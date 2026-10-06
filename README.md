# Bagisto SnappPay Payment

افزونه‌ی درگاه اسنپ‌پی برای Bagisto 2.4، بر پایه‌ی Payment architecture خود Bagisto و چرخه‌ی API نسخه 2.1 اسنپ‌پی.

## ویژگی‌ها

- سازگار با الگوی Package/Payment در Bagisto
- بررسی Eligibility قبل از نمایش روش پرداخت
- استفاده از عنوان و توضیح داینامیک Eligibility
- دریافت JWT برای هر فراخوانی API و عدم cache کردن access token
- ایجاد یک `transactionId` ده‌رقمی و یکتا برای هر تلاش پرداخت
- ذخیره‌ی snapshot سفارش قبل از redirect
- Callback با متد POST
- Verify فقط یک‌بار برای هر تراکنش، با recovery از طریق Status
- Settle پس از Verify
- جلوگیری از ساخت سفارش قبل از تأیید نهایی پرداخت
- ساخت Order و Invoice بعد از Settle موفق
- پیاده‌سازی متدهای Cancel و Update در client، بدون route عمومی ناامن
- عدم اجرای خودکار Revert، مطابق بخش Revert مستندات v2.1
- پشتیبانی از مبلغ ریال و تبدیل تومان به ریال
- نرمال‌سازی شماره موبایل ایرانی

## نصب

فایل‌های این package را داخل ریشه Bagisto و با همین ساختار قرار دهید:

```text
packages/Webkul/SnappPay
```

در `composer.json` اصلی پروژه، namespace را اضافه کنید:

```json
{
  "autoload": {
    "psr-4": {
      "Webkul\\SnappPay\\": "packages/Webkul/SnappPay/src"
    }
  }
}
```

در `bootstrap/providers.php` این provider را اضافه کنید:

```php
Webkul\SnappPay\Providers\SnappPayServiceProvider::class,
```

در `config/concord.php`، داخل بخش `modules`، module provider را اضافه کنید:

```php
Webkul\SnappPay\Providers\ModuleServiceProvider::class,
```

سپس:

```bash
composer dump-autoload
php artisan migrate
php artisan optimize:clear
```

## تنظیمات

از مسیر:

```text
Admin > Configuration > Sales > Payment Methods > SnappPay
```

مقادیر Stage را که خود اسنپ‌پی برای پذیرنده داده است وارد کنید.

فیلدهای مهم:

- `Base URL`: آدرس Stage یا Production که اسنپ‌پی در اختیار پذیرنده قرار می‌دهد.
- `Client ID`
- `Client Secret`
- `Username`
- `Password`
- `Callback URL`
- `Eligibility payment methods`: در صورت نیاز مانند `POSTPAID,INSTALLMENT`
- `Forced payment methods`: فقط در صورت فعال‌سازی از سمت اسنپ‌پی؛ برای پرداخت آخر ماه طبق مستندات ضروری است.
- `Timeout`: به‌صورت پیش‌فرض 30 ثانیه.
- `Verify SSL`: در Production روشن بماند.

## Callback

Route افزونه:

```text
POST /snapp-pay/callback
```

آدرس همین route باید دقیقاً همان URLای باشد که برای پذیرنده در اسنپ‌پی whitelist شده است.

در تنظیمات `Callback URL` مقدار کامل URL را وارد کنید. این URL نباید با GET استفاده شود.

برای اینکه callback بتواند بدون CSRF token از سمت اسنپ‌پی وارد شود، route با middleware مربوط به CSRF ثبت نمی‌شود. خود route عمومی هیچ عملیات تغییر وضعیت دیگری ندارد و تنها از روی `transactionId` تراکنش ذخیره‌شده را پیدا می‌کند.

## Eligibility

قبل از نمایش درگاه، سرویس:

```text
GET /api/online/offer/v1/eligible
```

فراخوانی می‌شود.

مبلغ به ریال ارسال می‌شود و در صورت `eligible = true` عنوان و توضیح دریافت‌شده از اسنپ‌پی در checkout استفاده می‌شود.

در این package مبلغ سقف/کف اسنپ‌پی به‌صورت دستی hard-code نشده است؛ تصمیم eligibility به خود API واگذار شده است.

## Payment Token

برای هر checkout یک transaction جدا ساخته می‌شود و سپس:

```text
POST /api/online/payment/v1/token
```

با `amount`, `discountAmount`, `externalSourceAmount`, `mobile`, `returnURL`, `transactionId`, `cartList` و در صورت تنظیم، `forcedPaymentMethodTypes` فراخوانی می‌شود.

`commissionType` برای آیتم‌ها به‌صورت پیش‌فرض `100` ارسال می‌شود.

## Verify و Settle

بعد از callback موفق:

1. Verify با timeout سی ثانیه انجام می‌شود.
2. اگر timeout/خطا رخ دهد، Status بررسی می‌شود.
3. در حالت `PENDING`، Verify دوباره انجام می‌شود.
4. بعد از Verify موفق، Settle انجام می‌شود.
5. اگر Settle timeout یا false شود، Status بررسی می‌شود.
6. در حالت `VERIFY` یک بار دیگر Settle انجام می‌شود.
7. فقط پس از تأیید `SETTLE`، Order و Invoice در Bagisto ساخته می‌شوند.

## Idempotency

یک callback تکراری نباید باعث Verify دوباره یا ساخت Order دوباره شود.

این package برای تراکنش lock شده و یک وضعیت مستقل برای `verifying`, `verified`, `settling`, `settled` نگه می‌دارد. در زمان ساخت Order نیز row تراکنش قفل می‌شود و `order_id` دوباره بررسی می‌شود.

## Cancel

متد client:

```php
app(\Webkul\SnappPay\Services\SnappPayClient::class)->cancel($paymentToken);
```

پیاده‌سازی شده است.

این عملیات عمداً route عمومی ندارد چون طبق مستندات اسنپ‌پی برگشت‌ناپذیر است و باید قبل از اجرا تأیید ادمین گرفته شود.

## Update

متد client:

```php
app(\Webkul\SnappPay\Services\SnappPayClient::class)->update(
    $paymentToken,
    $amountRial,
    $discountAmountRial,
    $cartList
);
```

برای فروشگاه چندآیتمی آماده است.

خود Bagisto در حالت عادی بعد از پرداخت API مدیریت سفارش داخلی اسنپ‌پی را به‌صورت خودکار صدا نمی‌زند؛ بنابراین workflow ادمین/ERP شما باید قبل از فراخوانی Update، داده‌ی جدید cart را بسازد و تأیید ادمین را بگیرد.

## Revert

متد client وجود دارد، اما به‌صورت خودکار از callback اجرا نمی‌شود. دلیل آن این است که در مستندات فعلی اسنپ‌پی، پیاده‌سازی Revert برای پذیرنده الزامی نیست و فقط در صورت اعلام پشتیبانی باید استفاده شود.

## واحد پول

افزونه به‌صورت پیش‌فرض:

- `IRR` را ریال در نظر می‌گیرد.
- `IRT`, `TMN`, `TOMAN` را تومان در نظر می‌گیرد و در 10 ضرب می‌کند.

در این پروژه فرض بر این است که مبلغ‌های Bagisto در تومان باشند، اگر currency code شما `IRT`/`TMN` باشد.

## نکات Production

- IP سرور باید در اسنپ‌پی whitelist باشد.
- Base URL را از مقدار Stage/Production رسمی خود اسنپ‌پی بگیرید و hard-code نکنید.
- Callback URL باید دقیقاً همان چیزی باشد که اسنپ‌پی برای پذیرنده whitelist کرده است.
- SSL verification را خاموش نکنید.
- Client Secret و Password را در Git commit نکنید.
- access token طبق مستندات اسنپ‌پی cache نشده است.
- قبل از Production، سناریوهای موفق، FAILED callback، timeout Verify، timeout Settle، callback تکراری و mismatch مبلغ را در Stage تست کنید.

## نکته سازگاری با Bagisto

این package بر مبنای الگوی رسمی فعلی Bagisto ساخته شده است: package مستقل، namespace با PSR-4 در composer، provider در `bootstrap/providers.php` و model mapping از طریق Concord.

در نسخه‌های قدیمی‌تر نمونه‌های community ممکن است autoload دستی در `bootstrap/app.php` دیده شود؛ این package آن الگوی قدیمی را استفاده نمی‌کند.

## وضعیت تست

این کد بر اساس مستندات API v2.1 طراحی شده، اما بدون credential و Base URL واقعی Stage اسنپ‌پی، تست end-to-end روی API انجام نشده است.

قبل از Production حتماً پاسخ واقعی APIهای Stage را با نمونه‌ی پذیرنده‌ی خودتان تطبیق دهید؛ مخصوصاً schema دقیق JSON پاسخ‌های Eligibility، Token، Verify، Settle و Status.

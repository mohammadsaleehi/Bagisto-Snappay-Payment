<?php

namespace Webkul\SnappPay\Payment;

use Illuminate\Support\Facades\Storage;
use Webkul\Payment\Payment\Payment;
use Webkul\Sales\Facades\Cart;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\SnappPay\Exceptions\SnappPayException;
use Webkul\SnappPay\Repositories\SnappPayTransactionRepository;
use Webkul\SnappPay\Services\SnappPayClient;

class SnappPay extends Payment
{
    /** code state. */
    protected $code = 'snapp_pay';

    /** eligibleData state. */
    protected ?array $eligibleData = null;

    /** Handle isAvailable operations. */
    public function isAvailable(): bool
    {
        if (! parent::isAvailable()) {
            return false;
        }

        $clientId = core()->getConfigData('sales.payment_methods.snapp_pay.client_id');
        $clientSecret = core()->getConfigData('sales.payment_methods.snapp_pay.client_secret');
        $username = core()->getConfigData('sales.payment_methods.snapp_pay.username');
        $password = core()->getConfigData('sales.payment_methods.snapp_pay.password');
        $baseUrl = core()->getConfigData('sales.payment_methods.snapp_pay.base_url');

        if (! $clientId || ! $clientSecret || ! $username || ! $password || ! $baseUrl) {
            return false;
        }

        try {
            $eligibility = $this->getEligibility();

            return (bool) $eligibility['eligible'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Handle getTitle operations. */
    public function getTitle(): string
    {
        return $this->eligibleData['title']
            ?? parent::getTitle();
    }

    /** Handle getDescription operations. */
    public function getDescription(): string
    {
        return $this->eligibleData['description']
            ?? parent::getDescription();
    }

    /** Handle getImage operations. */
    public function getImage(): string
    {
        $image = core()->getConfigData('sales.payment_methods.snapp_pay.image');

        if ($image) {
            return Storage::url($image);
        }

        return (string) parent::getImage();
    }

    /** Handle getRedirectUrl operations. */
    public function getRedirectUrl(): ?string
    {
        $cart = $this->getCart();

        if (! $cart) {
            throw new SnappPayException('SnappPay checkout could not find an active cart.');
        }

        $summary = $this->buildPaymentSummary($cart);
        $this->eligibleData = null;
        $eligibility = $this->getEligibility();

        if (! $eligibility['eligible']) {
            throw new SnappPayException('SnappPay is not eligible for this cart.');
        }

        $transactionRepository = app(SnappPayTransactionRepository::class);
        $transactionId = $transactionRepository->generateUniqueTransactionId();

        $orderPayload = (new OrderResource($cart))->jsonSerialize();

        $transaction = $transactionRepository->createTransaction([
            'transaction_id'          => $transactionId,
            'amount'                  => $summary['amount_rial'],
            'discount_amount'        => $summary['discount_amount_rial'],
            'external_source_amount' => $summary['external_source_amount_rial'],
            'mobile'                  => $summary['mobile'],
            'currency'                => $summary['currency'],
            'state'                   => null,
            'status'                  => 'pending',
            'cart_id'                 => $cart->id,
            'order_payload'           => $orderPayload,
        ]);

        try {
            $response = $this->client()->createPaymentToken([
                'amount' => $summary['amount_rial'],
                'discountAmount' => $summary['discount_amount_rial'],
                'externalSourceAmount' => $summary['external_source_amount_rial'],
                'mobile' => $summary['mobile'],
                'returnURL' => $this->getCallbackUrl(),
                'transactionId' => $transactionId,
                'cartList' => $summary['cart_list'],
                ...$this->forcedPaymentMethodTypesPayload(),
            ]);

            app(SnappPayTransactionRepository::class)->markStatus(
                $transaction,
                'pending',
                [
                    'payment_token' => $response['payment_token'],
                    'payment_page_url' => $response['payment_page_url'],
                ]
            );

            return $response['payment_page_url'];
        } catch (\Throwable $exception) {
            app(SnappPayTransactionRepository::class)->markStatus(
                $transaction,
                'failed',
                [
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'failed_at' => now(),
                ]
            );

            throw $exception;
        }
    }

    /** Handle getEligibility operations. */
    public function getEligibility(): array
    {
        if ($this->eligibleData !== null) {
            return $this->eligibleData;
        }

        $summary = $this->buildPaymentSummary($this->getCart());

        $types = $this->csvToArray(
            core()->getConfigData('sales.payment_methods.snapp_pay.payment_method_types')
        );

        $this->eligibleData = $this->client()->eligible(
            $summary['amount_rial'],
            $types ?: null
        );

        return $this->eligibleData;
    }

    /** Handle buildUpdatePayload operations. */
    public function buildUpdatePayload($cart): array
    {
        $summary = $this->buildPaymentSummary($cart);

        return [
            'amount_rial' => $summary['amount_rial'],
            'discount_amount_rial' => $summary['discount_amount_rial'],
            'cart_list' => $summary['cart_list'],
        ];
    }

    /** Handle buildPaymentSummary operations. */
    protected function buildPaymentSummary($cart): array
    {
        if (! $cart) {
            throw new SnappPayException('SnappPay checkout could not build a payment summary.');
        }

        $currency = strtoupper((string) core()->getCurrentCurrencyCode());
        $subTotal = $this->toRial((int) $cart->sub_total, $currency);
        $taxAmount = $this->toRial((int) $cart->tax_total, $currency);
        $shippingAmount = $this->toRial((int) $cart->selected_shipping_rate?->price, $currency);
        $discountAmount = $this->toRial((int) ($cart->discount_amount ?? 0), $currency);
        $grandTotal = $this->toRial((int) $cart->grand_total, $currency);

        $cartItems = [];

        foreach ($cart->items as $item) {
            $unitPrice = $this->toRial((int) $item->price, $currency);
            $category = $item->product?->categories?->first();

            $cartItems[] = [
                'id' => (string) $item->product_id,
                'name' => (string) $item->name,
                'count' => (int) $item->quantity,
                'amount' => $unitPrice,
                'category' => (string) ($category?->name ?? 'General'),
                'commissionType' => 100,
            ];
        }

        $mobile = $this->normalizeMobile(
            $cart->billing_address?->phone
            ?? $cart->customer?->phone
            ?? null
        );

        return [
            'currency' => $currency,
            'amount_rial' => $grandTotal + $discountAmount,
            'discount_amount_rial' => $discountAmount,
            'external_source_amount_rial' => 0,
            'mobile' => $mobile,
            'cart_list' => [
                [
                    'cartId' => (string) $cart->id,
                    'totalAmount' => $grandTotal + $discountAmount,
                    'shippingAmount' => $shippingAmount,
                    'isShipmentIncluded' => true,
                    'taxAmount' => $taxAmount,
                    'isTaxIncluded' => true,
                    'cartItems' => $cartItems,
                ],
            ],
        ];
    }

    /** Handle toRial operations. */
    protected function toRial(int $amount, string $currency): int
    {
        if ($currency === 'IRR') {
            return $amount;
        }

        if (in_array($currency, ['IRT', 'TMN', 'TOMAN'], true)) {
            return $amount * 10;
        }

        $rialCodes = $this->csvToArray(
            core()->getConfigData('sales.payment_methods.snapp_pay.rial_currency_codes')
        );

        $tomanCodes = $this->csvToArray(
            core()->getConfigData('sales.payment_methods.snapp_pay.toman_currency_codes')
        );

        if (in_array($currency, $rialCodes ?: ['IRR'], true)) {
            return $amount;
        }

        if (in_array($currency, $tomanCodes ?: ['IRT', 'TMN', 'TOMAN'], true)) {
            return $amount * 10;
        }

        throw new SnappPayException('Unsupported currency for SnappPay: '.$currency);
    }

    /** Handle normalizeMobile operations. */
    protected function normalizeMobile(?string $mobile): string
    {
        if (! $mobile) {
            throw new SnappPayException('SnappPay requires a customer mobile number.');
        }

        $mobile = strtr($mobile, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        $mobile = preg_replace('/[\s\-\(\)]/', '', $mobile);

        if (str_starts_with($mobile, '+98')) {
            $mobile = substr($mobile, 1);
        }

        if (str_starts_with($mobile, '0098')) {
            $mobile = substr($mobile, 2);
        }

        if (preg_match('/^09\d{9}$/', $mobile)) {
            return '+98'.substr($mobile, 1);
        }

        if (preg_match('/^9\d{9}$/', $mobile)) {
            return '+98'.$mobile;
        }

        if (preg_match('/^98\d{10}$/', $mobile)) {
            return '+'.$mobile;
        }

        throw new SnappPayException('The customer mobile number is not a valid Iranian mobile number.');
    }

    /** Handle forcedPaymentMethodTypesPayload operations. */
    protected function forcedPaymentMethodTypesPayload(): array
    {
        $types = $this->csvToArray(
            core()->getConfigData('sales.payment_methods.snapp_pay.forced_payment_method_types')
        );

        return $types
            ? ['forcedPaymentMethodTypes' => $types]
            : [];
    }

    /** Handle csvToArray operations. */
    protected function csvToArray($value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => strtoupper(trim($item)),
            explode(',', $value)
        )));
    }

    /** Handle getCallbackUrl operations. */
    protected function getCallbackUrl(): string
    {
        return rtrim(
            (string) core()->getConfigData('sales.payment_methods.snapp_pay.callback_url'),
            '/'
        ) ?: route('snapp_pay.callback');
    }

    /** Handle client operations. */
    protected function client(): SnappPayClient
    {
        return app(SnappPayClient::class);
    }
}

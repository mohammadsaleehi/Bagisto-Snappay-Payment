<?php

namespace Webkul\SnappPay\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Webkul\SnappPay\Exceptions\SnappPayException;

class SnappPayClient
{
    public const ELIGIBLE_PATH = '/api/online/offer/v1/eligible';

    public const TOKEN_PATH = '/api/online/payment/v1/token';

    public const VERIFY_PATH = '/api/online/payment/v1/verify';

    public const SETTLE_PATH = '/api/online/payment/v1/settle';

    public const REVERT_PATH = '/api/online/payment/v1/revert';

    public const STATUS_PATH = '/api/online/payment/v1/status';

    public const CANCEL_PATH = '/api/online/payment/v1/cancel';

    public const UPDATE_PATH = '/api/online/payment/v1/update';

    /** Handle eligible operations. */
    public function eligible(int $amountRial, ?array $paymentMethodTypes = null): array
    {
        $query = ['amount' => $amountRial];

        if ($paymentMethodTypes) {
            $query['paymentMethodTypes'] = $paymentMethodTypes;
        }

        $response = $this->request()->get(self::ELIGIBLE_PATH, $query);

        $payload = $this->decode($response);

        $eligible = $this->findBoolean($payload, ['eligible', 'isEligible']);

        if ($eligible === null) {
            throw new SnappPayException('SnappPay eligibility response does not contain an eligibility flag.');
        }

        return [
            'eligible'    => $eligible,
            'title'       => $this->findString($payload, ['title']),
            'description' => $this->findString($payload, ['description']),
            'raw'         => $payload,
        ];
    }

    /** Handle createPaymentToken operations. */
    public function createPaymentToken(array $payload): array
    {
        $response = $this->request()
            ->acceptJson()
            ->asJson()
            ->post(self::TOKEN_PATH, $payload);

        $data = $this->decode($response);

        $paymentToken = $this->findString($data, ['paymentToken']);

        $paymentPageUrl = $this->findString($data, ['paymentPageUrl']);

        if (! $paymentToken || ! $paymentPageUrl) {
            throw new SnappPayException('SnappPay token response does not contain paymentToken/paymentPageUrl.');
        }

        return [
            'payment_token'  => $paymentToken,
            'payment_page_url' => $paymentPageUrl,
            'raw'            => $data,
        ];
    }

    /** Handle verify operations. */
    public function verify(string $paymentToken): array
    {
        return $this->booleanOperation(self::VERIFY_PATH, ['paymentToken' => $paymentToken]);
    }

    /** Handle settle operations. */
    public function settle(string $paymentToken): array
    {
        return $this->booleanOperation(self::SETTLE_PATH, ['paymentToken' => $paymentToken]);
    }

    /** Handle cancel operations. */
    public function cancel(string $paymentToken): array
    {
        return $this->booleanOperation(self::CANCEL_PATH, ['paymentToken' => $paymentToken]);
    }

    /** Handle revert operations. */
    public function revert(string $paymentToken): array
    {
        return $this->booleanOperation(self::REVERT_PATH, ['paymentToken' => $paymentToken]);
    }

    /** Handle status operations. */
    public function status(string $paymentToken, int $amountRial): array
    {
        $response = $this->request()->get(
            self::STATUS_PATH,
            [
                'paymentToken' => $paymentToken,
                'amount'       => $amountRial,
            ]
        );

        $payload = $this->decode($response);

        $status = strtoupper((string) $this->findString($payload, ['status', 'paymentStatus']));

        if ($status === '') {
            throw new SnappPayException('SnappPay status response does not contain a status.');
        }

        return [
            'status' => $status,
            'raw'    => $payload,
        ];
    }

    /** Handle update operations. */
    public function update(
        string $paymentToken,
        int $amountRial,
        int $discountAmountRial,
        array $cartList
    ): array {
        return $this->booleanOperation(
            self::UPDATE_PATH,
            [
                'paymentToken'    => $paymentToken,
                'amount'          => $amountRial,
                'discountAmount'  => $discountAmountRial,
                'cartList'        => $cartList,
            ]
        );
    }

    /** Handle booleanOperation operations. */
    protected function booleanOperation(string $path, array $payload): array
    {
        $response = $this->request()
            ->acceptJson()
            ->asJson()
            ->post($path, $payload);

        $data = $this->decode($response);

        return [
            'success' => $this->resolveSuccess($data),
            'raw'     => $data,
        ];
    }

    /** Handle resolveSuccess operations. */
    protected function resolveSuccess(array $payload): bool
    {
        $boolean = $this->findBoolean($payload, ['success', 'successful', 'result']);

        if ($boolean !== null) {
            return $boolean;
        }

        if (array_key_exists('data', $payload) && is_bool($payload['data'])) {
            return $payload['data'];
        }

        return true;
    }

    /** Handle getAccessToken operations. */
    protected function getAccessToken(): string
    {
        $response = $this->requestWithoutBearer()
            ->withBasicAuth(
                (string) $this->config('client_id'),
                (string) $this->config('client_secret')
            )
            ->asForm()
            ->post(
                '/api/online/v1/oauth/token',
                [
                    'grant_type' => 'password',
                    'scope'      => 'online-merchant',
                    'username'   => (string) $this->config('username'),
                    'password'   => (string) $this->config('password'),
                ]
            );

        $payload = $this->decode($response);

        $token = $this->findString($payload, ['access_token', 'token']);

        if (! $token) {
            throw new SnappPayException('SnappPay authentication response does not contain an access token.');
        }

        return $token;
    }

    /** Handle request operations. */
    protected function request(): PendingRequest
    {
        $token = $this->getAccessToken();

        return $this->requestWithoutBearer()
            ->withToken($token);
    }

    /** Handle requestWithoutBearer operations. */
    protected function requestWithoutBearer(): PendingRequest
    {
        $baseUrl = rtrim((string) $this->config('base_url'), '/');

        if ($baseUrl === '') {
            throw new SnappPayException('SnappPay Base URL is not configured.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withOptions([
                'verify' => (bool) $this->config('verify_ssl', true),
            ])
            ->timeout((int) $this->config('timeout', 30))
            ->connectTimeout((int) $this->config('connect_timeout', 10));
    }

    /** Handle decode operations. */
    protected function decode($response): array
    {
        if (! $response->successful()) {
            $body = mb_substr((string) $response->body(), 0, 500);

            throw new SnappPayException(
                'SnappPay API request failed with HTTP '.$response->status().': '.$body
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new SnappPayException('SnappPay returned an invalid JSON payload.');
        }

        return $payload;
    }

    /** Handle config operations. */
    protected function config(string $key, $default = null)
    {
        return core()->getConfigData('sales.payment_methods.snapp_pay.'.$key) ?? $default;
    }

    /** Handle findBoolean operations. */
    protected function findBoolean(array $payload, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && is_bool($payload[$key])) {
                return $payload[$key];
            }

            if (isset($payload['data']) && is_array($payload['data'])
                && array_key_exists($key, $payload['data'])
                && is_bool($payload['data'][$key])
            ) {
                return $payload['data'][$key];
            }
        }

        return null;
    }

    /** Handle findString operations. */
    protected function findString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }

            if (isset($payload['data']) && is_array($payload['data'])
                && array_key_exists($key, $payload['data'])
                && is_scalar($payload['data'][$key])
            ) {
                return (string) $payload['data'][$key];
            }
        }

        return null;
    }
}

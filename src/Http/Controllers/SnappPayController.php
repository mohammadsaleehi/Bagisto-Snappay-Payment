<?php

namespace Webkul\SnappPay\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\SnappPay\Models\SnappPayTransaction;
use Webkul\SnappPay\Repositories\SnappPayTransactionRepository;
use Webkul\SnappPay\Services\SnappPayClient;
use Webkul\SnappPay\Services\SnappPayOrderFinalizer;

class SnappPayController extends Controller
{
    /** Handle callback operations. */
    public function callback(
        Request $request,
        SnappPayTransactionRepository $transactionRepository,
        SnappPayClient $client,
        SnappPayOrderFinalizer $orderFinalizer
    ): RedirectResponse {
        $payload = [
            'transactionId' => (string) $request->input('transactionId'),
            'state' => strtoupper((string) $request->input('state')),
            'amount' => (int) $request->input('amount'),
        ];

        if ($payload['transactionId'] === '') {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.invalid_callback'));
        }

        $transaction = $transactionRepository->findByTransactionId($payload['transactionId']);

        if (! $transaction) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.transaction_not_found'));
        }

        if (in_array($transaction->status, [
            SnappPayTransaction::STATUS_SETTLED,
        ], true)) {
            return $this->successRedirect($transaction->order_id);
        }

        if ($payload['amount'] !== (int) $transaction->amount) {
            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_FAILED,
                [
                    'callback_payload' => $payload,
                    'error_message' => trans('snapp-pay::app.errors.amount_mismatch'),
                    'failed_at' => now(),
                ]
            );

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.payment_failed'));
        }

        if ($payload['state'] !== 'OK') {
            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_FAILED,
                [
                    'state' => $payload['state'],
                    'callback_payload' => $payload,
                    'error_message' => trans('snapp-pay::app.errors.gateway_failed'),
                    'failed_at' => now(),
                ]
            );

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.payment_failed'));
        }

        $claimed = $transactionRepository->claimForVerification(
            $payload['transactionId'],
            $payload
        );

        if (! $claimed) {
            $transaction = $transactionRepository->findByTransactionId($payload['transactionId']);

            if ($transaction?->status === SnappPayTransaction::STATUS_SETTLED) {
                return $this->successRedirect($transaction->order_id);
            }

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.payment_processing'));
        }

        $transaction = $claimed;

        try {
            $verified = $this->verifyWithRecovery(
                $transaction,
                $client,
                $transactionRepository
            );

            if (! $verified) {
                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', trans('snapp-pay::app.errors.payment_failed'));
            }

            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_SETTLING,
                ['verified_at' => now()]
            );

            $settled = $this->settleWithRecovery(
                $transaction,
                $client
            );

            if (! $settled) {
                $transactionRepository->markStatus(
                    $transaction,
                    SnappPayTransaction::STATUS_FAILED,
                    [
                        'error_message' => trans('snapp-pay::app.errors.settle_failed'),
                        'failed_at' => now(),
                    ]
                );

                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', trans('snapp-pay::app.errors.payment_failed'));
            }

            $orderId = $orderFinalizer->finalize($transaction->transaction_id);

            return $this->successRedirect($orderId);
        } catch (\Throwable $exception) {
            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_FAILED,
                [
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'failed_at' => now(),
                ]
            );

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('snapp-pay::app.errors.payment_failed'));
        }
    }

    /** Handle verifyWithRecovery operations. */
    protected function verifyWithRecovery(
        SnappPayTransaction $transaction,
        SnappPayClient $client,
        SnappPayTransactionRepository $transactionRepository
    ): bool {
        try {
            $response = $client->verify((string) $transaction->payment_token);

            if ($response['success']) {
                $transactionRepository->markStatus(
                    $transaction,
                    SnappPayTransaction::STATUS_VERIFIED,
                    ['verified_at' => now()]
                );

                return true;
            }
        } catch (\Throwable) {
        }

        $status = $client->status(
            (string) $transaction->payment_token,
            (int) $transaction->amount
        );

        if ($status['status'] === 'VERIFY') {
            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_VERIFIED,
                ['verified_at' => now()]
            );

            return true;
        }

        if ($status['status'] !== 'PENDING') {
            return false;
        }

        try {
            $retry = $client->verify((string) $transaction->payment_token);

            if ($retry['success']) {
                $transactionRepository->markStatus(
                    $transaction,
                    SnappPayTransaction::STATUS_VERIFIED,
                    ['verified_at' => now()]
                );

                return true;
            }
        } catch (\Throwable) {
        }

        $status = $client->status(
            (string) $transaction->payment_token,
            (int) $transaction->amount
        );

        if ($status['status'] === 'VERIFY') {
            $transactionRepository->markStatus(
                $transaction,
                SnappPayTransaction::STATUS_VERIFIED,
                ['verified_at' => now()]
            );

            return true;
        }

        return false;
    }

    /** Handle settleWithRecovery operations. */
    protected function settleWithRecovery(
        SnappPayTransaction $transaction,
        SnappPayClient $client
    ): bool {
        try {
            $response = $client->settle((string) $transaction->payment_token);

            if ($response['success']) {
                return true;
            }
        } catch (\Throwable) {
        }

        $status = $client->status(
            (string) $transaction->payment_token,
            (int) $transaction->amount
        );

        if ($status['status'] === 'SETTLE') {
            return true;
        }

        if ($status['status'] !== 'VERIFY') {
            return false;
        }

        try {
            $retry = $client->settle((string) $transaction->payment_token);

            if ($retry['success']) {
                return true;
            }
        } catch (\Throwable) {
        }

        $status = $client->status(
            (string) $transaction->payment_token,
            (int) $transaction->amount
        );

        return $status['status'] === 'SETTLE';
    }

    /** Handle successRedirect operations. */
    protected function successRedirect(?int $orderId): RedirectResponse
    {
        if ($orderId) {
            session()->flash('order_id', $orderId);
        }

        return redirect()->route('shop.checkout.onepage.success');
    }
}

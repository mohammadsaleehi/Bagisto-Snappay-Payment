<?php

namespace Webkul\SnappPay\Repositories;

use Illuminate\Support\Facades\DB;
use Webkul\Core\Eloquent\Repository;
use Webkul\SnappPay\Contracts\SnappPayTransaction as SnappPayTransactionContract;
use Webkul\SnappPay\Models\SnappPayTransaction;

class SnappPayTransactionRepository extends Repository
{
    /** Handle model operations. */
    public function model(): string
    {
        return SnappPayTransactionContract::class;
    }

    /** Handle createTransaction operations. */
    public function createTransaction(array $data): SnappPayTransactionContract
    {
        return $this->create($data);
    }

    /** Handle findByTransactionId operations. */
    public function findByTransactionId(string $transactionId): ?SnappPayTransactionContract
    {
        return $this->findWhere(['transaction_id' => $transactionId])->first();
    }

    /** Handle lockByTransactionId operations. */
    public function lockByTransactionId(string $transactionId): ?SnappPayTransactionContract
    {
        return $this->model->newQuery()
            ->where('transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();
    }

    /** Handle claimForVerification operations. */
    public function claimForVerification(string $transactionId, array $callbackPayload): ?SnappPayTransactionContract
    {
        return DB::transaction(function () use ($transactionId, $callbackPayload) {
            $transaction = $this->lockByTransactionId($transactionId);

            if (! $transaction || $transaction->status !== SnappPayTransaction::STATUS_PENDING) {
                return null;
            }

            return $this->update(
                [
                    'status'           => SnappPayTransaction::STATUS_VERIFYING,
                    'state'            => strtoupper((string) ($callbackPayload['state'] ?? '')),
                    'callback_payload' => $callbackPayload,
                ],
                $transaction->id
            );
        });
    }

    /** Handle markStatus operations. */
    public function markStatus(SnappPayTransactionContract $transaction, string $status, array $data = []): SnappPayTransactionContract
    {
        return $this->update(array_merge(['status' => $status], $data), $transaction->id);
    }

    /** Handle attachOrder operations. */
    public function attachOrder(SnappPayTransactionContract $transaction, int $orderId): SnappPayTransactionContract
    {
        return $this->update(
            [
                'order_id'   => $orderId,
                'status'     => SnappPayTransaction::STATUS_SETTLED,
                'settled_at' => now(),
            ],
            $transaction->id
        );
    }

    /** Handle findByOrderId operations. */
    public function findByOrderId(int $orderId): ?SnappPayTransactionContract
    {
        return $this->findWhere(['order_id' => $orderId])->first();
    }

    /** Handle generateUniqueTransactionId operations. */
    public function generateUniqueTransactionId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $transactionId = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

            if (! $this->findByTransactionId($transactionId)) {
                return $transactionId;
            }
        }

        throw new \RuntimeException('Unable to generate a unique SnappPay transaction ID.');
    }
}

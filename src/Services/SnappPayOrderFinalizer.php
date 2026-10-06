<?php

namespace Webkul\SnappPay\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderItemRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\SnappPay\Models\SnappPayTransaction;
use Webkul\SnappPay\Repositories\SnappPayTransactionRepository;

class SnappPayOrderFinalizer
{
    /** Handle __construct operations. */
    public function __construct(
        /** transactionRepository state. */
        protected SnappPayTransactionRepository $transactionRepository,
        /** orderRepository state. */
        protected OrderRepository $orderRepository,
        /** orderItemRepository state. */
        protected OrderItemRepository $orderItemRepository,
        /** invoiceRepository state. */
        protected InvoiceRepository $invoiceRepository,
    ) {
    }

    /** Handle finalize operations. */
    public function finalize(string $transactionId): int
    {
        return DB::transaction(function () use ($transactionId) {
            $transaction = $this->transactionRepository->lockByTransactionId($transactionId);

            if (! $transaction) {
                throw new \RuntimeException('SnappPay transaction not found.');
            }

            if ($transaction->order_id) {
                return (int) $transaction->order_id;
            }

            if ($transaction->status !== SnappPayTransaction::STATUS_SETTLING) {
                throw new \RuntimeException('SnappPay transaction is not ready for order finalization.');
            }

            $order = $this->orderRepository->create($transaction->order_payload);

            $orderItems = $this->orderItemRepository->findWhere([
                'order_id' => $order->id,
            ]);

            $invoiceItems = [];

            foreach ($orderItems as $orderItem) {
                $invoiceItems[$orderItem->id] = $orderItem->qty_ordered;
            }

            $this->invoiceRepository->create([
                'order_id' => $order->id,
                'invoice' => [
                    'items' => $invoiceItems,
                ],
            ]);

            $this->transactionRepository->attachOrder(
                $transaction,
                (int) $order->id
            );

            return (int) $order->id;
        });
    }
}

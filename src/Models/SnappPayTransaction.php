<?php

namespace Webkul\SnappPay\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\SnappPay\Contracts\SnappPayTransaction as SnappPayTransactionContract;

class SnappPayTransaction extends Model implements SnappPayTransactionContract
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_SETTLING = 'settling';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** table state. */
    protected $table = 'snapp_pay_transactions';

    /** guarded state. */
    protected $guarded = [];

    /** casts state. */
    protected $casts = [
        'amount'                 => 'integer',
        'discount_amount'        => 'integer',
        'external_source_amount'=> 'integer',
        'order_id'               => 'integer',
        'cart_id'                => 'integer',
        'order_payload'          => 'array',
        'callback_payload'       => 'array',
        'verified_at'            => 'datetime',
        'settled_at'             => 'datetime',
        'failed_at'              => 'datetime',
    ];
}

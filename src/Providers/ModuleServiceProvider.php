<?php

namespace Webkul\SnappPay\Providers;

use Konekt\Concord\BaseModuleServiceProvider;
use Webkul\SnappPay\Contracts\SnappPayTransaction as SnappPayTransactionContract;
use Webkul\SnappPay\Models\SnappPayTransaction;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /** models state. */
    protected $models = [
        SnappPayTransactionContract::class => SnappPayTransaction::class,
    ];
}

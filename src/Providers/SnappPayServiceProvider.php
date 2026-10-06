<?php

namespace Webkul\SnappPay\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Webkul\SnappPay\Repositories\SnappPayTransactionRepository;
use Webkul\SnappPay\Services\SnappPayClient;

class SnappPayServiceProvider extends ServiceProvider
{
    /** Handle register operations. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/payment-methods.php', 'payment_methods');

        $this->mergeConfigFrom(__DIR__.'/../Config/system.php', 'core');

        $this->app->singleton(SnappPayClient::class, function () {
            return new SnappPayClient;
        });

        $this->app->bind(SnappPayTransactionRepository::class);
    }

    /** Handle boot operations. */
    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'snapp-pay');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'snapp-pay');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}

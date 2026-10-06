<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Handle up operations. */
    public function up(): void
    {
        Schema::create('snapp_pay_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 10)->unique();
            $table->string('payment_token')->nullable()->index();
            $table->string('payment_page_url')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('external_source_amount')->default(0);
            $table->string('currency', 12)->default('IRR');
            $table->string('mobile', 32)->nullable();
            $table->string('state', 20)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedBigInteger('cart_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->json('order_payload');
            $table->json('callback_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /** Handle down operations. */
    public function down(): void
    {
        Schema::dropIfExists('snapp_pay_transactions');
    }
};

<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payments.tables.orders', 'payment_orders'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained($this->userTable())->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained(config('payments.tables.products', 'payment_products'))->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained(config('payments.tables.transactions', 'payment_transactions'))->nullOnDelete();
            $table->enum('status', ['pending', 'paid', 'failed', 'authorized', 'cancelled'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payments.tables.orders', 'payment_orders'));
    }

    private function userTable(): string
    {
        $model = config('payments.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

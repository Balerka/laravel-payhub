<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payhub.tables.orders', 'payhub_orders'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained($this->userTable())->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained(config('payhub.tables.transactions', 'payhub_transactions'))->nullOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->string('description')->nullable();
            $table->json('receipt')->nullable();
            $table->enum('status', ['pending', 'paid', 'failed', 'authorized', 'cancelled'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payhub.tables.orders', 'payhub_orders'));
    }

    private function userTable(): string
    {
        $model = config('payhub.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

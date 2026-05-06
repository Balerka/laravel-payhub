<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payhub.tables.subscriptions', 'payhub_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->string('subscription_id')->unique();
            $table->foreignId('user_id')->constrained($this->userTable())->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->string('description')->nullable();
            $table->string('interval')->nullable();
            $table->unsignedInteger('period')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('next_transaction_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payhub.tables.subscriptions', 'payhub_subscriptions'));
    }

    private function userTable(): string
    {
        $model = config('payhub.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

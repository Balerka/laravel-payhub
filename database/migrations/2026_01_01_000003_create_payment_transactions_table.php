<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payments.tables.transactions', 'payment_transactions'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained($this->userTable())->cascadeOnDelete();
            $table->string('transaction_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->decimal('fee', 10, 2)->default(0);
            $table->boolean('status')->default(false);
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payments.tables.transactions', 'payment_transactions'));
    }

    private function userTable(): string
    {
        $model = config('payments.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

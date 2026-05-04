<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payments.tables.cards', 'payment_cards'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained($this->userTable())->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('last4', 4);
            $table->string('bank')->nullable();
            $table->string('brand');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payments.tables.cards', 'payment_cards'));
    }

    private function userTable(): string
    {
        $model = config('payments.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

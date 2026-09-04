<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(config('payhub.tables.cards', 'payhub_cards'))) {
            return;
        }

        Schema::create(config('payhub.tables.cards', 'payhub_cards'), function (Blueprint $table): void {
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
        if (! config('payhub.drop_tables_on_rollback', false)) {
            return;
        }

        Schema::dropIfExists(config('payhub.tables.cards', 'payhub_cards'));
    }

    private function userTable(): string
    {
        $model = config('payhub.user_model', 'App\\Models\\User');

        return is_a($model, Model::class, true)
            ? (new $model)->getTable()
            : 'users';
    }
};

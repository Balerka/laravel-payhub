<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payments.tables.products', 'payment_products'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->string('item');
            $table->integer('quantity');
            $table->enum('period', ['Hour', 'Day', 'Week', 'Month', 'Year'])->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('recurrent_id')->nullable()->constrained(config('payments.tables.products', 'payment_products'))->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payments.tables.products', 'payment_products'));
    }
};

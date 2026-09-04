<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ordersTable = config('payhub.tables.orders', 'payhub_orders');

        if (! Schema::hasTable($ordersTable)) {
            return;
        }

        if (! Schema::hasColumn($ordersTable, 'idempotency_key')) {
            Schema::table($ordersTable, function (Blueprint $table): void {
                $table->string('idempotency_key', 64)->nullable();
            });
        }

        if (! Schema::hasIndex($ordersTable, ['idempotency_key'], 'unique')) {
            Schema::table($ordersTable, function (Blueprint $table): void {
                $table->unique('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        // Existing applications may already own this checkout identifier.
    }
};

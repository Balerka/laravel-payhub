<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $transactionsTable = config('payhub.tables.transactions', 'payhub_transactions');

        if (! Schema::hasTable($transactionsTable) || Schema::hasColumn($transactionsTable, 'vat')) {
            return;
        }

        Schema::table($transactionsTable, function (Blueprint $table): void {
            $table->decimal('vat', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        // Existing applications may already own this payment tax metadata.
    }
};

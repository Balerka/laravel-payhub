<?php

use Balerka\LaravelPayhub\Support\GatewayResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyGateway = GatewayResolver::legacy();

        if ($legacyGateway === '') {
            return;
        }

        foreach (['transactions', 'subscriptions'] as $tableKey) {
            $table = config("payhub.tables.{$tableKey}", "payhub_{$tableKey}");

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'gateway')) {
                continue;
            }

            DB::table($table)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('gateway')
                    ->orWhere('gateway', ''))
                ->update(['gateway' => $legacyGateway]);
        }
    }

    public function down(): void
    {
        // Legacy rows cannot be distinguished safely after the forward migration.
    }
};

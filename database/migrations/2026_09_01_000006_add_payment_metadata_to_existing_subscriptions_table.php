<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $subscriptionsTable = config('payhub.tables.subscriptions', 'payhub_subscriptions');

        if (! Schema::hasTable($subscriptionsTable)) {
            return;
        }

        Schema::table($subscriptionsTable, function (Blueprint $table) use ($subscriptionsTable): void {
            if (! Schema::hasColumn($subscriptionsTable, 'amount')) {
                $table->decimal('amount', 10, 2)->nullable();
            }

            if (! Schema::hasColumn($subscriptionsTable, 'currency')) {
                $table->string('currency', 3)->default('RUB');
            }

            if (! Schema::hasColumn($subscriptionsTable, 'description')) {
                $table->string('description')->nullable();
            }

            if (! Schema::hasColumn($subscriptionsTable, 'interval')) {
                $table->string('interval')->nullable();
            }

            if (! Schema::hasColumn($subscriptionsTable, 'period')) {
                $table->unsignedInteger('period')->nullable();
            }

            if (! Schema::hasColumn($subscriptionsTable, 'metadata')) {
                $table->json('metadata')->nullable();
            }

            if (! Schema::hasColumn($subscriptionsTable, 'gateway')) {
                $table->string('gateway')->nullable();
            }
        });

        if (! Schema::hasIndex($subscriptionsTable, ['subscription_id'], 'unique')) {
            Schema::table($subscriptionsTable, function (Blueprint $table): void {
                $table->unique('subscription_id');
            });
        }

        if (! Schema::hasIndex($subscriptionsTable, ['user_id', 'status'])) {
            Schema::table($subscriptionsTable, function (Blueprint $table): void {
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Existing installations may have owned these columns before PayHub.
    }
};

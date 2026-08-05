<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog v5 moved model attribute diffs out of `properties` into a dedicated
 * `attribute_changes` column and dropped batching (`batch_uuid`). Our logs are manual
 * activity('video') calls, so there is no attribute data to backfill and `batch_uuid` is null on
 * every row — schema-only change. See Spatie\Activitylog\Support\ActivityLogger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name');

        if (! Schema::connection($connection)->hasColumn($table, 'attribute_changes')) {
            Schema::connection($connection)->table($table, function (Blueprint $table) {
                $table->json('attribute_changes')->nullable()->after('causer_id');
            });
        }

        if (Schema::connection($connection)->hasColumn($table, 'batch_uuid')) {
            Schema::connection($connection)->table($table, function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name');

        if (Schema::connection($connection)->hasColumn($table, 'attribute_changes')) {
            Schema::connection($connection)->table($table, function (Blueprint $table) {
                $table->dropColumn('attribute_changes');
            });
        }

        if (! Schema::connection($connection)->hasColumn($table, 'batch_uuid')) {
            Schema::connection($connection)->table($table, function (Blueprint $table) {
                $table->uuid('batch_uuid')->nullable()->after('properties');
            });
        }
    }
};

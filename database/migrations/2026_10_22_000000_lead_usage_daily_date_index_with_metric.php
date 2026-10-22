<?php

use App\Services\AnalyticsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The instance-wide reads of `usage_daily` all name a metric and a date range — encoding seconds,
 * upload volume, the delivery set ({@see AnalyticsService}). On `(date, metric)` the range comes
 * first, so the index walks every row of every metric in the range to find the few that match:
 * summing a month of encoding seconds read the month's millions of delivery rows to do it, and
 * the dashboard does that three times per load. Led by the metric, the same index jumps straight
 * to each metric's own slice of dates, and a metric with few rows costs few rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('usage_daily', 'usage_daily_metric_date_index')) {
            return;
        }

        Schema::table('usage_daily', function (Blueprint $table) {
            $table->index(['metric', 'date'], 'usage_daily_metric_date_index');
            $table->dropIndex('usage_daily_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('usage_daily', 'usage_daily_metric_date_index')) {
            return;
        }

        Schema::table('usage_daily', function (Blueprint $table) {
            $table->index(['date', 'metric'], 'usage_daily_date_index');
            $table->dropIndex('usage_daily_metric_date_index');
        });
    }
};

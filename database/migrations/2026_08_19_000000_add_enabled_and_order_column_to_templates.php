<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two columns the template list needed to stop being a write-once pile.
     *
     * `enabled`: a template referenced by a video can never be deleted
     * ({@see TemplateController::destroy}), so retiring one meant leaving it in the picker forever.
     * Disabling it retires it instead — existing videos keep encoding, new uploads may no longer
     * name it ({@see MyCustomUppyController::validateMetadata}). Defaults on, so everything that
     * exists today stays selectable.
     *
     * `order_column`: templates were listed newest-first, which is not the order anyone picks an
     * encoding profile in — the one used for most uploads is usually the oldest. The panel now
     * sorts them by hand (spatie/eloquent-sortable, {@see Template::buildSortQuery()}) and this is
     * the column it writes, scoped per project since that is the only scope a template is listed in.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('templates', 'enabled')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->boolean('enabled')->default(true)->after('query');
            });
        }

        if (Schema::hasColumn('templates', 'order_column')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            $table->unsignedInteger('order_column')->default(0)->after('enabled');
        });

        // Freeze the order the panel showed until now (newest first) instead of collapsing every
        // template to 0, which would leave the list ordered by whatever the engine felt like.
        $position = [];

        DB::table('templates')
            ->select('id', 'project_id')
            ->orderBy('project_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor()
            ->each(function ($template) use (&$position) {
                $next = ($position[$template->project_id] ?? 0) + 1;
                $position[$template->project_id] = $next;

                DB::table('templates')->where('id', $template->id)->update(['order_column' => $next]);
            });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['enabled', 'order_column'],
            fn (string $column) => Schema::hasColumn('templates', $column),
        ));

        if (! $columns) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};

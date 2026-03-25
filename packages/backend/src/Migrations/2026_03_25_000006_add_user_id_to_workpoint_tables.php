<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $recordsTable = config('workpoint.table', 'workpoint_records');
        $totalsTable = config('workpoint.period_totals_table', 'workpoint_period_totals');

        if (Schema::hasTable($recordsTable) && !Schema::hasColumn($recordsTable, 'user_id')) {
            Schema::table($recordsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('zone_id');
                $table->index(['zone_id', 'user_id', 'created_at'], 'wp_rec_zone_user_created_idx');
            });
        }

        if (Schema::hasTable($totalsTable) && !Schema::hasColumn($totalsTable, 'user_id')) {
            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('zone_id');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->dropUnique('workpoint_period_totals_unique');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unique(
                    ['zone_id', 'user_id', 'period_type', 'period_key'],
                    'workpoint_period_totals_unique'
                );
                $table->index(['zone_id', 'user_id', 'period_type', 'period_key'], 'wp_pt_zone_user_period_idx');
            });
        }
    }

    public function down(): void
    {
        $recordsTable = config('workpoint.table', 'workpoint_records');
        $totalsTable = config('workpoint.period_totals_table', 'workpoint_period_totals');

        if (Schema::hasTable($recordsTable) && Schema::hasColumn($recordsTable, 'user_id')) {
            Schema::table($recordsTable, function (Blueprint $table) {
                $table->dropIndex('wp_rec_zone_user_created_idx');
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasTable($totalsTable) && Schema::hasColumn($totalsTable, 'user_id')) {
            Schema::table($totalsTable, function (Blueprint $table) {
                $table->dropIndex('wp_pt_zone_user_period_idx');
                $table->dropUnique('workpoint_period_totals_unique');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unique(
                    ['zone_id', 'subject_type', 'subject_id', 'period_type', 'period_key'],
                    'workpoint_period_totals_unique'
                );
                $table->dropColumn('user_id');
            });
        }
    }

};


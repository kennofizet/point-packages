<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addSoftDeletes(config('workpoint.table', 'workpoint_records'));
        $this->addSoftDeletes(config('workpoint.period_totals_table', 'workpoint_period_totals'));
        $this->addSoftDeletes(config('workpoint.zone_cases_table', 'workpoint_zone_cases'));
    }

    public function down(): void
    {
        $this->dropSoftDeletes(config('workpoint.table', 'workpoint_records'));
        $this->dropSoftDeletes(config('workpoint.period_totals_table', 'workpoint_period_totals'));
        $this->dropSoftDeletes(config('workpoint.zone_cases_table', 'workpoint_zone_cases'));
    }

    private function addSoftDeletes(string $tableName): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    private function dropSoftDeletes(string $tableName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

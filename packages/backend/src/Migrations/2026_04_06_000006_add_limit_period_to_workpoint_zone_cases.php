<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = config('workpoint.zone_cases_table', 'workpoint_zone_cases');

        Schema::table($tableName, function (Blueprint $table) {
            $table->string('limit_period', 16)->nullable()->after('cap');
            $table->unsignedInteger('limit_period_time')->nullable()->after('limit_period');
        });
    }

    public function down(): void
    {
        $tableName = config('workpoint.zone_cases_table', 'workpoint_zone_cases');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn(['limit_period', 'limit_period_time']);
        });
    }
};

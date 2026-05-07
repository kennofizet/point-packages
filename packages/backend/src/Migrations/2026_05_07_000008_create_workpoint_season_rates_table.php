<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('workpoint.season_rates_table', 'workpoint_season_rates');
        if (Schema::hasTable($table)) {
            if (!Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->softDeletes();
                });
            }
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('zone_id')->nullable();
            $blueprint->unsignedBigInteger('season_id');
            $blueprint->decimal('rate_convert', 16, 6)->default(1);
            $blueprint->timestamps();
            $blueprint->softDeletes();

            $blueprint->unique(['zone_id', 'season_id'], 'wp_season_rate_zone_season_unique');
            $blueprint->index(['season_id'], 'wp_season_rate_season_idx');
        });
    }

    public function down(): void
    {
        $table = config('workpoint.season_rates_table', 'workpoint_season_rates');
        Schema::dropIfExists($table);
    }
};

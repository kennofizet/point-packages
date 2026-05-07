<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kennofizet\PackagesCore\Models\Season;

return new class extends Migration {
    public function up(): void
    {
        $recordsTable = config('workpoint.table', 'workpoint_records');
        $totalsTable = config('workpoint.period_totals_table', 'workpoint_period_totals');

        if (Schema::hasTable($recordsTable) && !Schema::hasColumn($recordsTable, 'season_id')) {
            Schema::table($recordsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('season_id')->nullable()->after('zone_id');
                $table->index(['zone_id', 'season_id', 'user_id', 'created_at'], 'wp_rec_zone_season_user_created_idx');
                $table->index(['zone_id', 'season_id', 'user_id', 'action_key', 'created_at'], 'wp_rec_z_s_u_a_c_idx');
            });
        }

        if (Schema::hasTable($totalsTable) && !Schema::hasColumn($totalsTable, 'season_id')) {
            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('season_id')->nullable()->after('zone_id');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->dropUnique('workpoint_period_totals_unique');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unique(
                    ['zone_id', 'season_id', 'user_id', 'period_type', 'period_key'],
                    'workpoint_period_totals_unique'
                );
                $table->index(['zone_id', 'season_id', 'user_id', 'period_type', 'period_key'], 'wp_pt_zone_season_user_period_idx');
            });
        }

        $this->backfillLegacySeason($recordsTable, $totalsTable);
    }

    public function down(): void
    {
        $recordsTable = config('workpoint.table', 'workpoint_records');
        $totalsTable = config('workpoint.period_totals_table', 'workpoint_period_totals');

        if (Schema::hasTable($recordsTable) && Schema::hasColumn($recordsTable, 'season_id')) {
            Schema::table($recordsTable, function (Blueprint $table) {
                $table->dropIndex('wp_rec_zone_season_user_created_idx');
                $table->dropIndex('wp_rec_z_s_u_a_c_idx');
                $table->dropColumn('season_id');
            });
        }

        if (Schema::hasTable($totalsTable) && Schema::hasColumn($totalsTable, 'season_id')) {
            Schema::table($totalsTable, function (Blueprint $table) {
                $table->dropIndex('wp_pt_zone_season_user_period_idx');
                $table->dropUnique('workpoint_period_totals_unique');
            });

            Schema::table($totalsTable, function (Blueprint $table) {
                $table->unique(
                    ['zone_id', 'user_id', 'period_type', 'period_key'],
                    'workpoint_period_totals_unique'
                );
                $table->dropColumn('season_id');
            });
        }
    }

    private function backfillLegacySeason(string $recordsTable, string $totalsTable): void
    {
        if (!class_exists(Season::class)) {
            return;
        }

        $seasonTable = (new Season())->getTable();
        if (!Schema::hasTable($seasonTable)) {
            return;
        }

        $zoneIds = [];
        if (Schema::hasTable($recordsTable) && Schema::hasColumn($recordsTable, 'zone_id')) {
            $zoneIds = array_merge($zoneIds, DB::table($recordsTable)->distinct()->pluck('zone_id')->all());
        }
        if (Schema::hasTable($totalsTable) && Schema::hasColumn($totalsTable, 'zone_id')) {
            $zoneIds = array_merge($zoneIds, DB::table($totalsTable)->distinct()->pluck('zone_id')->all());
        }

        $zoneIds = array_values(array_unique($zoneIds, SORT_REGULAR));

        foreach ($zoneIds as $zoneId) {
            $legacySeasonId = $this->resolveOrCreateLegacySeasonId($seasonTable, $zoneId);
            if ($legacySeasonId === null) {
                continue;
            }

            if (Schema::hasTable($recordsTable) && Schema::hasColumn($recordsTable, 'season_id')) {
                $recordsQuery = DB::table($recordsTable)->whereNull('season_id');
                if ($zoneId === null) {
                    $recordsQuery->whereNull('zone_id');
                } else {
                    $recordsQuery->where('zone_id', $zoneId);
                }
                $recordsQuery->update(['season_id' => $legacySeasonId]);
            }

            if (Schema::hasTable($totalsTable) && Schema::hasColumn($totalsTable, 'season_id')) {
                $totalsQuery = DB::table($totalsTable)->whereNull('season_id');
                if ($zoneId === null) {
                    $totalsQuery->whereNull('zone_id');
                } else {
                    $totalsQuery->where('zone_id', $zoneId);
                }
                $totalsQuery->update(['season_id' => $legacySeasonId]);
            }
        }
    }

    private function resolveOrCreateLegacySeasonId(string $seasonTable, $zoneId): ?int
    {
        $legacyQuery = DB::table($seasonTable)->where('name', 'Legacy');
        if ($zoneId === null) {
            $legacyQuery->whereNull('zone_id');
        } else {
            $legacyQuery->where('zone_id', $zoneId);
        }

        $existing = $legacyQuery->orderByDesc('id')->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $activeQuery = DB::table($seasonTable)->where('is_active', 1);
        if ($zoneId === null) {
            $activeQuery->whereNull('zone_id');
        } else {
            $activeQuery->where('zone_id', $zoneId);
        }
        $hasActive = $activeQuery->exists();

        $id = DB::table($seasonTable)->insertGetId([
            'zone_id' => $zoneId,
            'name' => 'Legacy',
            'is_active' => $hasActive ? 0 : 1,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id ? (int) $id : null;
    }
};

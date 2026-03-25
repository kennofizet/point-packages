<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = config('workpoint.table', 'workpoint_records');

        Schema::table($tableName, function (Blueprint $table) {
            // History query: zone + relation type/id + time range + keyset by id.
            $table->index(
                ['zone_id', 'subject_type', 'subject_id', 'created_at', 'id'],
                'wp_rec_z_su_st_ca_id'
            );

            // Member list (manager) and rank query: latest/group by relation in period.
            $table->index(
                ['zone_id', 'subject_type', 'created_at', 'subject_id'],
                'wp_rec_z_st_ca_su'
            );

            // Today-by-rule aggregation by action_key.
            $table->index(
                ['zone_id', 'subject_type', 'subject_id', 'action_key', 'created_at'],
                'wp_rec_z_st_su_ac_ca'
            );
        });
    }

    public function down(): void
    {
        $tableName = config('workpoint.table', 'workpoint_records');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('wp_rec_z_su_st_ca_id');
            $table->dropIndex('wp_rec_z_st_ca_su');
            $table->dropIndex('wp_rec_z_st_su_ac_ca');
        });
    }
};


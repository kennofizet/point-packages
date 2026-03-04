<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = config('workpoint.period_totals_table') ?? 'workpoint_period_totals';

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('period_type', 10);
            $table->string('period_key', 20);
            $table->integer('total_points')->default(0);
            $table->timestamps();

            $table->unique(['zone_id', 'subject_type', 'subject_id', 'period_type', 'period_key'], 'workpoint_period_totals_unique');
            $table->index(['zone_id', 'period_type', 'period_key']);
            $table->index(['period_type', 'period_key', 'total_points']);
        });
    }

    public function down(): void
    {
        $tableName = config('workpoint.period_totals_table') ?? 'workpoint_period_totals';
        Schema::dropIfExists($tableName);
    }
};

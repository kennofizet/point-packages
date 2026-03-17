<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = config('workpoint.zone_cases_table', 'workpoint_zone_cases');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id');
            $table->string('case_key', 64);
            $table->integer('points')->default(0);
            $table->string('check', 64)->default('none');
            $table->string('period', 16)->nullable();
            $table->unsignedInteger('cap')->nullable();
            $table->json('descriptions')->nullable();
            $table->timestamps();

            $table->unique(['zone_id', 'case_key'], 'wp_zone_cases_zone_key_unique');
            $table->index('zone_id');
        });
    }

    public function down(): void
    {
        $tableName = config('workpoint.zone_cases_table', 'workpoint_zone_cases');
        Schema::dropIfExists($tableName);
    }
};

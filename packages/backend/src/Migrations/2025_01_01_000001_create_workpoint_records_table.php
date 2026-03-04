<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = config('workpoint.table', 'workpoint_records');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('action_key');
            $table->integer('points_delta');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('zone_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['target_type', 'target_id']);
            $table->index('action_key');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $tableName = config('workpoint.table', 'workpoint_records');
        Schema::dropIfExists($tableName);
    }
};

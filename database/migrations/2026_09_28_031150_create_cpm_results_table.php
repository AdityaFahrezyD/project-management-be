<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cpm_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cpm_analysis_id')->constrained('cpm_analyses')->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->decimal('early_start', 24, 8);
            $table->decimal('early_finish', 24, 8);
            $table->decimal('late_start', 24, 8);
            $table->decimal('late_finish', 24, 8);
            $table->decimal('slack', 24, 8);
            $table->boolean('is_critical');
            $table->unique(['cpm_analysis_id', 'task_id']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpm_results');
    }
};

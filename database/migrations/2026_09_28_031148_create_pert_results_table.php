<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pert_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pert_analysis_id')->constrained('pert_analyses')->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->decimal('optimistic_time', 24, 8);
            $table->decimal('most_likely_time', 24, 8);
            $table->decimal('pessimistic_time', 24, 8);
            $table->decimal('expected_time', 24, 8);
            $table->decimal('variance', 24, 8);
            $table->decimal('standard_deviation', 24, 8);
            $table->unique(['pert_analysis_id', 'task_id']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pert_results');
    }
};

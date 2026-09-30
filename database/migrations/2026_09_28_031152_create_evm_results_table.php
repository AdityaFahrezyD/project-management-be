<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evm_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('evm_analysis_id')->constrained('evm_analyses')->restrictOnDelete();
            $table->decimal('planned_value', 24, 8);
            $table->decimal('earned_value', 24, 8);
            $table->decimal('actual_cost', 24, 8);
            $table->decimal('cost_variance', 24, 8);
            $table->decimal('schedule_variance', 24, 8);
            $table->decimal('cost_performance_index', 24, 8)->nullable();
            $table->decimal('schedule_performance_index', 24, 8)->nullable();
            $table->decimal('estimate_at_completion', 24, 8)->nullable();
            $table->decimal('estimate_to_complete', 24, 8)->nullable();
            $table->decimal('variance_at_completion', 24, 8)->nullable();
            $table->decimal('to_complete_performance_index', 24, 8)->nullable();
            $table->unique(['evm_analysis_id']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_results');
    }
};

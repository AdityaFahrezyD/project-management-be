<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('planned_amount', 20, 4);
            $table->unique(['budget_id', 'period_start', 'period_end']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_periods');
    }
};

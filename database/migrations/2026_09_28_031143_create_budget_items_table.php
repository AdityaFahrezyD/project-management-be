<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->restrictOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained('resources')->restrictOnDelete();
            $table->text('description');
            $table->decimal('planned_quantity', 12, 4)->nullable();
            $table->decimal('planned_duration', 12, 4)->nullable();
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->decimal('planned_amount', 20, 4);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_items');
    }
};

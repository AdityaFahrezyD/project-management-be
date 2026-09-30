<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The baseline references this project and is created later in the migration sequence.
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::create('projects', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('active_baseline_id')->nullable()->constrained('project_baselines')->restrictOnDelete();
                $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->string('status')->default('planning');
                $table->string('priority')->default('medium');
                $table->dateTime('start_date', 6)->nullable();
                $table->dateTime('target_end_date', 6)->nullable();
                $table->dateTime('actual_end_date', 6)->nullable();
                $table->decimal('budget_amount', 20, 4)->default(0);
                $table->char('currency', 3)->default('IDR');
                $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
                $table->dateTime('archived_at', 6)->nullable();
                $table->unique(['workspace_id', 'slug']);
                $table->index(['workspace_id', 'status']);
                $table->timestamps(6);
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

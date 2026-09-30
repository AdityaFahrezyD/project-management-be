<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('predecessor_task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignUuid('successor_task_id')->constrained('tasks')->restrictOnDelete();
            $table->string('dependency_type')->default('FS');
            $table->unique(['predecessor_task_id', 'successor_task_id']);
            $table->index('project_id');
            $table->index('predecessor_task_id');
            $table->index('successor_task_id');
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignUuid('resource_id')->constrained('resources')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('duration', 12, 4)->default(1);
            $table->decimal('estimated_cost', 20, 4);
            $table->unique(['task_id', 'resource_id']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_resources');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('tasks')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->string('priority')->default('medium');
            $table->dateTime('start_date', 6)->nullable();
            $table->dateTime('due_date', 6)->nullable();
            $table->decimal('duration_days', 12, 4)->default(1);
            $table->decimal('optimistic_time', 12, 4)->nullable();
            $table->decimal('most_likely_time', 12, 4)->nullable();
            $table->decimal('pessimistic_time', 12, 4)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at', 6)->nullable();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'due_date']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::dropIfExists('tasks');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->restrictOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained('resources')->restrictOnDelete();
            $table->text('description');
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3)->default('IDR');
            $table->date('expense_date');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->index(['project_id', 'expense_date']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('type')->default('initial');
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->char('currency', 3)->default('IDR');
            $table->string('estimation_method')->default('manual');
            $table->string('status')->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at', 6)->nullable();
            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_baselines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->json('snapshot_data');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->nullable();
            $table->unique(['project_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::dropIfExists('project_baselines');
        });
    }
};

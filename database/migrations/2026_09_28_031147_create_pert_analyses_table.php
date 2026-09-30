<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pert_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('running');
            $table->dateTime('analyzed_at', 6)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->json('input_snapshot');
            $table->string('engine_version')->nullable();
            $table->json('error_metadata')->nullable();
            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pert_analyses');
    }
};

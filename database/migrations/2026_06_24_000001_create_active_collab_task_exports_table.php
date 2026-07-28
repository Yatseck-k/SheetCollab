<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_collab_task_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id')->nullable()->index();
            $table->string('external_id')->unique();
            $table->string('spreadsheet_id');
            $table->string('sheet_id');
            $table->unsignedInteger('row_number');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('task_id')->nullable();
            $table->text('task_url')->nullable();
            $table->json('source_payload');
            $table->json('active_collab_payload')->nullable();
            $table->json('active_collab_response')->nullable();
            $table->string('sync_hash')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_collab_task_exports');
    }
};

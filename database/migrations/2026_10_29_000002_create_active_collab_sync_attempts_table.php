<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_collab_sync_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('export_id')->constrained('active_collab_task_exports')->restrictOnDelete();
            $table->uuid('integration_id')->index();
            $table->uuid('operation_id')->unique();
            $table->string('action');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('source_payload');
            $table->json('active_collab_payload')->nullable();
            $table->json('active_collab_response')->nullable();
            $table->string('sync_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_collab_sync_attempts');
    }
};

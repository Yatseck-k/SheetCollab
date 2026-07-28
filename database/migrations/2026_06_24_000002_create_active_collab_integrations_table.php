<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_collab_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('spreadsheet_id')->unique();
            $table->string('active_collab_base_url');
            $table->unsignedInteger('active_collab_project_id');
            $table->unsignedInteger('active_collab_task_list_id');
            $table->text('active_collab_api_token');
            $table->json('task_list_mapping')->nullable();
            $table->json('assignee_mapping')->nullable();
            $table->json('label_mapping')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_collab_integrations');
    }
};

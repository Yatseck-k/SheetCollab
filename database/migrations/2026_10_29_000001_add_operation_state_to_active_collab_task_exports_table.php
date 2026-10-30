<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_collab_task_exports', function (Blueprint $table) {
            $table->string('last_attempt_status')->nullable();
            $table->string('binding_status')->nullable();
            $table->uuid('operation_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->index(['integration_id', 'task_id'], 'ac_exports_integration_task_index');
        });
    }

    public function down(): void
    {
        Schema::table('active_collab_task_exports', function (Blueprint $table) {
            $table->dropIndex('ac_exports_integration_task_index');
            $table->dropColumn(['last_attempt_status', 'binding_status', 'operation_id', 'lease_expires_at', 'synced_at']);
        });
    }
};

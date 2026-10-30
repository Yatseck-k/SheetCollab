<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_collab_task_exports', function (Blueprint $table): void {
            $table->uuid('record_uuid')->nullable();
            $table->unique(['integration_id', 'record_uuid'], 'ac_exports_integration_record_unique');
        });
        Schema::table('active_collab_integrations', function (Blueprint $table): void {
            $table->boolean('uuid_only')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('active_collab_task_exports')->whereNotNull('record_uuid')->exists()
            || DB::table('active_collab_integrations')->where('uuid_only', true)->exists()) {
            throw new RuntimeException('UUID identities exist. Stop writers and use the documented recovery procedure.');
        }
        Schema::table('active_collab_task_exports', function (Blueprint $table): void {
            $table->dropUnique('ac_exports_integration_record_unique');
            $table->dropColumn('record_uuid');
        });
        Schema::table('active_collab_integrations', function (Blueprint $table): void {
            $table->dropColumn('uuid_only');
        });
    }
};

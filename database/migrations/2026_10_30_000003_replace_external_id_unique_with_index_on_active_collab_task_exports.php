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
            $table->index('external_id');
            $table->dropUnique('active_collab_task_exports_external_id_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('active_collab_task_exports')->whereNotNull('record_uuid')->exists()
            || DB::table('active_collab_integrations')->where('uuid_only', true)->exists()
            || DB::table('active_collab_task_exports')->select('external_id')->groupBy('external_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore row-number uniqueness. Stop writers and use the documented recovery procedure.');
        }
        Schema::table('active_collab_task_exports', function (Blueprint $table): void {
            $table->unique('external_id');
            $table->dropIndex('active_collab_task_exports_external_id_index');
        });
    }
};

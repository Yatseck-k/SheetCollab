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
        foreach (['active_collab_task_exports', 'active_collab_sync_attempts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('hash_version', 32)->nullable());
        }
        Schema::table('active_collab_integrations', fn (Blueprint $table) => $table->boolean('allow_legacy_token')->default(false));
        Schema::create('active_collab_integration_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('active_collab_integrations')->restrictOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('active_collab_integration_tokens')->exists()
            || DB::table('active_collab_integrations')->where('allow_legacy_token', true)->exists()
            || DB::table('active_collab_task_exports')->whereNotNull('hash_version')->exists()
            || DB::table('active_collab_sync_attempts')->whereNotNull('hash_version')->exists()) {
            throw new RuntimeException('Access or hash history exists. Stop writers and use the documented recovery procedure.');
        }
        Schema::drop('active_collab_integration_tokens');
        Schema::table('active_collab_integrations', fn (Blueprint $table) => $table->dropColumn('allow_legacy_token'));
        foreach (['active_collab_task_exports', 'active_collab_sync_attempts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('hash_version'));
        }
    }
};

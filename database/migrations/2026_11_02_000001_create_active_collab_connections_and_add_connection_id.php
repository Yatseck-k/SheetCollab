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
        Schema::create('active_collab_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('base_url');
            $table->text('api_token');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('active_collab_integrations', function (Blueprint $table): void {
            $table->foreignUuid('connection_id')
                ->nullable()
                ->constrained('active_collab_connections')
                ->restrictOnDelete();
            $table->string('active_collab_base_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('active_collab_integrations')->whereNotNull('connection_id')->exists()
            || DB::table('active_collab_integrations')->whereNull('active_collab_base_url')->exists()
            || DB::table('active_collab_connections')->exists()) {
            throw new RuntimeException('ActiveCollab connections are in use or contain credentials. Restore a pre-migration database backup to roll back.');
        }

        Schema::table('active_collab_integrations', function (Blueprint $table): void {
            $table->string('active_collab_base_url')->nullable(false)->change();
            $table->dropForeign(['connection_id']);
            $table->dropColumn('connection_id');
        });
        Schema::dropIfExists('active_collab_connections');
    }
};

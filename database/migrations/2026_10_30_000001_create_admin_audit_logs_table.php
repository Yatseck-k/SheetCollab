<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type');
            $table->uuid('subject_id')->index();
            $table->uuid('integration_id')->nullable()->index();
            $table->uuid('connection_id')->nullable();
            $table->string('action');
            $table->uuid('request_id');
            $table->uuid('operation_id')->index();
            $table->string('phase');
            $table->json('before');
            $table->json('after');
            $table->json('context');
            $table->timestamp('created_at');
            $table->unique(['request_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};

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
        Schema::create('active_collab_integration_user', function (Blueprint $table): void {
            $table->foreignUuid('integration_id')->constrained('active_collab_integrations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['integration_id', 'user_id']);
            $table->index(['user_id', 'integration_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('active_collab_integration_user')->exists()) {
            throw new RuntimeException('Integration access assignments exist. Remove or migrate assignments before rolling back.');
        }

        Schema::dropIfExists('active_collab_integration_user');
    }
};

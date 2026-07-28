<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('active_collab_integrations', 'active_collab_instance_url')) {
            return;
        }

        Schema::table('active_collab_integrations', function (Blueprint $table) {
            $table->dropColumn('active_collab_instance_url');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('active_collab_integrations', 'active_collab_instance_url')) {
            return;
        }

        Schema::table('active_collab_integrations', function (Blueprint $table) {
            $table->string('active_collab_instance_url')->default('');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_collab_integrations', function (Blueprint $table) {
            $table->text('active_collab_api_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('active_collab_integrations', function (Blueprint $table) {
            $table->text('active_collab_api_token')->nullable(false)->change();
        });
    }
};

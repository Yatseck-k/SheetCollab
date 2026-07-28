<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacyAsset = DB::table('script_assets')
                ->where('script_key', 'poc.gs')
                ->first();

            if ($legacyAsset === null) {
                return;
            }

            $canonicalAssetExists = DB::table('script_assets')
                ->where('script_key', 'activecollab_sync.gs')
                ->exists();

            if ($canonicalAssetExists) {
                DB::table('script_assets')
                    ->where('script_key', 'poc.gs')
                    ->delete();

                DB::table('script_assets')
                    ->where('script_key', 'activecollab_sync.gs')
                    ->update([
                        'name' => 'ActiveCollab Sync Script',
                    ]);

                return;
            }

            DB::table('script_assets')
                ->where('script_key', 'poc.gs')
                ->update([
                    'script_key' => 'activecollab_sync.gs',
                    'name' => 'ActiveCollab Sync Script',
                ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $legacyAssetExists = DB::table('script_assets')
                ->where('script_key', 'poc.gs')
                ->exists();

            if ($legacyAssetExists) {
                DB::table('script_assets')
                    ->where('script_key', 'activecollab_sync.gs')
                    ->update([
                        'name' => 'ActiveCollab Sync Script',
                    ]);

                return;
            }

            DB::table('script_assets')
                ->where('script_key', 'activecollab_sync.gs')
                ->where('name', 'ActiveCollab Sync Script')
                ->update([
                    'script_key' => 'poc.gs',
                ]);
        });
    }
};

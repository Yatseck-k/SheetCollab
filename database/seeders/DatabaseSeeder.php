<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminPermissions = [
            'platform.index' => 1,
            'platform.activecollab.recovery' => 1,
            'platform.systems' => 1,
            'platform.systems.index' => 1,
            'platform.systems.roles' => 1,
            'platform.systems.settings' => 1,
            'platform.systems.users' => 1,
            'platform.systems.comment' => 1,
            'platform.systems.attachment' => 1,
            'platform.systems.media' => 1,
        ];

        User::query()->updateOrCreate([
            'email' => 'admin@example.test',
        ], [
            'name' => 'Local Admin',
            'password' => Hash::make('LocalAdmin123!'),
            'permissions' => $adminPermissions,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}

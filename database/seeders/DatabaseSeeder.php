<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'Full access']
        );

        Role::query()->firstOrCreate(
            ['slug' => 'editor'],
            ['name' => 'Editor', 'description' => 'Catalog editor']
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@travel.loc'],
            [
                'name' => 'admin',
                'password' => Hash::make('admin'),
                'role_id' => $adminRole->id,
            ]
        );

        // Simple local credentials; change anytime in Admin → Users → Edit.
        User::query()->updateOrCreate(
            ['email' => 'akvamarin01@travel.loc'],
            [
                'name' => 'akvamarin01',
                'password' => Hash::make('123456'),
                'role_id' => $adminRole->id,
            ]
        );

        Language::query()->updateOrCreate(
            ['code' => 'ru'],
            [
                'name' => 'Русский',
                'locale' => 'ru-RU',
                'directory' => 'ru-ru',
                'sort_order' => 1,
                'status' => true,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        Language::query()->updateOrCreate(
            ['code' => 'en'],
            [
                'name' => 'English',
                'locale' => 'en-gb',
                'directory' => 'en-gb',
                'sort_order' => 2,
                'status' => true,
                'is_default' => false,
                'is_active' => true,
            ]
        );
    }
}

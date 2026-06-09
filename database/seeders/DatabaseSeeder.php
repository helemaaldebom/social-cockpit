<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'bas@burodebom.nl'],
            [
                'name' => 'Bas Romeijn',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-immediately')),
            ]
        );

        $this->call([
            ClientSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call(MenuSeeder::class);
        $this->call(StatusesTableSeeder::class);
        $this->call(UnitsSeeder::class);
        $this->call(PackageTypesSeeder::class);
        
    }
}

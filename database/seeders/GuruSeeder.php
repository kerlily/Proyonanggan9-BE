<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class GuruSeeder extends Seeder
{
    public function run(): void
    {
        $teachers = [
            ['name'=>'Guru A','email'=>'guru.a@example.test','nama'=>'Guru A'],
            ['name'=>'Guru B','email'=>'guru.b@example.test','nama'=>'Guru B'],
        ];

        foreach ($teachers as $t) {
            $userId = DB::table('users')->insertGetId([
                'name' => $t['name'],
                'email' => $t['email'],
                'password' => Hash::make('password'),
                'role' => 'guru',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('guru')->insert([
                'user_id' => $userId,
                'nama' => $t['nama'],
                'nip' => null,
                'no_hp' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateGuruRequest;
use App\Http\Requests\CreateSiswaRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;

class AdminUserController extends Controller
{
    /**
     * Create a guru (user + guru record). Admin only.
     * Accepts multipart/form-data (photo file).
     */
    public function createGuru(CreateGuruRequest $request)
    {
        $data = $request->validated();

        $photoPath = null;
        $rawPassword = $data['password'] ?? null;

        // generate random strong password if not provided
        if (empty($rawPassword)) {
            $rawPassword = Str::random(12);
        }

        DB::beginTransaction();
        try {
            // create user record
            // User model has password mutator to hash, so we can pass plain password
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $rawPassword,
                'role' => 'guru',
            ]);

            // handle photo upload
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('guru_photos', 'public');
            }

            // create guru profile
            $guru = Guru::create([
                'user_id' => $user->id,
                'nama' => $data['name'],
                'nip' => $data['nip'] ?? null,
                'no_hp' => $data['no_hp'] ?? null,
                'photo' => $photoPath,
            ]);

            DB::commit();

            // return created resource + raw password so admin can pass it to guru
            return response()->json([
                'message' => 'Guru created',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'guru' => $guru,
                // raw_password included so admin can inform guru; remove if undesired
                'raw_password' => $rawPassword,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            // cleanup uploaded file if any
            if (!empty($photoPath) && Storage::disk('public')->exists($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }
            return response()->json(['message' => 'Error creating guru: '.$e->getMessage()], 500);
        }
    }

    /**
     * Create a siswa. Admin only.
     * JSON body expected. Password default = tahun_lahir (hashed by Siswa model).
     */
    public function createSiswa(CreateSiswaRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $tahun_lahir = (int) $data['tahun_lahir'];
            $password = (string) $tahun_lahir;

            $siswa = Siswa::create([
                'nama' => $data['nama'],
                'tahun_lahir' => $tahun_lahir,
                'password' => $password, // Siswa model mutator will hash it
                'kelas_id' => $data['kelas_id'],
                'is_alumni' => $data['is_alumni'] ?? false,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Siswa created',
                'siswa' => $siswa,
                // send the raw password (tahun_lahir) so admin can inform siswa's guardian
                'raw_password' => $password,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating siswa: '.$e->getMessage()], 500);
        }
    }
}

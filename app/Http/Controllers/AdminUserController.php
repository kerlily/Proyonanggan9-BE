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

     public function updateSiswa(Request $request, $id)
    {
        $siswa = Siswa::find($id);
        if (!$siswa) {
            return response()->json(['message' => 'Siswa not found'], 404);
        }

        // Validasi (sesuaikan field yang kamu pakai)
        $validated = $request->validate([
            'nama' => 'sometimes|string|max:255',
            'tahun_lahir' => 'sometimes|integer|min:1900|max:'.(date('Y')),
            'kelas_id' => 'sometimes|exists:kelas,id',
            'is_alumni' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            // handle foto jika ada
            if ($request->hasFile('foto')) {
                // hapus foto lama jika ada
                if ($siswa->foto) {
                    Storage::delete($siswa->foto);
                }
                $path = $request->file('foto')->store('siswa');
                $validated['foto'] = $path;
            }

            $siswa->update($validated);

            DB::commit();
            return response()->json(['message' => 'Siswa updated', 'siswa' => $siswa], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating siswa: '.$e->getMessage()], 500);
        }
    }

    /**
     * Delete Siswa + related records (nilai, riwayat_kelas)
     */
    public function deleteSiswa($id)
    {
        $siswa = Siswa::find($id);
        if (!$siswa) {
            return response()->json(['message' => 'Siswa not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Hapus nilai terkait
            Nilai::where('siswa_id', $siswa->id)->delete();

            // Hapus riwayat_kelas terkait
            RiwayatKelas::where('siswa_id', $siswa->id)->delete();

            // Hapus file foto jika ada
            if ($siswa->foto) {
                Storage::delete($siswa->foto);
            }

            // Hapus record siswa
            $siswa->delete();

            DB::commit();
            return response()->json(['message' => 'Siswa deleted'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error deleting siswa: '.$e->getMessage()], 500);
        }
    }

    public function indexSiswa(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $search  = $request->query('search', null);
        $kelas   = $request->query('kelas', null);
        $sortBy  = $request->query('sort_by', 'nama');
        $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = Siswa::query();

        // Eager load relasi kelas jika ada
        if (method_exists(Siswa::class, 'riwayatKelas')) {
            $query->with(['riwayatKelas' => function($q) {
                // ambil riwayat terbaru untuk performa (sesuaikan jika mau semua)
                $q->with('kelas')->latest('id')->limit(1);
            }]);
        }

        // Search: nama atau nis
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('nis', 'like', "%{$search}%");
            });
        }

        // Filter by kelas: coba via relasi riwayatKelas -> kelas
        if ($kelas) {
            if (method_exists(Siswa::class, 'riwayatKelas')) {
                $query->whereHas('riwayatKelas.kelas', function($q) use ($kelas) {
                    $q->where('id', $kelas)->orWhere('nama', 'like', "%{$kelas}%");
                });
            } else {
                // fallback: jika ada kolom kelas_id langsung pada table siswa
                if (\Schema::hasColumn('siswa', 'kelas_id')) {
                    $query->where('kelas_id', $kelas);
                }
            }
        }

        // Guard allowed sort columns
        $allowedSort = ['nama', 'nis', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSort)) {
            $sortBy = 'nama';
        }

        $result = $query->orderBy($sortBy, $sortDir)
                        ->paginate($perPage)
                        ->appends($request->query());

        return response()->json($result, 200);
    }

    /**
     * Detail siswa untuk admin (include relasi penting)
     */
    public function showSiswa($id)
    {
        $siswa = Siswa::with([
            // sesuaikan nama relasi jika berbeda di model-mu
            'nilai' => function($q) { $q->orderBy('semester'); },
            'riwayatKelas.kelas',
            'user' // jika Siswa ber-relasi ke user model (opsional)
        ])->find($id);

        if (! $siswa) {
            return response()->json(['message' => 'Siswa not found'], 404);
        }

        // contoh meta sederhana
        $meta = [
            'total_nilai' => method_exists($siswa, 'nilai') ? $siswa->nilai()->count() : null,
            'riwayat_count' => $siswa->riwayatKelas ? $siswa->riwayatKelas->count() : 0
        ];

        return response()->json([
            'siswa' => $siswa,
            'meta' => $meta
        ], 200);
    }
}

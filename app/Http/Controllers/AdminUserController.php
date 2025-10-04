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
 * Show single guru (admin)
 */
public function showGuru($id)
{
    $guru = Guru::with('user')->find($id);
    if (! $guru) {
        return response()->json(['message' => 'Guru not found'], 404);
    }

    // build photo_url if photo path present
    $photoUrl = null;
    if ($guru->photo) {
        // storage path like 'guru_photos/xxx.jpg' -> /storage/guru_photos/xxx.jpg
        $photoUrl = asset(Storage::url($guru->photo)); // returns full URL
    } elseif ($guru->user && $guru->user->photo) {
        $photoUrl = asset(Storage::url($guru->user->photo));
    }

    return response()->json([
        'guru' => $guru,
        'photo_url' => $photoUrl
    ], 200);
}

/**
 * Update guru (admin)
 * Accepts multipart/form-data (photo optional).
 * Fields allowed: nama (guru), email (user), nip, no_hp, password (optional)
 */
public function updateGuru(Request $request, $id)
{
    $guru = Guru::with('user')->find($id);
    if (! $guru) {
        return response()->json(['message' => 'Guru not found'], 404);
    }

    // validate input
    $validated = $request->validate([
        'nama' => 'sometimes|string|max:255',
        'email' => 'sometimes|email|max:255|unique:users,email,'.$guru->user->id,
        'nip' => 'sometimes|nullable|string|max:50',
        'no_hp' => 'sometimes|nullable|string|max:50',
        'password' => 'sometimes|nullable|string|min:4',
        'photo' => 'sometimes|nullable|file|image|max:5120', // max 5MB
    ]);

    DB::beginTransaction();
    $oldPhoto = $guru->photo;
    try {
        // update user email/name if provided
        if (isset($validated['email'])) {
            $guru->user->email = $validated['email'];
        }
        if (isset($validated['nama'])) {
            // keep guru->nama and user->name in sync
            $guru->user->name = $validated['nama'];
            $guru->nama = $validated['nama'];
        }
        if (isset($validated['nip'])) $guru->nip = $validated['nip'];
        if (isset($validated['no_hp'])) $guru->no_hp = $validated['no_hp'];

        // handle password update (if provided)
        $rawPassword = null;
        if (!empty($validated['password'])) {
            $rawPassword = $validated['password'];
            $guru->user->password = Hash::make($rawPassword);
        }

        // handle photo: replace if new uploaded
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('guru_photos', 'public');
            $guru->photo = $path;
        }

        $guru->user->save();
        $guru->save();
        DB::commit();

        // delete old photo file if replaced
        if ($request->hasFile('photo') && $oldPhoto && Storage::disk('public')->exists($oldPhoto)) {
            Storage::disk('public')->delete($oldPhoto);
        }

        // return updated resource with photo_url
        $photoUrl = $guru->photo ? asset(Storage::url($guru->photo)) : null;

        return response()->json([
            'message' => 'Guru updated successfully',
            'guru' => $guru,
            'photo_url' => $photoUrl,
            'raw_password' => $rawPassword, // only present if password changed
        ], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        // if new uploaded file present but rolled back, remove it
        if (isset($path) && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        return response()->json(['message' => 'Error updating guru: '.$e->getMessage()], 500);
    }
}

/**
 * Delete guru (admin)
 * Removes guru record and associated user. Also deletes photo file.
 */
public function deleteGuru($id)
{
    $guru = Guru::with('user')->find($id);
    if (! $guru) {
        return response()->json(['message' => 'Guru not found'], 404);
    }

    DB::beginTransaction();
    try {
        // delete photo file if exists (on disk 'public')
        if ($guru->photo && Storage::disk('public')->exists($guru->photo)) {
            Storage::disk('public')->delete($guru->photo);
        }

        // delete guru, then delete user
        $user = $guru->user;
        $guru->delete();
        if ($user) {
            $user->delete();
        }

        DB::commit();

        return response()->json(['message' => 'Guru deleted successfully'], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json(['message' => 'Error deleting guru: '.$e->getMessage()], 500);
    }
}

/**
 * Reset guru password (admin)
 * POST body optional: { "password": "newpass" } otherwise generate random
 */
public function resetGuruPassword(Request $request, $id)
{
    $guru = Guru::with('user')->find($id);
    if (! $guru || ! $guru->user) {
        return response()->json(['message' => 'Guru not found'], 404);
    }

    $password = $request->input('password', null);
    if (empty($password)) {
        $password = Str::random(8);
    }

    try {
        $guru->user->password = Hash::make($password);
        $guru->user->save();

        return response()->json([
            'message' => 'Password reset successfully',
            'raw_password' => $password
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['message' => 'Error resetting password: '.$e->getMessage()], 500);
    }
}


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

    /**
 * Update Siswa
 * FIXED: Hanya field yang ada di table siswa
 */
public function updateSiswa(Request $request, $id)
{
    $siswa = Siswa::find($id);
    if (!$siswa) {
        return response()->json(['message' => 'Siswa not found'], 404);
    }

    // Validasi - HANYA field yang ada di table siswa
    $validated = $request->validate([
        'nama' => 'sometimes|string|max:255',
        'tahun_lahir' => 'sometimes|integer|min:1900|max:'.(date('Y')),
        'kelas_id' => 'sometimes|nullable|exists:kelas,id',
        'is_alumni' => 'sometimes|boolean',
    ]);

    DB::beginTransaction();
    try {
        $siswa->update($validated);

        DB::commit();

        // Load kelas untuk response
        $siswa->load('kelas');

        return response()->json([
            'message' => 'Siswa updated successfully',
            'siswa' => $siswa
        ], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error updating siswa: '.$e->getMessage()
        ], 500);
    }
}

/**
 * Delete Siswa + related records (nilai, riwayat_kelas)
 * FIXED: Import model yang benar
 */
public function deleteSiswa($id)
{
    $siswa = Siswa::find($id);
    if (!$siswa) {
        return response()->json(['message' => 'Siswa not found'], 404);
    }

    DB::beginTransaction();
    try {
        // Hapus nilai terkait (cascade handled by FK, tapi kita bisa manual juga)
        \App\Models\Nilai::where('siswa_id', $siswa->id)->delete();

        // Hapus riwayat_kelas terkait (cascade handled by FK)
        \App\Models\RiwayatKelas::where('siswa_id', $siswa->id)->delete();

        // Hapus record siswa
        $siswa->delete();

        DB::commit();

        return response()->json([
            'message' => 'Siswa deleted successfully'
        ], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error deleting siswa: '.$e->getMessage()
        ], 500);
    }
}


/**
 * Detail siswa untuk admin (include relasi penting)
 * Fixed: orderBy menggunakan kolom yang benar
 */
/**
 * List siswa untuk admin dengan pagination, search, filter, dan sort
 * FIXED: Hanya menggunakan kolom yang ada di table siswa
 */
public function indexSiswa(Request $request)
{
    $perPage = (int) $request->query('per_page', 15);
    $search  = $request->query('search', null);
    $kelasId = $request->query('kelas_id', null);
    $isAlumni = $request->query('is_alumni', null);
    $sortBy  = $request->query('sort_by', 'nama');
    $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    $query = Siswa::query();

    // Eager load relasi kelas
    $query->with(['kelas']);

    // Search: hanya berdasarkan nama (karena nis tidak ada)
    if ($search) {
        $query->where('nama', 'like', "%{$search}%");
    }

    // Filter by kelas_id
    if ($kelasId) {
        $query->where('kelas_id', $kelasId);
    }

    // Filter by is_alumni
    if ($isAlumni !== null) {
        $query->where('is_alumni', (bool) $isAlumni);
    }

    // Guard allowed sort columns - HANYA kolom yang ada di table siswa
    $allowedSort = ['id', 'nama', 'tahun_lahir', 'is_alumni', 'kelas_id', 'created_at', 'updated_at'];
    if (! in_array($sortBy, $allowedSort)) {
        $sortBy = 'nama';
    }

    $result = $query->orderBy($sortBy, $sortDir)
                    ->paginate($perPage)
                    ->appends($request->query());

    return response()->json($result, 200);
}
public function showSiswa($id)
{
    $siswa = Siswa::with([
        // Load relasi nilai dengan order yang benar
        'nilai' => function($q) {
            $q->with(['mapel', 'semester', 'tahunAjaran', 'inputByGuru'])
              ->orderByDesc('tahun_ajaran_id')
              ->orderByDesc('semester_id') // FIXED: semester_id bukan semester
              ->orderBy('mapel_id');
        },
        // Load riwayat kelas dengan kelas dan tahun ajaran
        'riwayatKelas' => function($q) {
            $q->with(['kelas', 'tahunAjaran'])
              ->orderByDesc('tahun_ajaran_id');
        },
        // Load kelas saat ini
        'kelas'
    ])->find($id);

    if (! $siswa) {
        return response()->json(['message' => 'Siswa not found'], 404);
    }

    // Group nilai by tahun ajaran & semester untuk response yang lebih terstruktur
    $nilaiGrouped = $siswa->nilai->groupBy(function($n) {
        return $n->tahun_ajaran_id . '_' . $n->semester_id;
    })->map(function($items) {
        $first = $items->first();
        return [
            'tahun_ajaran' => [
                'id' => $first->tahunAjaran?->id,
                'nama' => $first->tahunAjaran?->nama,
            ],
            'semester' => [
                'id' => $first->semester?->id,
                'nama' => $first->semester?->nama,
            ],
            'nilai' => $items->map(function($n) {
                return [
                    'id' => $n->id,
                    'mapel' => [
                        'id' => $n->mapel?->id,
                        'nama' => $n->mapel?->nama,
                        'kode' => $n->mapel?->kode,
                    ],
                    'nilai' => $n->nilai,
                    'catatan' => $n->catatan,
                    'input_by_guru' => $n->inputByGuru ? [
                        'id' => $n->inputByGuru->id,
                        'nama' => $n->inputByGuru->nama,
                    ] : null,
                    'updated_at' => $n->updated_at,
                ];
            })->values()
        ];
    })->values();

    // Hitung statistik
    $meta = [
        'total_nilai' => $siswa->nilai ? $siswa->nilai->count() : 0,
        'riwayat_count' => $siswa->riwayatKelas ? $siswa->riwayatKelas->count() : 0,
        'kelas_saat_ini' => $siswa->kelas ? [
            'id' => $siswa->kelas->id,
            'nama' => $siswa->kelas->nama,
            'tingkat' => $siswa->kelas->tingkat,
            'section' => $siswa->kelas->section
        ] : null
    ];

    return response()->json([
        'siswa' => [
            'id' => $siswa->id,
            'nama' => $siswa->nama,
            'tahun_lahir' => $siswa->tahun_lahir,
            'is_alumni' => $siswa->is_alumni,
            'kelas_id' => $siswa->kelas_id,
            'created_at' => $siswa->created_at,
            'updated_at' => $siswa->updated_at,
        ],
        'kelas_saat_ini' => $meta['kelas_saat_ini'],
        'riwayat_kelas' => $siswa->riwayatKelas->map(function($rk) {
            return [
                'tahun_ajaran' => [
                    'id' => $rk->tahunAjaran?->id,
                    'nama' => $rk->tahunAjaran?->nama,
                ],
                'kelas' => [
                    'id' => $rk->kelas?->id,
                    'nama' => $rk->kelas?->nama,
                    'tingkat' => $rk->kelas?->tingkat,
                    'section' => $rk->kelas?->section,
                ]
            ];
        }),
        'academic_records' => $nilaiGrouped,
        'meta' => $meta
    ], 200);
}

    /**
 * List guru untuk admin dengan pagination, search, dan sort sederhana.
 * Query params:
 *  - page, per_page (default 15)
 *  - q (search by guru.nama atau user.email)
 *  - sort_by (allowed: id, nama, created_at)
 *  - sort_dir (asc|desc)
 */
public function indexGuru(Request $request)
{
    $perPage = (int) $request->query('per_page', 15);
    $q       = $request->query('q', null);
    $sortBy  = $request->query('sort_by', 'nama');
    $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    // allowed columns
    $allowedSort = ['id', 'nama', 'created_at', 'updated_at'];
    if (! in_array($sortBy, $allowedSort)) {
        $sortBy = 'nama';
    }

    // Query: ambil data guru + relasi user (untuk email, dll)
    $query = Guru::query()->with('user');

    if ($q) {
        $query->where(function($sub) use ($q) {
            $sub->where('nama', 'like', "%{$q}%")
                ->orWhereHas('user', function($u) use ($q) {
                    $u->where('email', 'like', "%{$q}%");
                });
        });
    }

    $result = $query->orderBy($sortBy, $sortDir)
                    ->paginate($perPage)
                    ->appends($request->query());

    // Normalisasi response: pelanggan frontend ingin array data (data) + pagination
    return response()->json($result, 200);
}
}

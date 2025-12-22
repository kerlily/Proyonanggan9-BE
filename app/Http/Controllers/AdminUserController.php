<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateGuruRequest;
use App\Http\Requests\CreateSiswaRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;

class AdminUserController extends Controller
{
/**
     * ========================================
     * CRUD ADMIN USER
     * ========================================
     */

    /**
     * List all admin users with pagination
     * GET /api/admin/admins?search=name&per_page=15
     */
    public function indexAdmin(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $search = $request->query('search', null);

        $query = User::where('role', 'admin');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $result = $query->orderBy('name')
                        ->paginate($perPage)
                        ->appends($request->query());

        return response()->json($result, 200);
    }

    /**
     * Show single admin user
     * GET /api/admin/admins/{id}
     */
    public function showAdmin($id)
    {
        $user = User::where('role', 'admin')->find($id);

        if (!$user) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        return response()->json([
            'admin' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ], 200);
    }

    /**
     * Create new admin user
     * POST /api/admin/admins
     * Body: { "name": "...", "email": "...", "password": "..." (optional) }
     */
    public function createAdmin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:8',
        ]);

        $rawPassword = $validated['password'] ?? Str::random(12);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $rawPassword, // Model User sudah punya mutator setPasswordAttribute yang auto-hash
                'role' => 'admin',
            ]);

            DB::commit();

            \Illuminate\Support\Facades\Log::info('Admin created new admin user', [
                'creator_id' => auth()->guard('api')->id(),
                'new_admin_id' => $user->id,
                'new_admin_email' => $user->email,
            ]);

            return response()->json([
                'message' => 'Admin user created successfully',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'raw_password' => $rawPassword, // kirim password ke creator admin
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating admin: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update admin user
     * PUT /api/admin/admins/{id}
     * Body: { "name": "...", "email": "...", "password": "..." (optional) }
     */
    public function updateAdmin(Request $request, $id)
    {
        $user = User::where('role', 'admin')->find($id);

        if (!$user) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|nullable|string|min:8',
        ]);

        DB::beginTransaction();
        try {
            $rawPassword = null;

            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }

            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }

            if (!empty($validated['password'])) {
                $rawPassword = $validated['password'];
                $user->password = $rawPassword; // mutator akan hash
            }

            $user->save();
            DB::commit();

            \Illuminate\Support\Facades\Log::info('Admin updated another admin user', [
                'updater_id' => auth()->guard('api')->id(),
                'updated_admin_id' => $user->id,
            ]);

            $response = [
                'message' => 'Admin updated successfully',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ];

            if ($rawPassword) {
                $response['raw_password'] = $rawPassword;
            }

            return response()->json($response, 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating admin: ' . $e->getMessage()
            ], 500);
        }
    }

/**
 * Delete admin user (SOFT DELETE)
 * DELETE /api/admin/admins/{id}
 */
public function deleteAdmin($id)
{
    $user = User::where('role', 'admin')->find($id);

    if (!$user) {
        return response()->json(['message' => 'Admin not found'], 404);
    }

    // Prevent self-deletion
    $currentUserId = auth()->guard('api')->id();
    if ($user->id === $currentUserId) {
        return response()->json([
            'message' => 'Cannot delete your own admin account'
        ], 422);
    }

    DB::beginTransaction();
    try {
        $adminData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        // SOFT DELETE
        $user->delete();

        DB::commit();

        Log::info('Admin user soft deleted', [
            'deleter_id' => $currentUserId,
            'deleted_admin' => $adminData,
            'note' => 'Data bisa di-restore dari trash'
        ]);

        return response()->json([
            'message' => 'Admin berhasil dihapus (bisa dipulihkan dari trash)'
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error deleting admin: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Reset admin password
     * POST /api/admin/admins/{id}/reset-password
     * Body: { "password": "..." } (optional, akan generate random jika kosong)
     */
    public function resetAdminPassword(Request $request, $id)
    {
        $user = User::where('role', 'admin')->find($id);

        if (!$user) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        $password = $request->input('password', null);
        if (empty($password)) {
            $password = Str::random(12);
        } else {
            // Validate password if provided
            $request->validate([
                'password' => 'string|min:8'
            ]);
        }

        try {
            $user->password = $password; // mutator akan hash
            $user->save();

            \Illuminate\Support\Facades\Log::info('Admin reset another admin password', [
                'resetter_id' => auth()->guard('api')->id(),
                'target_admin_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Admin password reset successfully',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'raw_password' => $password
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error resetting password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================
     * CRUD GURU
     * ========================================
     */
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
 * FIXED: Proper validation, password handling, and field mapping
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
        'name' => 'sometimes|string|max:255', // Allow both 'nama' and 'name'
        'email' => 'sometimes|email|max:255|unique:users,email,'.$guru->user->id,
        'nip' => 'sometimes|nullable|string|max:50',
        'no_hp' => 'sometimes|nullable|string|max:50',
        'password' => 'sometimes|nullable|string|min:4',
        'photo' => 'sometimes|nullable|file|image|max:5120', // max 5MB
    ]);

    DB::beginTransaction();
    $oldPhoto = $guru->photo;
    try {
        // FIXED: Handle both 'nama' and 'name' from frontend
        $namaValue = $validated['nama'] ?? $validated['name'] ?? null;

        // update user email/name if provided
        if (isset($validated['email'])) {
            $guru->user->email = $validated['email'];
        }
        if ($namaValue) {
            // keep guru->nama and user->name in sync
            $guru->user->name = $namaValue;
            $guru->nama = $namaValue;
        }
        if (isset($validated['nip'])) {
            $guru->nip = $validated['nip'];
        }
        if (isset($validated['no_hp'])) {
            $guru->no_hp = $validated['no_hp'];
        }

        // FIXED: handle password update properly
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
            'guru' => $guru->load('user'), // Reload with updated user
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
 * Delete guru (SOFT DELETE)
 * DELETE /api/admin/guru/{id}
 *
 * Data tidak dihapus permanen, bisa di-restore dari trash
 */
public function deleteGuru($id)
{
    $guru = Guru::with('user')->find($id);
    if (!$guru) {
        return response()->json(['message' => 'Guru not found'], 404);
    }

    DB::beginTransaction();
    try {
        // SOFT DELETE - data masih bisa di-restore
        $user = $guru->user;

        // Soft delete guru
        $guru->delete();

        // Soft delete user jika ada
        if ($user) {
            $user->delete();
        }

        DB::commit();

        Log::info('Guru soft deleted', [
            'admin_id' => auth()->guard('api')->id(),
            'guru_id' => $guru->id,
            'guru_nama' => $guru->nama,
            'note' => 'Data bisa di-restore dari trash'
        ]);

        return response()->json([
            'message' => 'Guru berhasil dihapus (bisa dipulihkan dari trash)',
            'deleted' => [
                'guru_id' => $guru->id,
                'guru_nama' => $guru->nama,
            ]
        ], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error deleting guru: '.$e->getMessage()
        ], 500);
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
 * Delete siswa (SOFT DELETE)
 * DELETE /api/admin/siswa/{id}
 *
 * Data tidak dihapus permanen, bisa di-restore dari trash
 */
public function deleteSiswa($id)
{
    $siswa = Siswa::find($id);
    if (!$siswa) {
        return response()->json(['message' => 'Siswa not found'], 404);
    }

    DB::beginTransaction();
    try {
        // SOFT DELETE - nilai dan riwayat TIDAK dihapus
        // Hanya siswa yang di-soft delete
        $siswaData = [
            'id' => $siswa->id,
            'nama' => $siswa->nama,
            'nilai_count' => $siswa->nilai()->count(),
        ];

        $siswa->delete();

        DB::commit();

        Log::info('Siswa soft deleted', [
            'admin_id' => auth()->guard('api')->id(),
            'siswa_id' => $siswa->id,
            'siswa_nama' => $siswa->nama,
            'note' => 'Data nilai tetap ada, bisa di-restore dari trash'
        ]);

        return response()->json([
            'message' => 'Siswa berhasil dihapus (bisa dipulihkan dari trash)',
            'deleted' => $siswaData,
            'note' => 'Data nilai dan riwayat kelas tetap tersimpan'
        ], 200);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error deleting siswa: '.$e->getMessage()
        ], 500);
    }
}


/**
 * List siswa DENGAN filter trashed
 * GET /api/admin/siswa?include_trashed=true
 */
public function indexSiswa(Request $request)
{
    $perPage = (int) $request->query('per_page', 15);
    $search = $request->query('search', null);
    $kelasId = $request->query('kelas_id', null);
    $isAlumni = $request->query('is_alumni', null);
    $sortBy = $request->query('sort_by', 'nama');
    $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    // ✅ TAMBAH: Option untuk include deleted
    $includeTrashed = $request->query('include_trashed', false);

    // ✅ MODIFIKASI: Support query trashed data
    $query = $includeTrashed
        ? Siswa::withTrashed()->with(['kelas'])
        : Siswa::query()->with(['kelas']);

    if ($search) {
        $query->where('nama', 'like', "%{$search}%");
    }

    if ($kelasId) {
        $query->where('kelas_id', $kelasId);
    }

    if ($isAlumni !== null) {
        $query->where('is_alumni', (bool) $isAlumni);
    }

    $allowedSort = ['id', 'nama', 'tahun_lahir', 'is_alumni', 'kelas_id', 'created_at', 'updated_at'];
    if (!in_array($sortBy, $allowedSort)) {
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
 * List guru DENGAN filter trashed
 * GET /api/admin/guru?include_trashed=true
 */
public function indexGuru(Request $request)
{
    $perPage = (int) $request->query('per_page', 15);
    $q = $request->query('q', null);
    $sortBy = $request->query('sort_by', 'nama');
    $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    // ✅ TAMBAH: Option untuk include deleted
    $includeTrashed = $request->query('include_trashed', false);

    $allowedSort = ['id', 'nama', 'created_at', 'updated_at'];
    if (!in_array($sortBy, $allowedSort)) {
        $sortBy = 'nama';
    }

    // ✅ MODIFIKASI: Support query trashed data
    $query = $includeTrashed
        ? Guru::withTrashed()->with('user')
        : Guru::query()->with('user');

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

    return response()->json($result, 200);
}

}

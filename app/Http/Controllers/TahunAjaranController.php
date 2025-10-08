<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TahunAjaran;
use App\Models\Semester;

class TahunAjaranController extends Controller
{
    /**
     * Get tahun ajaran yang sedang aktif
     * Bisa diakses oleh semua user (guru, admin, siswa)
     * Route: GET /api/tahun-ajaran/active
     */
    public function getActive()
    {
        $tahunAjaran = TahunAjaran::where('is_active', true)
            ->with(['semesters' => function($q) {
                $q->orderBy('nama');
            }])
            ->first();

        if (!$tahunAjaran) {
            return response()->json([
                'message' => 'Tidak ada tahun ajaran aktif',
                'data' => null
            ], 404);
        }

        // Get semester yang aktif juga
        $semesterAktif = Semester::where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('is_active', true)
            ->first();

        return response()->json([
            'message' => 'Success',
            'data' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
                'is_active' => $tahunAjaran->is_active,
                'created_at' => $tahunAjaran->created_at,
                'updated_at' => $tahunAjaran->updated_at,
                'semester_aktif' => $semesterAktif ? [
                    'id' => $semesterAktif->id,
                    'nama' => $semesterAktif->nama,
                    'is_active' => $semesterAktif->is_active,
                ] : null,
                'all_semesters' => $tahunAjaran->semesters->map(function($s) {
                    return [
                        'id' => $s->id,
                        'nama' => $s->nama,
                        'is_active' => $s->is_active,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get semua tahun ajaran (untuk admin)
     * Route: GET /api/admin/tahun-ajaran
     */
    public function index()
    {
        $tahunAjarans = TahunAjaran::with('semesters')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Success',
            'data' => $tahunAjarans
        ]);
    }

    /**
     * Get detail tahun ajaran by ID (untuk admin)
     * Route: GET /api/admin/tahun-ajaran/{id}
     */
    public function show($id)
    {
        $tahunAjaran = TahunAjaran::with('semesters')->find($id);

        if (!$tahunAjaran) {
            return response()->json([
                'message' => 'Tahun ajaran tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'message' => 'Success',
            'data' => $tahunAjaran
        ]);
    }

    /**
     * Create tahun ajaran baru (untuk admin)
     * Route: POST /api/admin/tahun-ajaran
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:100|unique:tahun_ajaran,nama',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            \DB::beginTransaction();

            // Jika set active, nonaktifkan tahun ajaran lain
            if ($request->input('is_active', false)) {
                TahunAjaran::where('is_active', true)->update(['is_active' => false]);
            }

            $tahunAjaran = TahunAjaran::create([
                'nama' => $request->nama,
                'is_active' => $request->input('is_active', false),
            ]);

            // Auto create 2 semester: ganjil dan genap
            Semester::create([
                'tahun_ajaran_id' => $tahunAjaran->id,
                'nama' => 'ganjil',
                'is_active' => true, // semester ganjil aktif by default
            ]);

            Semester::create([
                'tahun_ajaran_id' => $tahunAjaran->id,
                'nama' => 'genap',
                'is_active' => false,
            ]);

            \DB::commit();

            return response()->json([
                'message' => 'Tahun ajaran berhasil dibuat',
                'data' => $tahunAjaran->load('semesters')
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tahun ajaran (untuk admin)
     * Route: PUT /api/admin/tahun-ajaran/{id}
     */
    public function update(Request $request, $id)
    {
        $tahunAjaran = TahunAjaran::find($id);

        if (!$tahunAjaran) {
            return response()->json([
                'message' => 'Tahun ajaran tidak ditemukan'
            ], 404);
        }

        $request->validate([
            'nama' => 'sometimes|string|max:100|unique:tahun_ajaran,nama,' . $id,
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            \DB::beginTransaction();

            // Jika set active, nonaktifkan tahun ajaran lain
            if ($request->has('is_active') && $request->is_active) {
                TahunAjaran::where('id', '!=', $id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $tahunAjaran->update($request->only(['nama', 'is_active']));

            \DB::commit();

            return response()->json([
                'message' => 'Tahun ajaran berhasil diupdate',
                'data' => $tahunAjaran->load('semesters')
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete tahun ajaran (untuk admin)
     * Route: DELETE /api/admin/tahun-ajaran/{id}
     */
    public function destroy($id)
    {
        $tahunAjaran = TahunAjaran::find($id);

        if (!$tahunAjaran) {
            return response()->json([
                'message' => 'Tahun ajaran tidak ditemukan'
            ], 404);
        }

        // Cek apakah tahun ajaran sedang aktif
        if ($tahunAjaran->is_active) {
            return response()->json([
                'message' => 'Tidak bisa menghapus tahun ajaran yang sedang aktif'
            ], 422);
        }

        try {
            $tahunAjaran->delete();

            return response()->json([
                'message' => 'Tahun ajaran berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

   /**
     * Toggle semester aktif (untuk admin)
     * Route: POST /api/admin/semester/{id}/toggle-active
     */
    public function toggleSemester($semesterId)
    {
        $semester = Semester::with('tahunAjaran')->find($semesterId);

        if (!$semester) {
            return response()->json([
                'message' => 'Semester tidak ditemukan'
            ], 404);
        }

        // Validasi: semester harus milik tahun ajaran yang aktif
        if (!$semester->tahunAjaran || !$semester->tahunAjaran->is_active) {
            return response()->json([
                'message' => 'Semester hanya bisa diaktifkan pada tahun ajaran yang sedang aktif',
                'current_tahun_ajaran' => $semester->tahunAjaran ? $semester->tahunAjaran->nama : null,
                'is_active' => $semester->tahunAjaran ? $semester->tahunAjaran->is_active : false
            ], 422);
        }

        try {
            \DB::beginTransaction();

            // Nonaktifkan semester lain di tahun ajaran yang sama
            Semester::where('tahun_ajaran_id', $semester->tahun_ajaran_id)
                ->where('id', '!=', $semesterId)
                ->update(['is_active' => false]);

            // Aktifkan semester ini
            $semester->is_active = true;
            $semester->save();

            \DB::commit();

            return response()->json([
                'message' => 'Semester berhasil diaktifkan',
                'data' => [
                    'id' => $semester->id,
                    'nama' => $semester->nama,
                    'is_active' => $semester->is_active,
                    'tahun_ajaran' => [
                        'id' => $semester->tahunAjaran->id,
                        'nama' => $semester->tahunAjaran->nama,
                        'is_active' => $semester->tahunAjaran->is_active
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}

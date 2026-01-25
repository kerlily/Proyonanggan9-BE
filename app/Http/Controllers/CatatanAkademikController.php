<?php
// app/Http/Controllers/CatatanAkademikController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\StrukturNilaiMapel;
use App\Models\CatatanMapelSiswa;
use App\Models\Siswa;

class CatatanAkademikController extends Controller
{
    public function index($kelas_id, $struktur_id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->findOrFail($struktur_id);

        // Get all catatan for this struktur
        $catatan = CatatanMapelSiswa::with(['siswa:id,nama,nisn', 'inputByGuru:id,nama'])
            ->where('struktur_nilai_mapel_id', $struktur_id)
            ->get();

        // Convert to { siswa_id: {catatan, guru, timestamp} }
        $result = [];
        foreach ($catatan as $c) {
            $result[$c->siswa_id] = [
                'catatan' => $c->catatan,
                'input_by_guru' => [
                    'id' => $c->inputByGuru?->id,
                    'nama' => $c->inputByGuru?->nama,
                ],
                'updated_at' => $c->updated_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'struktur' => [
                'id' => $struktur->id,
                'mapel' => [
                    'id' => $struktur->mapel?->id,
                    'nama' => $struktur->mapel?->nama,
                ],
                'semester' => [
                    'id' => $struktur->semester?->id,
                    'nama' => $struktur->semester?->nama,
                ],
            ],
        ]);
    }

    /**
     * Bulk save catatan untuk banyak siswa
     * POST /kelas/{kelas_id}/struktur-nilai/{struktur_id}/catatan/bulk
     *
     * Body: {
     *   catatan_data: [
     *     { siswa_id: 1, catatan: "..." },
     *     { siswa_id: 2, catatan: "..." }
     *   ]
     * }
     */
    public function bulkStore(Request $request, $kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json([
                'success' => false,
                'message' => 'User bukan guru'
            ], 403);
        }

        // Validasi struktur
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->findOrFail($struktur_id);

        $data = $request->validate([
            'catatan_data' => ['required', 'array'],
            'catatan_data.*.siswa_id' => ['required', 'integer', 'exists:siswa,id'],
            'catatan_data.*.catatan' => ['required', 'string', 'max:1000'],
        ]);

        $saved = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($data['catatan_data'] as $item) {
                $siswa = Siswa::where('id', $item['siswa_id'])
                    ->where('kelas_id', $kelas_id)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$siswa) {
                    $errors[] = "Siswa ID {$item['siswa_id']} tidak ditemukan di kelas ini";
                    continue;
                }

                // Update or create catatan
                CatatanMapelSiswa::updateOrCreate(
                    [
                        'siswa_id' => $item['siswa_id'],
                        'struktur_nilai_mapel_id' => $struktur_id,
                    ],
                    [
                        'catatan' => $item['catatan'],
                        'input_by_guru_id' => $guru->id,
                    ]
                );

                $saved++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Berhasil menyimpan {$saved} catatan",
                'saved_count' => $saved,
                'errors' => $errors,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan catatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store/Update satu catatan siswa
     * POST /kelas/{kelas_id}/struktur-nilai/{struktur_id}/catatan/single
     *
     * Body: {
     *   siswa_id: 1,
     *   catatan: "..."
     * }
     */
    public function storeSingle(Request $request, $kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json([
                'success' => false,
                'message' => 'User bukan guru'
            ], 403);
        }

        // Validasi struktur
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->findOrFail($struktur_id);

        $data = $request->validate([
            'siswa_id' => ['required', 'integer', 'exists:siswa,id'],
            'catatan' => ['required', 'string', 'max:1000'],
        ]);

        // Validasi siswa in kelas
        $siswa = Siswa::where('id', $data['siswa_id'])
            ->where('kelas_id', $kelas_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Update or create
        $catatan = CatatanMapelSiswa::updateOrCreate(
            [
                'siswa_id' => $data['siswa_id'],
                'struktur_nilai_mapel_id' => $struktur_id,
            ],
            [
                'catatan' => $data['catatan'],
                'input_by_guru_id' => $guru->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Catatan berhasil disimpan',
            'data' => $catatan,
        ]);
    }

    /**
     * Delete catatan siswa
     * DELETE /kelas/{kelas_id}/struktur-nilai/{struktur_id}/catatan/{siswa_id}
     */
    public function destroy($kelas_id, $struktur_id, $siswa_id)
    {
        $catatan = CatatanMapelSiswa::where('siswa_id', $siswa_id)
            ->where('struktur_nilai_mapel_id', $struktur_id)
            ->firstOrFail();

        $catatan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Catatan berhasil dihapus',
        ]);
    }

    /**
     * Get catatan siswa untuk semua mapel (untuk rapor)
     * GET /siswa/{siswa_id}/catatan-akademik
     *
     * Params: ?semester_id=X&tahun_ajaran_id=Y
     */
    public function getBySiswa(Request $request, $siswa_id)
    {
        $siswa = Siswa::findOrFail($siswa_id);

        $query = CatatanMapelSiswa::with([
            'strukturNilaiMapel.mapel:id,nama,kode',
            'strukturNilaiMapel.semester:id,nama',
            'strukturNilaiMapel.tahunAjaran:id,nama',
            'inputByGuru:id,nama'
        ])->where('siswa_id', $siswa_id);

        // Filter by semester if provided
        if ($request->has('semester_id')) {
            $query->whereHas('strukturNilaiMapel', function($q) use ($request) {
                $q->where('semester_id', $request->semester_id);
            });
        }

        // Filter by tahun ajaran if provided
        if ($request->has('tahun_ajaran_id')) {
            $query->whereHas('strukturNilaiMapel', function($q) use ($request) {
                $q->where('tahun_ajaran_id', $request->tahun_ajaran_id);
            });
        }

        $catatan = $query->get();

        // Group by tahun ajaran & semester
        $grouped = $catatan->groupBy(function($item) {
            $struktur = $item->strukturNilaiMapel;
            return $struktur->tahun_ajaran_id . '_' . $struktur->semester_id;
        })->map(function($items) {
            $first = $items->first()->strukturNilaiMapel;

            return [
                'tahun_ajaran' => [
                    'id' => $first->tahunAjaran?->id,
                    'nama' => $first->tahunAjaran?->nama,
                ],
                'semester' => [
                    'id' => $first->semester?->id,
                    'nama' => $first->semester?->nama,
                ],
                'catatan_per_mapel' => $items->map(function($item) {
                    return [
                        'mapel' => [
                            'id' => $item->strukturNilaiMapel->mapel?->id,
                            'nama' => $item->strukturNilaiMapel->mapel?->nama,
                            'kode' => $item->strukturNilaiMapel->mapel?->kode,
                        ],
                        'catatan' => $item->catatan,
                        'input_by_guru' => [
                            'id' => $item->inputByGuru?->id,
                            'nama' => $item->inputByGuru?->nama,
                        ],
                        'updated_at' => $item->updated_at,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'nisn' => $siswa->nisn,
                'kelas' => $siswa->kelas?->nama,
            ],
            'data' => $grouped,
        ]);
    }
}

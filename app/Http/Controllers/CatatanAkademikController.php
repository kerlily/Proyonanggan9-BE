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

    /**
 * Get existing catatan untuk satu mapel
 * Bisa dengan struktur_id atau langsung dari mapel_id
 * GET /kelas/{kelas_id}/catatan-akademik
 */
public function index(Request $request, $kelas_id)
{
    $mapelId = $request->mapel_id;
    $strukturId = $request->struktur_id;
    $semesterId = $request->semester_id;
    $tahunAjaranId = $request->tahun_ajaran_id;

    if (!$tahunAjaranId) {
        $tahunAjaranId = \App\Models\TahunAjaran::where('is_active', true)->value('id');
    }

    // Validasi: harus ada salah satu (struktur_id atau mapel_id)
    if (!$strukturId && !$mapelId) {
        return response()->json([
            'success' => false,
            'message' => 'Parameter struktur_id atau mapel_id wajib diisi'
        ], 400);
    }

    $result = [];

    if ($strukturId) {
        // Case 1: Ada struktur - ambil dari catatan_mapel_siswa
        $struktur = \App\Models\StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->findOrFail($strukturId);

        $catatan = \App\Models\CatatanMapelSiswa::with(['siswa:id,nama,nisn', 'inputByGuru:id,nama'])
            ->where('struktur_nilai_mapel_id', $strukturId)
            ->get();

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
            'mapel' => [
                'id' => $struktur->mapel?->id,
                'nama' => $struktur->mapel?->nama,
            ],
            'has_struktur' => true,
        ]);

    } else {
        // Case 2: Tidak ada struktur - ambil dari tabel nilai langsung
        $query = \DB::table('nilai')
            ->where('mapel_id', $mapelId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNotNull('catatan')
            ->where('catatan', '!=', '')
            ->where('catatan', '!=', '-');

        if ($semesterId) {
            $query->where('semester_id', $semesterId);
        }

        // Join dengan siswa untuk dapat nama
        $query->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
            ->leftJoin('guru', 'nilai.input_by_guru_id', '=', 'guru.id')
            ->select(
                'nilai.siswa_id',
                'nilai.catatan',
                'nilai.updated_at',
                'guru.id as guru_id',
                'guru.nama as guru_nama',
                'siswa.nama as siswa_nama',
                'siswa.nisn'
            );

        $catatan = $query->get();

        foreach ($catatan as $c) {
            $result[$c->siswa_id] = [
                'catatan' => $c->catatan,
                'input_by_guru' => [
                    'id' => $c->guru_id,
                    'nama' => $c->guru_nama,
                ],
                'updated_at' => $c->updated_at,
            ];
        }

        $mapel = \App\Models\Mapel::find($mapelId);

        return response()->json([
            'success' => true,
            'data' => $result,
            'mapel' => [
                'id' => $mapel?->id,
                'nama' => $mapel?->nama,
            ],
            'has_struktur' => false,
        ]);
    }
}
    /**
 * Get list mapel yang bisa diinput catatan
 * (SEMUA mapel yang di-assign ke kelas, tidak perlu ada struktur)
 * GET /kelas/{kelas_id}/struktur-nilai/available-for-catatan
 *
 * Params: ?semester_id=X&tahun_ajaran_id=Y
 */
public function getAvailableStruktur(Request $request, $kelas_id)
{
    $semesterId = $request->semester_id;
    $tahunAjaranId = $request->tahun_ajaran_id;

    // Jika tidak ada tahun ajaran, ambil yang aktif
    if (!$tahunAjaranId) {
        $tahunAjaranId = \App\Models\TahunAjaran::where('is_active', true)->value('id');
    }

    // Validasi semester sesuai tahun ajaran
    if ($semesterId) {
        $semester = \App\Models\Semester::where('id', $semesterId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first();

        if (!$semester) {
            return response()->json([
                'success' => false,
                'message' => 'Semester tidak valid untuk tahun ajaran ini'
            ], 400);
        }
    }

    // Ambil kelas dengan mapel yang di-assign
    $kelas = \App\Models\Kelas::with('mapels')->findOrFail($kelas_id);

    if ($kelas->mapels->isEmpty()) {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Belum ada mapel yang di-assign ke kelas ini'
        ]);
    }

    $result = [];

    foreach ($kelas->mapels as $mapel) {
        // Cari struktur nilai untuk mapel ini
        $struktur = \App\Models\StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->where('mapel_id', $mapel->id)
            ->where('tahun_ajaran_id', $tahunAjaranId);

        if ($semesterId) {
            $struktur->where('semester_id', $semesterId);
        }

        $strukturData = $struktur->first();

        // Hitung catatan yang sudah ada
        $catatanCount = 0;
        if ($strukturData) {
            // Jika ada struktur, hitung dari catatan_mapel_siswa
            $catatanCount = \App\Models\CatatanMapelSiswa::where('struktur_nilai_mapel_id', $strukturData->id)
                ->count();
        } else {
            // Jika tidak ada struktur, hitung dari tabel nilai langsung
            $catatanCount = \DB::table('nilai')
                ->where('mapel_id', $mapel->id)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->whereNotNull('catatan')
                ->where('catatan', '!=', '')
                ->where('catatan', '!=', '-');

            if ($semesterId) {
                $catatanCount->where('semester_id', $semesterId);
            }

            $catatanCount = $catatanCount->count();
        }

        $result[] = [
            'id' => $strukturData?->id, // NULL jika belum ada struktur
            'mapel' => [
                'id' => $mapel->id,
                'nama' => $mapel->nama,
                'kode' => $mapel->kode,
            ],
            'semester' => $semesterId ? [
                'id' => $semester->id,
                'nama' => $semester->nama,
            ] : null,
            'has_struktur' => $strukturData !== null,
            'catatan_count' => $catatanCount,
        ];
    }

    // Sort by mapel name
    usort($result, function($a, $b) {
        return strcmp($a['mapel']['nama'], $b['mapel']['nama']);
    });

    return response()->json([
        'success' => true,
        'data' => $result,
    ]);
}
   public function bulkStore(Request $request, $kelas_id)
{
    $user = auth()->guard('api')->user();
    $guru = $user->guru;

    if (!$guru) {
        return response()->json([
            'success' => false,
            'message' => 'User bukan guru'
        ], 403);
    }

    $data = $request->validate([
        'mapel_id' => ['required', 'integer', 'exists:mapel,id'],
        'struktur_id' => ['nullable', 'integer', 'exists:struktur_nilai_mapel,id'],
        'semester_id' => ['nullable', 'integer', 'exists:semester,id'],
        'catatan_data' => ['required', 'array'],
        'catatan_data.*.siswa_id' => ['required', 'integer', 'exists:siswa,id'],
        'catatan_data.*.catatan' => ['required', 'string', 'max:1000'],
    ]);

    $strukturId = $data['struktur_id'] ?? null;
    $semesterId = $data['semester_id'] ?? null;
    $tahunAjaranId = \App\Models\TahunAjaran::where('is_active', true)->value('id');

    // Validasi: jika tidak ada struktur, semester_id wajib
    if (!$strukturId && !$semesterId) {
        return response()->json([
            'success' => false,
            'message' => 'Semester ID wajib diisi jika tidak ada struktur nilai'
        ], 400);
    }

    $saved = 0;
    $errors = [];

    DB::beginTransaction();
    try {
        foreach ($data['catatan_data'] as $item) {
            $siswa = \App\Models\Siswa::where('id', $item['siswa_id'])
                ->where('kelas_id', $kelas_id)
                ->whereNull('deleted_at')
                ->first();

            if (!$siswa) {
                $errors[] = "Siswa ID {$item['siswa_id']} tidak ditemukan di kelas ini";
                continue;
            }

            if ($strukturId) {
                // Case 1: Ada struktur - simpan ke catatan_mapel_siswa
                \App\Models\CatatanMapelSiswa::updateOrCreate(
                    [
                        'siswa_id' => $item['siswa_id'],
                        'struktur_nilai_mapel_id' => $strukturId,
                    ],
                    [
                        'catatan' => $item['catatan'],
                        'input_by_guru_id' => $guru->id,
                    ]
                );
            } else {
                // Case 2: Tidak ada struktur - simpan langsung ke tabel nilai
                \DB::table('nilai')->updateOrInsert(
                    [
                        'siswa_id' => $item['siswa_id'],
                        'mapel_id' => $data['mapel_id'],
                        'semester_id' => $semesterId,
                        'tahun_ajaran_id' => $tahunAjaranId,
                    ],
                    [
                        'catatan' => $item['catatan'],
                        'catatan_source' => 'manual',
                        'input_by_guru_id' => $guru->id,
                        'updated_at' => now(),
                        'created_at' => \DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            }

            $saved++;
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "Berhasil menyimpan {$saved} catatan",
            'saved_count' => $saved,
            'errors' => $errors,
            'saved_to' => $strukturId ? 'catatan_mapel_siswa' : 'nilai_table',
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Bulk save catatan error: ' . $e->getMessage());
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

    /**
     * Endpoint untuk siswa melihat catatan akademik mereka sendiri
     * GET /siswa/me/catatan-akademik
     * Auth: auth:siswa
     */
    public function siswaCatatanAkademik(Request $request)
    {
        $siswa = Auth::guard('siswa')->user();

        if (!$siswa) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $query = CatatanMapelSiswa::with([
            'strukturNilaiMapel.mapel:id,nama,kode',
            'strukturNilaiMapel.semester:id,nama',
            'strukturNilaiMapel.tahunAjaran:id,nama',
            'inputByGuru:id,nama'
        ])->where('siswa_id', $siswa->id);

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

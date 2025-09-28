<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\RiwayatKelas;

class SiswaNilaiController extends Controller
{
    /**
     * Tampilkan semua nilai siswa yang sedang login,
     * dikelompokkan per semester dan tahun ajaran dengan info kelas historis.
     */
    public function index(Request $request)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ambil semua nilai siswa, eager load mapel+semester+tahunAjaran
        $nilaiAll = Nilai::with(['mapel','semester','tahunAjaran'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->orderBy('mapel_id')
            ->get();

        // ambil riwayat kelas siswa untuk semua tahun ajaran yang ada nilainya
        $tahunAjaranIds = $nilaiAll->pluck('tahun_ajaran_id')->unique();
        $riwayatKelas = RiwayatKelas::with('kelas')
            ->where('siswa_id', $siswa->id)
            ->whereIn('tahun_ajaran_id', $tahunAjaranIds)
            ->get()
            ->keyBy('tahun_ajaran_id'); // index by tahun_ajaran_id untuk mudah akses

        // group by combination of tahun_ajaran_id and semester_id
        $grouped = $nilaiAll->groupBy(function($n) {
            return $n->tahun_ajaran_id . '_' . $n->semester_id;
        })->map(function($items, $groupKey) use ($riwayatKelas) {
            $firstItem = $items->first();
            $semester = $firstItem->semester;
            $tahunAjaran = $firstItem->tahunAjaran;

            // ambil kelas historis untuk tahun ajaran ini
            $kelasHistoris = $riwayatKelas->get($firstItem->tahun_ajaran_id)?->kelas;

            return [
                'tahun_ajaran' => [
                    'id' => $tahunAjaran?->id,
                    'nama' => $tahunAjaran?->nama,
                ],
                'semester' => [
                    'id' => $semester?->id,
                    'nama' => $semester?->nama,
                    'tahun_ajaran_id' => $semester?->tahun_ajaran_id,
                ],
                'kelas_historis' => [
                    'id' => $kelasHistoris?->id,
                    'nama' => $kelasHistoris?->nama,
                    'tingkat' => $kelasHistoris?->tingkat,
                    'section' => $kelasHistoris?->section,
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
                        'input_by_guru_id' => $n->input_by_guru_id,
                        'updated_at' => $n->updated_at,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas_saat_ini' => $siswa->kelas->nama ?? null,
            ],
            'academic_records' => $grouped,
        ]);
    }

    /**
     * Tampilkan nilai untuk semester tertentu milik siswa yang login.
     * Sekarang juga memfilter berdasarkan tahun ajaran aktif dan menampilkan kelas historis.
     */
    public function bySemester(Request $request, $semester_id)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) return response()->json(['message' => 'Unauthorized'], 401);

        // pastikan semester ada
        $semester = Semester::with('tahunAjaran')->find($semester_id);
        if (! $semester) return response()->json(['message' => 'Semester not found'], 404);

        // ambil nilai berdasarkan siswa_id, semester_id, dan tahun_ajaran_id dari semester
        $nilai = Nilai::with('mapel')
            ->where('siswa_id', $siswa->id)
            ->where('semester_id', $semester_id)
            ->where('tahun_ajaran_id', $semester->tahun_ajaran_id)
            ->get();

        // ambil kelas historis untuk tahun ajaran ini
        $riwayatKelas = RiwayatKelas::with('kelas')
            ->where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $semester->tahun_ajaran_id)
            ->first();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas_saat_ini' => $siswa->kelas->nama ?? null,
            ],
            'tahun_ajaran' => [
                'id' => $semester->tahunAjaran?->id,
                'nama' => $semester->tahunAjaran?->nama,
            ],
            'semester' => [
                'id' => $semester->id,
                'nama' => $semester->nama,
                'tahun_ajaran_id' => $semester->tahun_ajaran_id
            ],
            'kelas_historis' => [
                'id' => $riwayatKelas?->kelas?->id,
                'nama' => $riwayatKelas?->kelas?->nama,
                'tingkat' => $riwayatKelas?->kelas?->tingkat,
                'section' => $riwayatKelas?->kelas?->section,
            ],
            'nilai' => $nilai->map(function($n){
                return [
                    'id'=>$n->id,
                    'mapel'=>['id'=>$n->mapel?->id,'nama'=>$n->mapel?->nama],
                    'nilai'=>$n->nilai,
                    'catatan'=>$n->catatan,
                    'tahun_ajaran_id'=>$n->tahun_ajaran_id,
                ];
            })->values()
        ]);
    }

    /**
     * Tampilkan satu record nilai (detail) — hanya jika record milik siswa yang login.
     */
    public function show(Request $request, $id)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) return response()->json(['message' => 'Unauthorized'], 401);

        $n = Nilai::with(['mapel','semester.tahunAjaran'])->findOrFail($id);

        if ($n->siswa_id !== $siswa->id) {
            return response()->json(['message' => 'Forbidden: this is not your grade'], 403);
        }

        // ambil kelas historis untuk tahun ajaran nilai ini
        $riwayatKelas = RiwayatKelas::with('kelas')
            ->where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $n->tahun_ajaran_id)
            ->first();

        return response()->json([
            'id' => $n->id,
            'mapel' => $n->mapel,
            'semester' => $n->semester,
            'tahun_ajaran' => $n->semester?->tahunAjaran,
            'kelas_historis' => [
                'id' => $riwayatKelas?->kelas?->id,
                'nama' => $riwayatKelas?->kelas?->nama,
                'tingkat' => $riwayatKelas?->kelas?->tingkat,
                'section' => $riwayatKelas?->kelas?->section,
            ],
            'nilai' => $n->nilai,
            'catatan' => $n->catatan,
            'input_by_guru_id' => $n->input_by_guru_id,
            'tahun_ajaran_id' => $n->tahun_ajaran_id,
            'created_at' => $n->created_at,
            'updated_at' => $n->updated_at,
        ]);
    }

    /**
     * Method untuk mendapatkan nilai berdasarkan tahun ajaran tertentu dengan kelas historis.
     */
    public function byTahunAjaran(Request $request, $tahun_ajaran_id)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) return response()->json(['message' => 'Unauthorized'], 401);

        // ambil semua nilai siswa untuk tahun ajaran tertentu
        $nilaiAll = Nilai::with(['mapel','semester.tahunAjaran'])
            ->where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $tahun_ajaran_id)
            ->orderBy('semester_id')
            ->orderBy('mapel_id')
            ->get();

        if ($nilaiAll->isEmpty()) {
            return response()->json(['message' => 'No grades found for this academic year'], 404);
        }

        // ambil kelas historis untuk tahun ajaran ini
        $riwayatKelas = RiwayatKelas::with('kelas')
            ->where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $tahun_ajaran_id)
            ->first();

        // group by semester_id dalam tahun ajaran yang sama
        $tahunAjaran = $nilaiAll->first()->semester?->tahunAjaran;

        $grouped = $nilaiAll->groupBy('semester_id')->map(function($items, $semesterId) {
            $semester = $items->first()->semester;
            return [
                'semester' => [
                    'id' => $semester?->id,
                    'nama' => $semester?->nama,
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
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas_saat_ini' => $siswa->kelas->nama ?? null,
            ],
            'tahun_ajaran' => [
                'id' => $tahunAjaran?->id,
                'nama' => $tahunAjaran?->nama,
            ],
            'kelas_historis' => [
                'id' => $riwayatKelas?->kelas?->id,
                'nama' => $riwayatKelas?->kelas?->nama,
                'tingkat' => $riwayatKelas?->kelas?->tingkat,
                'section' => $riwayatKelas?->kelas?->section,
            ],
            'semesters' => $grouped,
        ]);
    }
}

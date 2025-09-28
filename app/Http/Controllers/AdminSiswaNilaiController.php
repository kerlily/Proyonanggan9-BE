<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Nilai;
use App\Models\Siswa;
use App\Models\WaliKelas;
use App\Models\TahunAjaran;

class AdminSiswaNilaiController extends Controller
{
    protected function userIsWaliOfSiswa($user, Siswa $siswa): bool
    {
        // admin bypass
        if ($user->role === 'admin') return true;

        // must be guru to be wali
        if ($user->role !== 'guru') return false;

        // get guru model id
        $guru = $user->guru;
        if (! $guru) return false;

        // find active year
        $tahunId = TahunAjaran::where('is_active', true)->value('id');
        if (! $tahunId) return false;

        // check wali_kelas entry exists for guru_id, kelas_id and tahun
        return WaliKelas::where('guru_id', $guru->id)
            ->where('kelas_id', $siswa->kelas_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->exists();
    }

    // list all nilai for a siswa (admin or wali)
    public function index(Request $request, $siswa_id)
    {
        $user = Auth::guard('api')->user();
        if (! $user) return response()->json(['message'=>'Unauthorized'], 401);

        $siswa = Siswa::findOrFail($siswa_id);

        if (! $this->userIsWaliOfSiswa($user, $siswa)) {
            return response()->json(['message'=>'Forbidden: not allowed to view this student grades'], 403);
        }

        // ambil semua nilai siswa, grouped by tahun_ajaran dan semester
        $nilaiAll = Nilai::with(['mapel','semester','tahunAjaran','inputByGuru'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->orderBy('mapel_id')
            ->get();

        // group by combination of tahun_ajaran_id and semester_id
        $grouped = $nilaiAll->groupBy(function($n) {
            return $n->tahun_ajaran_id . '_' . $n->semester_id;
        })->map(function($items, $groupKey) {
            $firstItem = $items->first();
            $semester = $firstItem->semester;
            $tahunAjaran = $firstItem->tahunAjaran;

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
                        'input_by_guru' => $n->inputByGuru,
                        'tahun_ajaran_id' => $n->tahun_ajaran_id,
                        'updated_at' => $n->updated_at,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'siswa' => ['id'=>$siswa->id,'nama'=>$siswa->nama,'kelas_id'=>$siswa->kelas_id],
            'academic_records' => $grouped,
        ]);
    }

    // list nilai for a siswa filtered by semester
    public function bySemester(Request $request, $siswa_id, $semester_id)
    {
        $user = Auth::guard('api')->user();
        if (! $user) return response()->json(['message'=>'Unauthorized'], 401);

        $siswa = Siswa::findOrFail($siswa_id);

        if (! $this->userIsWaliOfSiswa($user, $siswa)) {
            return response()->json(['message'=>'Forbidden: not allowed to view this student grades'], 403);
        }

        // ambil semester dengan tahun ajaran
        $semester = \App\Models\Semester::with('tahunAjaran')->find($semester_id);
        if (!$semester) {
            return response()->json(['message'=>'Semester not found'], 404);
        }

        // filter berdasarkan siswa_id, semester_id, dan tahun_ajaran_id
        $nilai = Nilai::with('mapel','semester','inputByGuru')
            ->where('siswa_id', $siswa->id)
            ->where('semester_id', $semester_id)
            ->where('tahun_ajaran_id', $semester->tahun_ajaran_id)
            ->get();

        return response()->json([
            'siswa' => ['id'=>$siswa->id,'nama'=>$siswa->nama,'kelas_id'=>$siswa->kelas_id],
            'tahun_ajaran' => [
                'id' => $semester->tahunAjaran?->id,
                'nama' => $semester->tahunAjaran?->nama,
            ],
            'semester' => [
                'id' => $semester->id,
                'nama' => $semester->nama,
                'tahun_ajaran_id' => $semester->tahun_ajaran_id
            ],
            'nilai' => $nilai->map(function($n) {
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
                    'input_by_guru' => $n->inputByGuru,
                    'tahun_ajaran_id' => $n->tahun_ajaran_id,
                    'updated_at' => $n->updated_at,
                ];
            })->values(),
        ]);
    }

    // show single nilai detail (only if it belongs to siswa and user allowed)
    public function show(Request $request, $siswa_id, $nilai_id)
    {
        $user = Auth::guard('api')->user();
        if (! $user) return response()->json(['message'=>'Unauthorized'], 401);

        $siswa = Siswa::findOrFail($siswa_id);

        if (! $this->userIsWaliOfSiswa($user, $siswa)) {
            return response()->json(['message'=>'Forbidden: not allowed to view this student grades'], 403);
        }

        $n = Nilai::with(['mapel','semester.tahunAjaran','inputByGuru'])->findOrFail($nilai_id);

        if ($n->siswa_id !== (int)$siswa->id) {
            return response()->json(['message'=>'Bad Request: grade not belong to this student'], 400);
        }

        return response()->json([
            'id' => $n->id,
            'mapel' => $n->mapel,
            'semester' => $n->semester,
            'tahun_ajaran' => $n->semester?->tahunAjaran,
            'nilai' => $n->nilai,
            'catatan' => $n->catatan,
            'input_by_guru_id' => $n->input_by_guru_id,
            'input_by_guru' => $n->inputByGuru,
            'tahun_ajaran_id' => $n->tahun_ajaran_id,
            'created_at' => $n->created_at,
            'updated_at' => $n->updated_at,
        ]);
    }

    /**
     * NEW: Method untuk mendapatkan nilai siswa berdasarkan tahun ajaran tertentu
     */
    public function byTahunAjaran(Request $request, $siswa_id, $tahun_ajaran_id)
    {
        $user = Auth::guard('api')->user();
        if (! $user) return response()->json(['message'=>'Unauthorized'], 401);

        $siswa = Siswa::findOrFail($siswa_id);

        if (! $this->userIsWaliOfSiswa($user, $siswa)) {
            return response()->json(['message'=>'Forbidden: not allowed to view this student grades'], 403);
        }

        // ambil semua nilai siswa untuk tahun ajaran tertentu
        $nilaiAll = Nilai::with(['mapel','semester.tahunAjaran','inputByGuru'])
            ->where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $tahun_ajaran_id)
            ->orderBy('semester_id')
            ->orderBy('mapel_id')
            ->get();

        if ($nilaiAll->isEmpty()) {
            return response()->json(['message' => 'No grades found for this academic year'], 404);
        }

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
                        'input_by_guru_id' => $n->input_by_guru_id,
                        'input_by_guru' => $n->inputByGuru,
                        'tahun_ajaran_id' => $n->tahun_ajaran_id,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas_id' => $siswa->kelas_id,
            ],
            'tahun_ajaran' => [
                'id' => $tahunAjaran?->id,
                'nama' => $tahunAjaran?->nama,
            ],
            'semesters' => $grouped,
        ]);
    }
}

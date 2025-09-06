<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Nilai;
use App\Models\Semester;

class SiswaNilaiController extends Controller
{
    /**
     * Tampilkan semua nilai siswa yang sedang login,
     * dikelompokkan per semester (semester object + daftar nilai mapel).
     */
    public function index(Request $request)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ambil semua nilai siswa, eager load mapel+semester
        $nilaiAll = Nilai::with(['mapel','semester'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('semester_id')
            ->orderBy('mapel_id')
            ->get();

        // group by semester_id
        $grouped = $nilaiAll->groupBy(fn($n) => $n->semester_id)->map(function($items, $semesterId) {
            $semester = $items->first()->semester;
            return [
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
                        'updated_at' => $n->updated_at,
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
            'semesters' => $grouped,
        ]);
    }

    /**
     * Tampilkan nilai untuk semester tertentu milik siswa yang login.
     */
    public function bySemester(Request $request, $semester_id)
    {
        $siswa = Auth::guard('siswa')->user();
        if (! $siswa) return response()->json(['message' => 'Unauthorized'], 401);

        // pastikan semester ada
        $semester = Semester::find($semester_id);
        if (! $semester) return response()->json(['message' => 'Semester not found'], 404);

        $nilai = Nilai::with('mapel')
            ->where('siswa_id', $siswa->id)
            ->where('semester_id', $semester_id)
            ->get();

        return response()->json([
            'siswa' => ['id'=>$siswa->id,'nama'=>$siswa->nama,'kelas_id'=>$siswa->kelas_id],
            'semester' => ['id'=>$semester->id,'nama'=>$semester->nama,'tahun_ajaran_id'=>$semester->tahun_ajaran_id],
            'nilai' => $nilai->map(function($n){
                return [
                    'id'=>$n->id,
                    'mapel'=>['id'=>$n->mapel?->id,'nama'=>$n->mapel?->nama],
                    'nilai'=>$n->nilai,
                    'catatan'=>$n->catatan,
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

        $n = Nilai::with(['mapel','semester'])->findOrFail($id);

        if ($n->siswa_id !== $siswa->id) {
            return response()->json(['message' => 'Forbidden: this is not your grade'], 403);
        }

        return response()->json([
            'id' => $n->id,
            'mapel' => $n->mapel,
            'semester' => $n->semester,
            'nilai' => $n->nilai,
            'catatan' => $n->catatan,
            'input_by_guru_id' => $n->input_by_guru_id,
            'created_at' => $n->created_at,
            'updated_at' => $n->updated_at,
        ]);
    }
}

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

        $nilaiAll = Nilai::with(['mapel','semester','inputByGuru'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('semester_id')
            ->get();

        return response()->json([
            'siswa' => ['id'=>$siswa->id,'nama'=>$siswa->nama,'kelas_id'=>$siswa->kelas_id],
            'nilai' => $nilaiAll,
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

        $nilai = Nilai::with('mapel','semester','inputByGuru')
            ->where('siswa_id', $siswa->id)
            ->where('semester_id', $semester_id)
            ->get();

        return response()->json([
            'siswa' => ['id'=>$siswa->id,'nama'=>$siswa->nama,'kelas_id'=>$siswa->kelas_id],
            'semester_id' => (int)$semester_id,
            'nilai' => $nilai,
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

        $n = Nilai::with(['mapel','semester','inputByGuru'])->findOrFail($nilai_id);

        if ($n->siswa_id !== (int)$siswa->id) {
            return response()->json(['message'=>'Bad Request: grade not belong to this student'], 400);
        }

        return response()->json($n);
    }
}

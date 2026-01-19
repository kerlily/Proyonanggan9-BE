<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\TahunAjaran;

class NilaiController extends Controller
{
    public function store(Request $request, $kelas_id)
    {
        $data = $request->validate([
            'siswa_id' => ['required','integer','exists:siswa,id'],
            'mapel_id' => ['required','integer','exists:mapel,id'],
            'semester_id' => ['required','integer','exists:semester,id'],
            'nilai' => ['nullable','numeric','between:0,100'],
            'catatan' => ['nullable','string'],
        ]);

        $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();
        if (!$activeTahunAjaran) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        $semester = DB::table('semester')
            ->where('id', $data['semester_id'])
            ->where('tahun_ajaran_id', $activeTahunAjaran->id)
            ->first();

        if (!$semester) {
            return response()->json(['message' => 'Semester tidak sesuai dengan tahun ajaran aktif'], 400);
        }

        // ✅ FIX: Pastikan siswa tidak soft deleted
        $siswa = \App\Models\Siswa::where('id', $data['siswa_id'])
            ->where('kelas_id', $kelas_id)
            ->first();

        if (!$siswa) {
            return response()->json(['message' => 'Siswa not in this class or has been deleted'], 422);
        }

        $kelas = \App\Models\Kelas::findOrFail($kelas_id);
        $mapelExists = $kelas->mapels()->where('mapel_id', $data['mapel_id'])->exists();

        if (!$mapelExists) {
            $mapel = \App\Models\Mapel::find($data['mapel_id']);
            return response()->json([
                'message' => "Mapel '{$mapel->nama}' tidak di-assign ke kelas ini. Silakan assign mapel terlebih dahulu."
            ], 422);
        }

        // ✅ OPSI C: Input manual langsung ke tabel nilai
        DB::table('nilai')->updateOrInsert(
            [
                'siswa_id' => $data['siswa_id'],
                'mapel_id' => $data['mapel_id'],
                'semester_id' => $data['semester_id'],
                'tahun_ajaran_id' => $activeTahunAjaran->id,
            ],
            [
                'nilai' => $data['nilai'],
                'catatan' => $data['catatan'] ?? null, // ✅ Langsung simpan catatan di tabel nilai
                'catatan_source' => 'manual', // ✅ Tandai sebagai input manual
                'input_by_guru_id' => auth()->guard('api')->user()->guru->id ?? null,
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Nilai saved']);
    }

    /**
     * ✅ OPSI C: Update manual langsung di tabel nilai
     */
    public function update(Request $request, $kelas_id, $id)
    {
        $data = $request->validate([
            'nilai' => ['nullable','numeric','between:0,100'],
            'catatan' => ['nullable','string'],
        ]);

        $record = DB::table('nilai')->where('id', $id)->first();
        if (! $record) return response()->json(['message' => 'Not found'], 404);

        // ✅ FIX: Verify siswa is not deleted
        $siswa = \App\Models\Siswa::where('id', $record->siswa_id)
            ->where('kelas_id', $kelas_id)
            ->first();

        if (!$siswa) {
            return response()->json(['message' => 'Siswa not in this class or has been deleted'], 422);
        }

        $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();
        if ($activeTahunAjaran && $record->tahun_ajaran_id != $activeTahunAjaran->id) {
            return response()->json(['message' => 'Cannot update nilai from previous academic year'], 422);
        }

        // ✅ OPSI C: Update langsung di tabel nilai
        DB::table('nilai')->where('id', $id)->update([
            'nilai' => $data['nilai'] ?? $record->nilai,
            'catatan' => $data['catatan'] ?? $record->catatan, // ✅ Update catatan manual
            'catatan_source' => 'manual', // ✅ Tetap manual (jangan overwrite jika generated)
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Nilai updated']);
    }

    /**
     * ✅ Read nilai per kelas
     * Tidak perlu diubah - sudah support catatan
     */
    public function indexByKelas(Request $request, $kelas_id)
    {
        $semesterId = $request->query('semester_id');
        $tahunId = TahunAjaran::where('is_active', true)->value('id');

        // ✅ FIX: Exclude soft deleted siswa
        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->whereNull('deleted_at')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nisn']);

        $query = DB::table('nilai')
            ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
            ->join('mapel', 'nilai.mapel_id', '=', 'mapel.id')
            ->where('siswa.kelas_id', $kelas_id)
            ->whereNull('siswa.deleted_at')
            ->where('nilai.tahun_ajaran_id', $tahunId);

        if ($semesterId) {
            $query->where('nilai.semester_id', $semesterId);
        }

        $nilai = $query->select(
            'nilai.*', // ✅ Sudah include catatan & catatan_source
            'siswa.nama as siswa_nama',
            'siswa.nisn as siswa_nisn',
            'mapel.nama as mapel_nama',
            'mapel.kode as mapel_kode'
        )->get();

        $grouped = $siswaList->map(function($siswa) use ($nilai) {
            return [
                'siswa_id' => $siswa->id,
                'siswa_nama' => $siswa->nama,
                'siswa_nisn' => $siswa->nisn,
                'nilai' => $nilai->where('siswa_id', $siswa->id)->values()
            ];
        });

        return response()->json([
            'kelas_id' => $kelas_id,
            'data' => $grouped
        ]);
    }
}

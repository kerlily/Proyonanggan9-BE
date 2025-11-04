<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StrukturNilaiMapel;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StrukturNilaiMapelController extends Controller
{
    public function index(Request $request, $kelas_id)
    {
        $semesterId = $request->query('semester_id');
        $tahunId = TahunAjaran::where('is_active', true)->value('id');

        $query = StrukturNilaiMapel::with(['mapel', 'semester', 'tahunAjaran', 'createdByGuru'])
            ->where('kelas_id', $kelas_id)
            ->where('tahun_ajaran_id', $tahunId);

        if ($semesterId) {
            $query->where('semester_id', $semesterId);
        }

        return response()->json($query->get());
    }

    public function show($kelas_id, $id)
    {
        $struktur = StrukturNilaiMapel::with(['mapel', 'semester', 'tahunAjaran', 'createdByGuru', 'kelas'])
            ->where('kelas_id', $kelas_id)
            ->findOrFail($id);

        return response()->json($struktur);
    }

    public function store(Request $request, $kelas_id)
    {
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $validated = $request->validate([
            'mapel_id' => 'required|integer|exists:mapel,id',
            'semester_id' => 'required|integer|exists:semester,id',
            'struktur' => 'required|array',
            'struktur.*.lm_key' => 'required|string',
            'struktur.*.lm_label' => 'required|string',
            'struktur.*.kolom' => 'required|array',
            'struktur.*.kolom.*.kolom_key' => 'required|string',
            'struktur.*.kolom.*.kolom_label' => 'required|string',
            'struktur.*.kolom.*.tipe' => 'required|in:formatif,aslim,asas',
        ]);

        $tahunId = TahunAjaran::where('is_active', true)->value('id');
        if (!$tahunId) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        $semester = DB::table('semester')
            ->where('id', $validated['semester_id'])
            ->where('tahun_ajaran_id', $tahunId)
            ->first();

        if (!$semester) {
            return response()->json(['message' => 'Semester tidak sesuai dengan tahun ajaran aktif'], 400);
        }

        try {
            DB::beginTransaction();

            $struktur = StrukturNilaiMapel::create([
                'mapel_id' => $validated['mapel_id'],
                'kelas_id' => $kelas_id,
                'semester_id' => $validated['semester_id'],
                'tahun_ajaran_id' => $tahunId,
                'created_by_guru_id' => $guruId,
                'struktur' => $validated['struktur'],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Struktur nilai berhasil dibuat',
                'data' => $struktur->load(['mapel', 'semester', 'tahunAjaran'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $kelas_id, $id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($id);

        $validated = $request->validate([
            'struktur' => 'required|array',
            'struktur.*.lm_key' => 'required|string',
            'struktur.*.lm_label' => 'required|string',
            'struktur.*.kolom' => 'required|array',
            'struktur.*.kolom.*.kolom_key' => 'required|string',
            'struktur.*.kolom.*.kolom_label' => 'required|string',
            'struktur.*.kolom.*.tipe' => 'required|in:formatif,aslim,asas',
        ]);

        $nilaiDetailCount = DB::table('nilai_detail')
            ->where('struktur_nilai_mapel_id', $struktur->id)
            ->count();

        if ($nilaiDetailCount > 0) {
            return response()->json([
                'message' => 'Tidak bisa mengubah struktur karena sudah ada nilai yang terinput',
                'nilai_count' => $nilaiDetailCount
            ], 422);
        }

        try {
            DB::beginTransaction();

            $struktur->update([
                'struktur' => $validated['struktur'],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Struktur nilai berhasil diupdate',
                'data' => $struktur->load(['mapel', 'semester', 'tahunAjaran'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($kelas_id, $id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($id);

        $nilaiDetailCount = DB::table('nilai_detail')
            ->where('struktur_nilai_mapel_id', $struktur->id)
            ->count();

        if ($nilaiDetailCount > 0) {
            return response()->json([
                'message' => 'Tidak bisa menghapus struktur karena sudah ada nilai yang terinput',
                'nilai_count' => $nilaiDetailCount
            ], 422);
        }

        try {
            DB::beginTransaction();
            $struktur->delete();
            DB::commit();

            return response()->json(['message' => 'Struktur nilai berhasil dihapus']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getByMapel($kelas_id, $mapel_id, $semester_id)
    {
        $tahunId = TahunAjaran::where('is_active', true)->value('id');

        $struktur = StrukturNilaiMapel::with(['mapel', 'semester', 'tahunAjaran', 'createdByGuru'])
            ->where('kelas_id', $kelas_id)
            ->where('mapel_id', $mapel_id)
            ->where('semester_id', $semester_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->first();

        if (!$struktur) {
            return response()->json(['message' => 'Struktur nilai belum dibuat untuk mapel ini'], 404);
        }

        return response()->json($struktur);
    }
}

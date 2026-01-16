<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StrukturNilaiMapel;
use App\Models\TahunAjaran;
use App\Models\Kelas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StrukturNilaiMapelController extends Controller
{
    public function index(Request $request, $kelas_id)
{
    $semesterId = $request->query('semester_id');

    $tahunId = $request->query('tahun_ajaran_id');

    // Kalau tidak ada filter, default ke tahun aktif
    if (!$tahunId) {
        $tahunId = TahunAjaran::where('is_active', true)->value('id');
    }

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

        // Gunakan Validator untuk deep validation
        $validator = Validator::make($request->all(), [
            'mapel_id' => 'required|integer|exists:mapel,id',
            'semester_id' => 'required|integer|exists:semester,id',
            'struktur' => 'required|array',
            'struktur.lingkup_materi' => 'required|array|min:1',
            'struktur.lingkup_materi.*.lm_key' => 'required|string',
            'struktur.lingkup_materi.*.lm_label' => 'required|string',
            'struktur.lingkup_materi.*.formatif' => 'required|array|min:1',
            'struktur.lingkup_materi.*.formatif.*.kolom_key' => 'required|string',
            'struktur.lingkup_materi.*.formatif.*.kolom_label' => 'required|string',
            'struktur.aslim' => 'required|array',
            'struktur.aslim.kolom_key' => 'required|string',
            'struktur.aslim.kolom_label' => 'required|string',
            'struktur.asas' => 'required|array',
            'struktur.asas.kolom_key' => 'required|string',
            'struktur.asas.kolom_label' => 'required|string',
        ]);

        if ($validator->fails()) {
            \Log::error('Store validation failed: ' . json_encode($validator->errors()));
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();
        $strukturData = $validated['struktur'];

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

        // Validasi mapel di-assign ke kelas
        $kelas = Kelas::with('mapels')->findOrFail($kelas_id);
        $mapelExists = $kelas->mapels()->where('mapel_id', $validated['mapel_id'])->exists();

        if (!$mapelExists) {
            $mapel = \App\Models\Mapel::find($validated['mapel_id']);
            return response()->json([
                'message' => "Mapel '{$mapel->nama}' tidak di-assign ke kelas {$kelas->nama}. Silakan assign mapel terlebih dahulu.",
                'available_mapels' => $kelas->mapels->map(fn($m) => [
                    'id' => $m->id,
                    'nama' => $m->nama,
                    'kode' => $m->kode
                ])
            ], 422);
        }

        // Cek duplikasi struktur
        $exists = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->where('mapel_id', $validated['mapel_id'])
            ->where('semester_id', $validated['semester_id'])
            ->where('tahun_ajaran_id', $tahunId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Struktur nilai untuk mapel dan semester ini sudah ada. Gunakan update jika ingin mengubah.'
            ], 422);
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

        // ✅ DEBUG: Lihat data yang diterima
        \Log::info("📥 Data received for update:", $request->all());

        // Gunakan Validator untuk deep validation
        $validator = Validator::make($request->all(), [
            'struktur' => 'required|array',
            'struktur.lingkup_materi' => 'required|array|min:1',
            'struktur.lingkup_materi.*.lm_key' => 'required|string',
            'struktur.lingkup_materi.*.lm_label' => 'required|string',
            'struktur.lingkup_materi.*.formatif' => 'required|array|min:1',
            'struktur.lingkup_materi.*.formatif.*.kolom_key' => 'required|string',
            'struktur.lingkup_materi.*.formatif.*.kolom_label' => 'required|string',
            'struktur.aslim' => 'required|array',
            'struktur.aslim.kolom_key' => 'required|string',
            'struktur.aslim.kolom_label' => 'required|string',
            'struktur.asas' => 'required|array',
            'struktur.asas.kolom_key' => 'required|string',
            'struktur.asas.kolom_label' => 'required|string',
        ]);

        if ($validator->fails()) {
            \Log::error('Update validation failed: ' . json_encode($validator->errors()));
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();
        $strukturData = $validated['struktur'];

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
                'struktur' => $strukturData, // ✅ Gunakan data yang sudah divalidasi
            ]);

            DB::commit();

            \Log::info("✅ Struktur {$id} updated successfully");
            \Log::info("📊 Updated struktur data:", $struktur->fresh()->struktur);

            return response()->json([
                'message' => 'Struktur nilai berhasil diupdate',
                'data' => $struktur->load(['mapel', 'semester', 'tahunAjaran'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("❌ Error updating struktur {$id}: " . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($kelas_id, $id)
{
    $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($id);

    // ✅ PERUBAHAN: Hitung jumlah nilai untuk logging, TAPI tidak block delete
    $nilaiDetailCount = DB::table('nilai_detail')
        ->where('struktur_nilai_mapel_id', $struktur->id)
        ->count();

    try {
        DB::beginTransaction();

        // Log untuk audit
        \Log::info("Deleting struktur {$id} with {$nilaiDetailCount} nilai details", [
            'struktur_id' => $id,
            'kelas_id' => $kelas_id,
            'mapel_id' => $struktur->mapel_id,
            'nilai_count' => $nilaiDetailCount,
            'deleted_by' => Auth::guard('api')->user()->id ?? 'unknown'
        ]);

        $struktur->delete(); // Cascade akan auto-delete nilai_detail

        DB::commit();

        return response()->json([
            'message' => 'Struktur nilai berhasil dihapus',
            'deleted_nilai_count' => $nilaiDetailCount
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error("Error deleting struktur {$id}: " . $e->getMessage());
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }
}

    public function getByMapel($kelas_id, $mapel_id, $semester_id)
    {
        $tahunId = TahunAjaran::where('is_active', true)->value('id');

        // ✅ Validasi mapel di-assign ke kelas
        $kelas = Kelas::with('mapels')->findOrFail($kelas_id);
        $mapelExists = $kelas->mapels()->where('mapel_id', $mapel_id)->exists();

        if (!$mapelExists) {
            return response()->json([
                'message' => 'Mapel tidak di-assign ke kelas ini',
                'available_mapels' => $kelas->mapels->map(fn($m) => [
                    'id' => $m->id,
                    'nama' => $m->nama
                ])
            ], 422);
        }

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

    /**
     * ✅ NEW: Get daftar mapel yang bisa dibuat strukturnya
     */
   public function getAvailableMapels($kelas_id, $semester_id)
{
    // ✅ PERUBAHAN: Support filter tahun dari query parameter
    $tahunId = request()->query('tahun_ajaran_id');

    if (!$tahunId) {
        $tahunId = TahunAjaran::where('is_active', true)->value('id');
    }

        $kelas = Kelas::with('mapels')->findOrFail($kelas_id);

        // Ambil mapel yang sudah punya struktur
        $mapelWithStruktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->where('semester_id', $semester_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->pluck('mapel_id');

        // Filter mapel yang belum punya struktur
        $availableMapels = $kelas->mapels->whereNotIn('id', $mapelWithStruktur);

        return response()->json([
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama
            ],
            'available_mapels' => $availableMapels->map(fn($m) => [
                'id' => $m->id,
                'nama' => $m->nama,
                'kode' => $m->kode
            ]),
            'total' => $availableMapels->count()
        ]);
    }

    /**
 * Get count nilai detail untuk warning sebelum delete
 */
public function getNilaiCount($kelas_id, $id)
{
    $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($id);

    $nilaiDetailCount = DB::table('nilai_detail')
        ->where('struktur_nilai_mapel_id', $struktur->id)
        ->count();

    // Hitung berapa siswa yang sudah punya nilai
    $siswaWithNilai = DB::table('nilai_detail')
        ->where('struktur_nilai_mapel_id', $struktur->id)
        ->distinct('siswa_id')
        ->count();

    return response()->json([
        'struktur_id' => $id,
        'total_nilai_detail' => $nilaiDetailCount,
        'total_siswa_with_nilai' => $siswaWithNilai,
        'has_nilai' => $nilaiDetailCount > 0
    ]);
}
}

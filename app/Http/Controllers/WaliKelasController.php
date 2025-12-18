<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WaliKelas;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class WaliKelasController extends Controller
{
    // list semua assignment (opsional filter tahun)
    public function index(Request $request)
    {
        $tahunId = $request->query('tahun_ajaran_id');
        $q = WaliKelas::with(['guru.user','kelas','tahunAjaran'])
            ->orderBy('kelas_id')
            ->orderByDesc('is_primary'); // primary dulu

        if ($tahunId) $q->where('tahun_ajaran_id', $tahunId);

        return response()->json($q->get());
    }

    // show semua wali untuk kelas tertentu & tahun (default tahun aktif)
    public function showByKelas(Request $request, $kelas_id)
    {
        $tahunId = $request->query('tahun_ajaran_id') ?? TahunAjaran::where('is_active', true)->value('id');

        $records = WaliKelas::with(['guru.user','kelas','tahunAjaran'])
            ->where('kelas_id', $kelas_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->orderByDesc('is_primary') // primary dulu
            ->get();

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No wali assigned for this class/year'], 404);
        }

        return response()->json([
            'primary' => $records->firstWhere('is_primary', true),
            'additional' => $records->where('is_primary', false)->values(),
            'all' => $records
        ]);
    }

    // assign wali kelas (bisa multiple)
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'guru_id' => ['required','integer','exists:guru,id'],
            'kelas_id' => ['required','integer','exists:kelas,id'],
            'tahun_ajaran_id' => ['nullable','integer','exists:tahun_ajaran,id'],
            'is_primary' => ['nullable','boolean'],
        ]);

        $tahunId = $validated['tahun_ajaran_id'] ?? TahunAjaran::where('is_active', true)->value('id');
        if (! $tahunId) {
            return response()->json(['message' => 'No active academic year and no tahun_ajaran_id provided'], 422);
        }

        $isPrimary = $validated['is_primary'] ?? false;

        DB::beginTransaction();
        try {
            // Cek apakah guru sudah di-assign di kelas ini
            $existing = WaliKelas::where('kelas_id', $validated['kelas_id'])
                ->where('tahun_ajaran_id', $tahunId)
                ->where('guru_id', $validated['guru_id'])
                ->first();

            if ($existing) {
                // Update is_primary jika berbeda
                if ($existing->is_primary != $isPrimary) {
                    $existing->is_primary = $isPrimary;
                    $existing->save();
                }

                // Jika set sebagai primary, unset primary lainnya
                if ($isPrimary) {
                    WaliKelas::where('kelas_id', $validated['kelas_id'])
                        ->where('tahun_ajaran_id', $tahunId)
                        ->where('id', '!=', $existing->id)
                        ->update(['is_primary' => false]);
                }

                DB::commit();
                return response()->json([
                    'message' => 'Wali kelas updated successfully',
                    'data' => $existing
                ], 200);
            }

            // Jika akan set sebagai primary, unset primary yang lama
            if ($isPrimary) {
                WaliKelas::where('kelas_id', $validated['kelas_id'])
                    ->where('tahun_ajaran_id', $tahunId)
                    ->update(['is_primary' => false]);
            }

            // Create new assignment
            $wk = WaliKelas::create([
                'guru_id' => $validated['guru_id'],
                'kelas_id' => $validated['kelas_id'],
                'tahun_ajaran_id' => $tahunId,
                'is_primary' => $isPrimary,
            ]);

            DB::commit();
            return response()->json([
                'message' => 'Wali kelas assigned successfully',
                'data' => $wk
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    // unassign - delete record sepenuhnya
    public function unassign(Request $request, $id)
    {
        $wk = WaliKelas::find($id);
        if (! $wk) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Cek apakah ini wali primary
        if ($wk->is_primary) {
            // Cek apakah ada wali tambahan
            $hasAdditional = WaliKelas::where('kelas_id', $wk->kelas_id)
                ->where('tahun_ajaran_id', $wk->tahun_ajaran_id)
                ->where('id', '!=', $wk->id)
                ->exists();

            if ($hasAdditional) {
                return response()->json([
                    'message' => 'Tidak bisa hapus wali utama jika masih ada wali tambahan. Promosikan wali tambahan terlebih dahulu atau hapus semua wali tambahan.'
                ], 422);
            }
        }

        $wk->delete();

        return response()->json(['message' => 'Wali kelas berhasil dihapus'], 200);
    }

    public function showByGuru(Request $request)
    {
        $user = auth()->guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json(['message' => 'Not a guru'], 403);
        }

        $tahunId = $request->query('tahun_ajaran_id')
            ?? TahunAjaran::where('is_active', true)->value('id');

        $waliKelas = WaliKelas::with(['kelas', 'tahunAjaran'])
            ->where('guru_id', $guru->id)
            ->where('tahun_ajaran_id', $tahunId)
            ->orderByDesc('is_primary')
            ->get();

        return response()->json($waliKelas);
    }
}

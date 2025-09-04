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
        $q = WaliKelas::with(['guru.user','kelas','tahunAjaran'])->orderBy('kelas_id');

        if ($tahunId) $q->where('tahun_ajaran_id', $tahunId);

        return response()->json($q->get());
    }

    // show wali untuk kelas tertentu & tahun (default tahun aktif)
    public function showByKelas(Request $request, $kelas_id)
    {
        $tahunId = $request->query('tahun_ajaran_id') ?? TahunAjaran::where('is_active', true)->value('id');

        $record = WaliKelas::with(['guru.user','kelas','tahunAjaran'])
            ->where('kelas_id', $kelas_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->first();

        if (! $record) {
            return response()->json(['message' => 'No wali assigned for this class/year'], 404);
        }

        return response()->json($record);
    }

    // assign or replace wali kelas (admin only)
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'guru_id' => ['required','integer','exists:guru,id'],
            'kelas_id' => ['required','integer','exists:kelas,id'],
            'tahun_ajaran_id' => ['nullable','integer','exists:tahun_ajaran,id'],
        ]);

        $tahunId = $validated['tahun_ajaran_id'] ?? TahunAjaran::where('is_active', true)->value('id');
        if (! $tahunId) {
            return response()->json(['message' => 'No active academic year and no tahun_ajaran_id provided'], 422);
        }

        DB::beginTransaction();
        try {
            // if record exists for kelas+tahun -> update guru_id
            $wk = WaliKelas::where('kelas_id', $validated['kelas_id'])
                ->where('tahun_ajaran_id', $tahunId)
                ->first();

            if ($wk) {
                $wk->guru_id = $validated['guru_id'];
                $wk->save();
                $action = 'updated';
            } else {
                $wk = WaliKelas::create([
                    'guru_id' => $validated['guru_id'],
                    'kelas_id' => $validated['kelas_id'],
                    'tahun_ajaran_id' => $tahunId,
                ]);
                $action = 'created';
            }

            DB::commit();
            return response()->json(['message' => "Wali kelas {$action} successfully", 'data' => $wk], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    // unassign (set guru_id = null) or delete record
    public function unassign(Request $request, $id)
    {
        $wk = WaliKelas::find($id);
        if (! $wk) return response()->json(['message' => 'Not found'], 404);

        // pilihan: set guru_id null
        $wk->guru_id = null;
        $wk->save();

        // atau kalau ingin remove record: $wk->delete();

        return response()->json(['message' => 'Wali unassigned', 'data' => $wk]);
    }
}

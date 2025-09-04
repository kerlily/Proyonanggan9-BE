<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NilaiController extends Controller
{
    // store nilai: body must include siswa_id, mapel_id, nilai, semester_id
    public function store(Request $request, $kelas_id)
    {
        $data = $request->validate([
            'siswa_id' => ['required','integer','exists:siswa,id'],
            'mapel_id' => ['required','integer','exists:mapel,id'],
            'semester_id' => ['required','integer','exists:semester,id'],
            'nilai' => ['nullable','numeric','between:0,100'],
            'catatan' => ['nullable','string'],
        ]);

        // ensure siswa belongs to kelas_id
        $siswa = DB::table('siswa')->where('id', $data['siswa_id'])->first();
        if (! $siswa || $siswa->kelas_id != $kelas_id) {
            return response()->json(['message' => 'Siswa not in this class'], 422);
        }

        // upsert unique (siswa,mapel,semester)
        $id = DB::table('nilai')->updateOrInsert(
            [
                'siswa_id' => $data['siswa_id'],
                'mapel_id' => $data['mapel_id'],
                'semester_id' => $data['semester_id'],
            ],
            [
                'nilai' => $data['nilai'],
                'catatan' => $data['catatan'] ?? null,
                'input_by_guru_id' => auth()->guard('api')->user()->guru->id ?? null,
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Nilai saved']);
    }

    public function update(Request $request, $kelas_id, $id)
    {
        $data = $request->validate([
            'nilai' => ['nullable','numeric','between:0,100'],
            'catatan' => ['nullable','string'],
        ]);

        $record = DB::table('nilai')->where('id', $id)->first();
        if (! $record) return response()->json(['message' => 'Not found'], 404);

        // verify siswa in kelas
        $siswa = DB::table('siswa')->where('id', $record->siswa_id)->first();
        if (! $siswa || $siswa->kelas_id != $kelas_id) {
            return response()->json(['message' => 'Siswa not in this class'], 422);
        }

        DB::table('nilai')->where('id', $id)->update([
            'nilai' => $data['nilai'] ?? $record->nilai,
            'catatan' => $data['catatan'] ?? $record->catatan,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Nilai updated']);
    }
}

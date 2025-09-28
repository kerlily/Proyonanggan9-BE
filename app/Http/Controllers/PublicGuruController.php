<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicGuruController extends Controller
{
    // Daftar semua guru
    public function index()
    {
        $gurus = DB::table('guru')
            ->select('id', 'nama', 'nip', 'photo')
            ->orderBy('nama')
            ->get()
            ->map(function ($g) {
                return [
                    'id'    => $g->id,
                    'nama'  => $g->nama,
                    'nip'   => $g->nip,
                    'photo' => $g->photo ? url('storage/'.$g->photo) : null,
                ];
            });

        return response()->json([
            'gurus' => $gurus,
        ]);
    }

    // Detail satu guru (opsional)
    public function show($id)
    {
        $guru = DB::table('guru')
            ->select('id', 'nama', 'nip', 'photo')
            ->where('id', $id)
            ->first();

        if (!$guru) {
            return response()->json(['message' => 'Guru tidak ditemukan'], 404);
        }

        $guru->photo = $guru->photo ? url('storage/'.$guru->photo) : null;

        return response()->json($guru);
    }
}

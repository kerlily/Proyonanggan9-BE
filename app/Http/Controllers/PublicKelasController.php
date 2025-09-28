<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Kelas;
use App\Models\Siswa;
use Exception;
use Illuminate\Support\Facades\Log;

class PublicKelasController extends Controller
{
    /**
     * GET /api/kelas
     * Mengembalikan daftar semua kelas.
     */
    public function index()
    {
        try {
            // Ambil semua kelas, urutkan sesuai kolom yang umum ada (ubah kalau beda)
            $kelas = Kelas::orderBy('tingkat', 'asc')
                          ->orderBy('nama', 'asc')
                          ->get();

            return response()->json($kelas, Response::HTTP_OK);
        } catch (Exception $e) {
            Log::error('PublicKelasController@index error: '.$e->getMessage());
            return response()->json([
                'message' => 'Gagal mengambil daftar kelas'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/kelas/{kelas_id}/siswa
     * Mengembalikan daftar siswa pada kelas tertentu.
     */
    public function siswaByKelas($kelas_id)
    {
        try {
            // Cek apakah kelas ada
            $kelas = Kelas::find($kelas_id);
            if (! $kelas) {
                return response()->json([
                    'message' => 'Kelas tidak ditemukan'
                ], Response::HTTP_NOT_FOUND);
            }

            // Ambil siswa berdasarkan foreign key kelas_id (sesuaikan nama kolom jika berbeda)
            $siswa = Siswa::where('kelas_id', $kelas_id)
                          ->orderBy('nama', 'asc')
                          ->get();

            return response()->json($siswa, Response::HTTP_OK);
        } catch (Exception $e) {
            Log::error('PublicKelasController@siswaByKelas error: '.$e->getMessage());
            return response()->json([
                'message' => 'Gagal mengambil daftar siswa untuk kelas ini'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

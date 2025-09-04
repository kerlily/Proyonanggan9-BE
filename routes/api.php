<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SiswaAuthController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\BeritaController;
use App\Http\Controllers\WaliKelasController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Semua route API untuk LMS: auth (guru/admin), siswa, nilai, jadwal, berita.
|
*/

/**
 * Guru/Admin auth (email + password)
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);                  // /api/auth/login
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
});

/**
 * Siswa auth (nama + kelas_id + password(tahun_lahir))
 */
Route::prefix('siswa')->group(function () {
    Route::post('login', [SiswaAuthController::class, 'login']);           // /api/siswa/login
    Route::post('logout', [SiswaAuthController::class, 'logout'])->middleware('auth:siswa');
    Route::post('refresh', [SiswaAuthController::class, 'refresh'])->middleware('auth:siswa');
    Route::get('me', [SiswaAuthController::class, 'me'])->middleware('auth:siswa');
});

/**
 * Nilai (hanya wali kelas via middleware wali.kelas boleh menyimpan / update nilai untuk kelasnya)
 */
Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
    Route::post('/kelas/{kelas_id}/nilai', [NilaiController::class, 'store']);         // create / upsert nilai
    Route::put('/kelas/{kelas_id}/nilai/{id}', [NilaiController::class, 'update']);    // update nilai by id
});


Route::middleware(['auth:api','is_admin'])->prefix('admin')->group(function () {
    Route::get('wali-kelas', [WaliKelasController::class, 'index']); // ?tahun_ajaran_id=#
    Route::get('wali-kelas/kelas/{kelas_id}', [WaliKelasController::class, 'showByKelas']);
    Route::post('wali-kelas/assign', [WaliKelasController::class, 'assign']); // body: guru_id, kelas_id, tahun_ajaran_id (opt)
    Route::post('wali-kelas/unassign/{id}', [WaliKelasController::class, 'unassign']); // or DELETE
});

/**
 * Jadwal (public list + CRUD terproteksi untuk wali kelas)
 * - LIST & SHOW public (siapa saja bisa lihat)
 * - CREATE/UPDATE/DELETE hanya oleh wali kelas (auth:api + wali.kelas)
 */
Route::get('/kelas/{kelas_id}/jadwals', [JadwalController::class, 'index']);                 // list jadwal kelas
Route::get('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'show']);             // show single jadwal

Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
    // untuk upload gambar gunakan multipart/form-data
    Route::post('/kelas/{kelas_id}/jadwals', [JadwalController::class, 'store']);            // create (file upload)
    Route::post('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'update']);      // update (file upload supported)
    Route::delete('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'destroy']);   // delete
});

/**
 * Berita (public list + CRUD oleh guru/admin)
 * - Anyone can read published berita
 * - Create/Update/Delete only authenticated users (guru/admin); update/delete only author or admin
 */
Route::get('/beritas', [BeritaController::class, 'index']);
Route::get('/beritas/{id}', [BeritaController::class, 'show']);

Route::middleware(['auth:api','role:admin,guru'])->group(function () {
    Route::post('/beritas', [BeritaController::class, 'store']);
    Route::post('/beritas/{id}', [BeritaController::class, 'update']);
    Route::delete('/beritas/{id}', [BeritaController::class, 'destroy']);
});

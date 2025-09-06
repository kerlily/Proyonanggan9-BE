<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SiswaAuthController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\BeritaController;
use App\Http\Controllers\WaliKelasController;
use App\Http\Controllers\SiswaNilaiController;
use App\Http\Controllers\AdminSiswaNilaiController;
use App\Http\Controllers\AdminAcademicYearController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminResetPasswordController;


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
 * Profil untuk Guru/Admin
 */

Route::middleware(['auth:api'])->group(function () {
    Route::post('/me/profile', [ProfileController::class, 'updateUserProfile']);   // update nama/email/no_hp/photo
    Route::post('/me/password', [ProfileController::class, 'changeUserPassword']); // ganti password
});

/**
 * Profil untuk Siswa
 */
Route::middleware(['auth:siswa'])->group(function () {
    Route::post('/siswa/me/password', [ProfileController::class, 'changeSiswaPassword']); // ganti password
});


/**
 * Nilai (hanya wali kelas via middleware wali.kelas boleh menyimpan / update nilai untuk kelasnya)
 */
Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
    Route::post('/kelas/{kelas_id}/nilai', [NilaiController::class, 'store']);         // create / upsert nilai
    Route::put('/kelas/{kelas_id}/nilai/{id}', [NilaiController::class, 'update']);    // update nilai by id
});

Route::middleware(['auth:api','is_admin'])->prefix('admin')->group(function () {
    // create guru (multipart/form-data)
  Route::post('/guru', [AdminUserController::class, 'createGuru']);
  // create siswa (json)
  Route::post('/siswa', [AdminUserController::class, 'createSiswa']);
    Route::get('wali-kelas', [WaliKelasController::class, 'index']); // ?tahun_ajaran_id=#
    Route::get('wali-kelas/kelas/{kelas_id}', [WaliKelasController::class, 'showByKelas']);
    Route::post('wali-kelas/assign', [WaliKelasController::class, 'assign']); // body: guru_id, kelas_id, tahun_ajaran_id (opt)
    Route::post('wali-kelas/unassign/{id}', [WaliKelasController::class, 'unassign']); // or DELETE
    Route::post('tahun-ajaran/change', [AdminAcademicYearController::class, 'changeYear']); // promote year


Route::post('/guru/{id}/reset-password', [AdminResetPasswordController::class, 'resetUserPassword']);
Route::post('/siswa/{id}/reset-password', [AdminResetPasswordController::class, 'resetSiswaPassword']);
});

/**
 * Jadwal (public list + CRUD terproteksi untuk wali kelas)
 * - LIST & SHOW public (siapa saja bisa lihat)
 * - CREATE/UPDATE/DELETE hanya oleh wali kelas (auth:api + wali.kelas)
 */
Route::middleware(['can.view.jadwal'])->group(function () {
    Route::get('/kelas/{kelas_id}/jadwals', [JadwalController::class, 'index']);
    Route::get('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'show']);
});

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


Route::middleware(['auth:siswa'])->group(function () {
    // semua endpoint hanya bisa diakses siswa yang login (lihat nilai sendiri)
    Route::get('/siswa/me/nilai', [SiswaNilaiController::class, 'index']);
    Route::get('/siswa/me/nilai/semester/{semester_id}', [SiswaNilaiController::class, 'bySemester']);
    Route::get('/siswa/me/nilai/{id}', [SiswaNilaiController::class, 'show']);
});

Route::middleware(['auth:api','role:admin,guru'])->prefix('admin')->group(function () {
    Route::get('/siswa/{siswa_id}/nilai', [AdminSiswaNilaiController::class, 'index']);
    Route::get('/siswa/{siswa_id}/nilai/semester/{semester_id}', [AdminSiswaNilaiController::class, 'bySemester']);
    Route::get('/siswa/{siswa_id}/nilai/{nilai_id}', [AdminSiswaNilaiController::class, 'show']);
});

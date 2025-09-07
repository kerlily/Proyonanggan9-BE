<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAcademicYearController;
use App\Http\Controllers\AdminResetPasswordController;
use App\Http\Controllers\AdminSiswaNilaiController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BeritaController;
use App\Http\Controllers\ImportNilaiController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SiswaAuthController;
use App\Http\Controllers\SiswaNilaiController;
use App\Http\Controllers\TemplateController;
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
 * -------------------------
 * Guru / Admin Authentication
 * (email + password)
 * -------------------------
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']); // /api/auth/login
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
});

/**
 * -------------------------
 * Siswa Authentication
 * (nama + kelas_id + password(tahun_lahir))
 * -------------------------
 */
Route::prefix('siswa')->group(function () {
    Route::post('login', [SiswaAuthController::class, 'login']); // /api/siswa/login
    Route::post('logout', [SiswaAuthController::class, 'logout'])->middleware('auth:siswa');
    Route::post('refresh', [SiswaAuthController::class, 'refresh'])->middleware('auth:siswa');
    Route::get('me', [SiswaAuthController::class, 'me'])->middleware('auth:siswa');
});

/**
 * -------------------------
 * Profil - Guru/Admin
 * (authed: auth:api)
 * -------------------------
 */
Route::middleware(['auth:api'])->group(function () {
    Route::post('/me/profile', [ProfileController::class, 'updateUserProfile']);   // update nama/email/no_hp/photo
    Route::post('/me/password', [ProfileController::class, 'changeUserPassword']); // ganti password
});

/**
 * -------------------------
 * Profil - Siswa
 * (authed: auth:siswa)
 * -------------------------
 */
Route::middleware(['auth:siswa'])->group(function () {
    Route::post('/siswa/me/password', [ProfileController::class, 'changeSiswaPassword']); // ganti password siswa
});

/**
 * -------------------------
 * Nilai
 * - Hanya wali kelas yang boleh menyimpan/update nilai untuk kelasnya
 * - Middleware: auth:api + wali.kelas
 * -------------------------
 */
Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
    Route::post('/kelas/{kelas_id}/nilai', [NilaiController::class, 'store']);         // create / upsert nilai
    Route::put('/kelas/{kelas_id}/nilai/{id}', [NilaiController::class, 'update']);    // update nilai by id
});

/**
 * -------------------------
 * Admin (is_admin)
 * - Create guru/siswa, manage wali-kelas, change academic year, reset password
 * -------------------------
 */
Route::prefix('admin')->middleware(['auth:api', 'is_admin'])->group(function () {
    // Create user endpoints
    Route::post('/guru', [AdminUserController::class, 'createGuru']); // multipart/form-data
    Route::post('/siswa', [AdminUserController::class, 'createSiswa']); // json

    // Wali Kelas management
    Route::get('wali-kelas', [WaliKelasController::class, 'index']); // optional ?tahun_ajaran_id=#
    Route::get('wali-kelas/kelas/{kelas_id}', [WaliKelasController::class, 'showByKelas']);
    Route::post('wali-kelas/assign', [WaliKelasController::class, 'assign']); // body: guru_id, kelas_id, tahun_ajaran_id (opt)
    Route::post('wali-kelas/unassign/{id}', [WaliKelasController::class, 'unassign']); // or DELETE if preferred

    // Academic year
    Route::post('tahun-ajaran/change', [AdminAcademicYearController::class, 'changeYear']); // promote year

    // Reset passwords
    Route::post('/guru/{id}/reset-password', [AdminResetPasswordController::class, 'resetUserPassword']);
    Route::post('/siswa/{id}/reset-password', [AdminResetPasswordController::class, 'resetSiswaPassword']);
});

/**
 * -------------------------
 * Jadwal
 * - Public list & show (middleware can.view.jadwal)
 * - CRUD protected for wali.kelas (auth:api + wali.kelas)
 * -------------------------
 */
// Public access to view jadwals
Route::middleware(['can.view.jadwal'])->group(function () {
    Route::get('/kelas/{kelas_id}/jadwals', [JadwalController::class, 'index']);
    Route::get('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'show']);
});

// Protected CRUD (file upload supported on store & update)
Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
    Route::post('/kelas/{kelas_id}/jadwals', [JadwalController::class, 'store']);          // Create (file upload)
    Route::post('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'update']);    // Update (file upload supported)
    Route::delete('/kelas/{kelas_id}/jadwals/{id}', [JadwalController::class, 'destroy']); // Delete

    // Template download & import nilai
    Route::get('/kelas/{kelas_id}/semester/{semester_id}/download-template', [TemplateController::class, 'downloadTemplate']);
    Route::post('/kelas/{kelas_id}/semester/{semester_id}/import-nilai', [ImportNilaiController::class, 'import']);
});

/**
 * -------------------------
 * Berita
 * - Publik: list & show
 * - CRUD: guru/admin (auth:api + role:admin,guru)
 * -------------------------
 */
Route::get('/beritas', [BeritaController::class, 'index']);
Route::get('/beritas/{id}', [BeritaController::class, 'show']);

Route::middleware(['auth:api', 'role:admin,guru'])->group(function () {
    Route::post('/beritas', [BeritaController::class, 'store']);
    Route::post('/beritas/{id}', [BeritaController::class, 'update']);
    Route::delete('/beritas/{id}', [BeritaController::class, 'destroy']);
});

/**
 * -------------------------
 * Endpoints untuk Siswa (auth:siswa)
 * - siswa hanya bisa lihat nilai sendiri
 * -------------------------
 */
Route::middleware(['auth:siswa'])->group(function () {
    Route::get('/siswa/me/nilai', [SiswaNilaiController::class, 'index']);
    Route::get('/siswa/me/nilai/semester/{semester_id}', [SiswaNilaiController::class, 'bySemester']);
    Route::get('/siswa/me/nilai/{id}', [SiswaNilaiController::class, 'show']);
});

/**
 * -------------------------
 * Admin: melihat nilai siswa (role: admin,guru)
 * -------------------------
 */
Route::prefix('admin')->middleware(['auth:api', 'role:admin,guru'])->group(function () {
    Route::get('/siswa/{siswa_id}/nilai', [AdminSiswaNilaiController::class, 'index']);
    Route::get('/siswa/{siswa_id}/nilai/semester/{semester_id}', [AdminSiswaNilaiController::class, 'bySemester']);
    Route::get('/siswa/{siswa_id}/nilai/{nilai_id}', [AdminSiswaNilaiController::class, 'show']);
});

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
use App\Http\Controllers\PublicGuruController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\PublicKelasController;
use App\Http\Controllers\TahunAjaranController;
use App\Http\Controllers\MapelController;
use App\Http\Controllers\KelasMapelController;
use App\Http\Controllers\StrukturNilaiMapelController;
use App\Http\Controllers\NilaiDetailController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\BackupController;

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
    Route::get('wali-kelas/me', [WaliKelasController::class, 'showByGuru']);

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
    Route::get('/kelas/{kelas_id}/nilaiKelas', [NilaiController::class, 'indexByKelas']);
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
    Route::post('/siswa/{id}', [AdminUserController::class, 'updateSiswa']);
    Route::delete('/siswa/{id}', [AdminUserController::class, 'deleteSiswa']);
    Route::get('/guru/{id}', [AdminUserController::class, 'showGuru']);
    Route::post('/guru/{id}', [AdminUserController::class, 'updateGuru']); // update via POST (you used same pattern for siswa)
    Route::delete('/guru/{id}', [AdminUserController::class, 'deleteGuru']);

    // ===========================
    // CRUD Admin Users
    // ===========================
    Route::get('/admins', [AdminUserController::class, 'indexAdmin']);
    Route::get('/admins/{id}', [AdminUserController::class, 'showAdmin']);
    Route::post('/admins', [AdminUserController::class, 'createAdmin']);
    Route::put('/admins/{id}', [AdminUserController::class, 'updateAdmin']);
    Route::delete('/admins/{id}', [AdminUserController::class, 'deleteAdmin']);
    Route::post('/admins/{id}/reset-password', [AdminUserController::class, 'resetAdminPassword']);



    // Daftar siswa untuk admin (pagination, search by nama/nis, filter kelas, sort)
    Route::get('/siswa', [AdminUserController::class, 'indexSiswa']);

    // Detail siswa
    Route::get('/siswa/{id}', [AdminUserController::class, 'showSiswa']);

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

     // ===========================
    // CRUD Mapel (Master Data)
    // ===========================
    Route::get('/mapel', [MapelController::class, 'index']);           // List dengan pagination & search
    Route::get('/mapel/{id}', [MapelController::class, 'show']);       // Detail mapel
    Route::post('/mapel', [MapelController::class, 'store']);          // Create mapel
    Route::put('/mapel/{id}', [MapelController::class, 'update']);     // Update mapel
    Route::delete('/mapel/{id}', [MapelController::class, 'destroy']); // Delete mapel
    Route::post('/mapel/bulk', [MapelController::class, 'bulkStore']); // Bulk create mapel

    // ===========================
    // Manage Mapel per Kelas
    // ===========================

    // Statistics & Monitoring
    Route::get('/kelas-mapel/statistics', [KelasMapelController::class, 'statistics']); // Dashboard statistik

    // Manage mapel untuk satu kelas
    Route::get('/kelas/{kelas_id}/mapel/available', [KelasMapelController::class, 'available']); // Mapel yang bisa ditambahkan
    Route::post('/kelas/{kelas_id}/mapel', [KelasMapelController::class, 'assign']);              // Assign multiple mapel (replace)
    Route::post('/kelas/{kelas_id}/mapel/{mapel_id}', [KelasMapelController::class, 'attach']);   // Tambah 1 mapel
    Route::delete('/kelas/{kelas_id}/mapel/{mapel_id}', [KelasMapelController::class, 'detach']); // Hapus 1 mapel

    // Copy & Bulk operations
    Route::post('/kelas/{kelas_id}/mapel/copy-from/{source_kelas_id}', [KelasMapelController::class, 'copyFrom']); // Copy dari kelas lain
    Route::post('/kelas-mapel/bulk-assign', [KelasMapelController::class, 'bulkAssign']); // Assign ke multiple kelas sekaligus

    // Get all activity logs dengan filter
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);

    // Get detail activity log
    Route::get('/activity-logs/{id}', [ActivityLogController::class, 'show']);

    // Get statistics
    Route::get('/activity-logs/stats', [ActivityLogController::class, 'stats']);

    // Get activity logs by user
    Route::get('/activity-logs/user/{userId}', [ActivityLogController::class, 'userActivity']);
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
    Route::get('/public/guru', [PublicGuruController::class, 'index']);
    Route::get('/public/guru/{id}', [PublicGuruController::class, 'show']);
    Route::get('/galleries', [GalleryController::class, 'index']);
    Route::get('/galleries/{id}', [GalleryController::class, 'show']);
    Route::get('/mapel/all', [MapelController::class, 'all']);

    // Public endpoints untuk frontend (dropdown kelas & daftar siswa per kelas)
    Route::get('/kelas', [PublicKelasController::class, 'index']);
    Route::get('/kelas/{kelas_id}/siswa', [PublicKelasController::class, 'siswaByKelas']);


Route::middleware(['auth:api', 'role:admin,guru'])->group(function () {
    Route::post('/beritas', [BeritaController::class, 'store']);
    Route::post('/beritas/{id}', [BeritaController::class, 'update']);
    Route::delete('/beritas/{id}', [BeritaController::class, 'destroy']);
    Route::post('/galleries', [GalleryController::class, 'store']);
    Route::post('/galleries/{id}', [GalleryController::class, 'update']); // or use PUT/PATCH
    Route::delete('/galleries/{id}', [GalleryController::class, 'destroy']);

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


/**
 * -------------------------
 * Tahun Ajaran - Public (semua user bisa akses)
 * -------------------------
 */
// Get tahun ajaran aktif - bisa diakses tanpa auth atau dengan auth
Route::get('/tahun-ajaran/active', [TahunAjaranController::class, 'getActive']);
Route::get('/tahun-ajaran/{id}/semester', [TahunAjaranController::class, 'getSemestersByTahunAjaran']);

/**
 * -------------------------
 * Tahun Ajaran - Admin Only
 * -------------------------
 */
Route::prefix('admin')->middleware(['auth:api', 'is_admin'])->group(function () {
    // CRUD tahun ajaran
    Route::get('/tahun-ajaran', [TahunAjaranController::class, 'index']);
    Route::get('/tahun-ajaran/{id}', [TahunAjaranController::class, 'show']);
    Route::post('/tahun-ajaran', [TahunAjaranController::class, 'store']);
    Route::put('/tahun-ajaran/{id}', [TahunAjaranController::class, 'update']);
    Route::delete('/tahun-ajaran/{id}', [TahunAjaranController::class, 'destroy']);

    // Toggle semester aktif
    Route::post('/semester/{id}/toggle-active', [TahunAjaranController::class, 'toggleSemester']);

    Route::get('/guru', [AdminUserController::class, 'indexGuru']);
});

Route::middleware(['auth:api', 'is_admin_or_guru'])->group(function () {
    Route::prefix('kelas/{kelas_id}')->group(function () {

        Route::get('struktur-nilai', [StrukturNilaiMapelController::class, 'index']);
        Route::post('struktur-nilai', [StrukturNilaiMapelController::class, 'store']);
        Route::get('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'show']);
        Route::put('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'update']);
        Route::delete('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'destroy']);
        Route::get('struktur-nilai/mapel/{mapel_id}/semester/{semester_id}', [StrukturNilaiMapelController::class, 'getByMapel']);

        Route::get('struktur-nilai/{struktur_id}/nilai-detail', [NilaiDetailController::class, 'index']);
        Route::post('struktur-nilai/{struktur_id}/nilai-detail/bulk', [NilaiDetailController::class, 'storeBulk']);
        Route::post('struktur-nilai/{struktur_id}/generate-nilai-akhir', [NilaiDetailController::class, 'generateNilaiAkhir']);
        Route::get('struktur-nilai/{struktur_id}/siswa/{siswa_id}', [NilaiDetailController::class, 'getSiswaDetail']);
    });
});

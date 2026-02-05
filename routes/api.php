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
use App\Http\Controllers\NilaiSikapController;
use App\Http\Controllers\KetidakhadiranController;
use App\Http\Controllers\NilaiMonitoringController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\CatatanAkademikController;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('check-token', [AuthController::class, 'checkToken']);
    });
});

/**
 * -------------------------
 * Siswa Authentication
 * (nama + kelas_id + password(tahun_lahir))
 * -------------------------
 */
Route::prefix('siswa')->group(function () {
    Route::post('login', [SiswaAuthController::class, 'login']);
    Route::middleware('auth:siswa')->group(function () {
        Route::post('logout', [SiswaAuthController::class, 'logout']);
        Route::post('refresh', [SiswaAuthController::class, 'refresh']);
        Route::get('me', [SiswaAuthController::class, 'me']);
        Route::get('check-token', [SiswaAuthController::class, 'checkToken']);
    });
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

     Route::get('/beritas/all', [BeritaController::class, 'all']);


    Route::get('wali-kelas/nilai-detail/history', [WaliKelasController::class, 'getNilaiDetailHistory']);
    Route::get('wali-kelas/nilai-history', [WaliKelasController::class, 'getNilaiHistory']);
    Route::get('wali-kelas/nilai-history/detail', [WaliKelasController::class, 'getNilaiHistoryDetail']);
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
    Route::post('/kelas/{kelas_id}/nilai', [NilaiController::class, 'store']);
    Route::put('/kelas/{kelas_id}/nilai/{id}', [NilaiController::class, 'update']);
    Route::get('/kelas/{kelas_id}/nilaiKelas', [NilaiController::class, 'indexByKelas']);

     // ===========================
    // NILAI SIKAP
    // ===========================

    // Get semua nilai sikap di kelas (per semester)
    Route::get('/kelas/{kelas_id}/nilai-sikap', [NilaiSikapController::class, 'index']);

    // Store/Update satu nilai sikap siswa
    Route::post('/kelas/{kelas_id}/nilai-sikap', [NilaiSikapController::class, 'store']);

    // Bulk store nilai sikap untuk banyak siswa
    Route::post('/kelas/{kelas_id}/nilai-sikap/bulk', [NilaiSikapController::class, 'bulkStore']);

    // Delete nilai sikap
    Route::delete('/kelas/{kelas_id}/nilai-sikap/{id}', [NilaiSikapController::class, 'destroy']);

    // ===========================
    // KETIDAKHADIRAN
    // ===========================

    // Get semua ketidakhadiran di kelas (per semester)
    Route::get('/kelas/{kelas_id}/ketidakhadiran', [KetidakhadiranController::class, 'index']);

    // Store/Update satu ketidakhadiran siswa
    Route::post('/kelas/{kelas_id}/ketidakhadiran', [KetidakhadiranController::class, 'store']);

    // Bulk store ketidakhadiran untuk banyak siswa
    Route::post('/kelas/{kelas_id}/ketidakhadiran/bulk', [KetidakhadiranController::class, 'bulkStore']);

    // Increment ketidakhadiran (tambah 1 ijin/sakit/alpa)
    Route::post('/kelas/{kelas_id}/ketidakhadiran/{siswa_id}/increment', [KetidakhadiranController::class, 'increment']);

    // Delete ketidakhadiran
    Route::delete('/kelas/{kelas_id}/ketidakhadiran/{id}', [KetidakhadiranController::class, 'destroy']);
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
    Route::post('/guru/{id}', [AdminUserController::class, 'updateGuru']);
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

    // Get statistics
    Route::get('/activity-logs/stats', [ActivityLogController::class, 'stats']);

    // Get detail activity log
    Route::get('/activity-logs/{id}', [ActivityLogController::class, 'show']);

    // Get activity logs by user
    Route::get('/activity-logs/user/{userId}', [ActivityLogController::class, 'userActivity']);

    // MONITORING NILAI AKHIR
    Route::get('/nilai-akhir/monitoring', [NilaiMonitoringController::class, 'monitoring']);
    Route::get('/nilai-akhir/monitoring/kelas/{kelas_id}/missing', [NilaiMonitoringController::class, 'missingDetail']);
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
   Route::get('/kelas/{kelas_id}/jadwal', [JadwalController::class, 'index']);
});

// Protected CRUD (file upload supported on store & update)
Route::middleware(['auth:api', 'wali.kelas'])->group(function () {
     Route::post('/kelas/{kelas_id}/jadwal', [JadwalController::class, 'store']);
    Route::put('/kelas/{kelas_id}/jadwal/{id}', [JadwalController::class, 'update']);
    Route::delete('/kelas/{kelas_id}/jadwal/{id}', [JadwalController::class, 'destroy']);


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


    // Route public untuk melihat mapel per kelas (bisa diakses tanpa auth)
    Route::get('kelas/{kelas_id}/mapel', [KelasMapelController::class, 'index']);



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
    Route::get('/siswa/me/nilai-sikap', [NilaiSikapController::class, 'siswaNilaiSikap']);
    Route::get('/siswa/me/ketidakhadiran', [KetidakhadiranController::class, 'siswaKetidakhadiran']);
    Route::get('/siswa/me/catatan-akademik', [CatatanAkademikController::class, 'siswaCatatanAkademik']);
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

    // Get nilai sikap siswa tertentu (semua semester)
    Route::get('/siswa/{siswa_id}/nilai-sikap', function($siswa_id) {
        $siswa = \App\Models\Siswa::findOrFail($siswa_id);
        $nilaiSikap = \App\Models\NilaiSikap::with(['semester.tahunAjaran', 'inputByGuru'])
            ->where('siswa_id', $siswa_id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->get();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas' => $siswa->kelas ? $siswa->kelas->nama : null,
            ],
            'data' => $nilaiSikap
        ]);
    });

    // Get ketidakhadiran siswa tertentu (semua semester)
    Route::get('/siswa/{siswa_id}/ketidakhadiran', function($siswa_id) {
        $siswa = \App\Models\Siswa::findOrFail($siswa_id);
        $ketidakhadiran = \App\Models\Ketidakhadiran::with(['semester.tahunAjaran', 'inputByGuru'])
            ->where('siswa_id', $siswa_id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->get();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas' => $siswa->kelas ? $siswa->kelas->nama : null,
            ],
            'data' => $ketidakhadiran
        ]);
    });
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


/**
 * ========================================
 * TRASH MANAGEMENT (Soft Delete)
 * Admin only - untuk manage data yang sudah dihapus
 * ========================================
 */
Route::prefix('admin/trash')->middleware(['auth:api', 'is_admin'])->group(function () {

    // Dashboard trash
    Route::get('/stats', [TrashController::class, 'stats']);

    // Bulk operations
    Route::post('/bulk-restore', [TrashController::class, 'bulkRestore']);

    // ===========================
    // USERS TRASH
    // ===========================
    Route::get('/users', [TrashController::class, 'indexUsers']);
    Route::post('/users/{id}/restore', [TrashController::class, 'restoreUser']);
    Route::delete('/users/{id}/force', [TrashController::class, 'forceDeleteUser']);

    // ===========================
    // SISWA TRASH
    // ===========================
    Route::get('/siswa', [TrashController::class, 'indexSiswa']);
    Route::post('/siswa/{id}/restore', [TrashController::class, 'restoreSiswa']);
    Route::delete('/siswa/{id}/force', [TrashController::class, 'forceDeleteSiswa']);

    // ===========================
    // KELAS TRASH
    // ===========================
    Route::get('/kelas', [TrashController::class, 'indexKelas']);
    Route::post('/kelas/{id}/restore', [TrashController::class, 'restoreKelas']);
    Route::delete('/kelas/{id}/force', [TrashController::class, 'forceDeleteKelas']);
});

Route::middleware(['auth:api', 'is_admin_or_guru'])->group(function () {


  Route::get(
        '/kelas/{kelas_id}/struktur-nilai/available-for-catatan',
        [CatatanAkademikController::class, 'getAvailableStruktur']
    );

    // Get existing catatan (support struktur_id atau mapel_id)
    Route::get(
        '/kelas/{kelas_id}/catatan-akademik',
        [CatatanAkademikController::class, 'index']
    );

    // Bulk save catatan (support dengan/tanpa struktur)
    Route::post(
        '/kelas/{kelas_id}/catatan-akademik/bulk',
        [CatatanAkademikController::class, 'bulkStore']
    );

    // Save single catatan
    Route::post(
        '/kelas/{kelas_id}/struktur-nilai/{struktur_id}/catatan/single',
        [CatatanAkademikController::class, 'storeSingle']
    );

    // Delete catatan
    Route::delete(
        '/kelas/{kelas_id}/struktur-nilai/{struktur_id}/catatan/{siswa_id}',
        [CatatanAkademikController::class, 'destroy']
    );

    // Get catatan siswa untuk rapor (admin/guru)
    Route::get(
        '/siswa/{siswa_id}/catatan-akademik',
        [CatatanAkademikController::class, 'getBySiswa']
    );

    Route::post('/beritas', [BeritaController::class, 'store']);
    Route::post('/beritas/{id}', [BeritaController::class, 'update']);
    Route::put('/beritas/{id}', [BeritaController::class, 'update']);
    Route::delete('/beritas/{id}', [BeritaController::class, 'destroy']);
    Route::post('/galleries', [GalleryController::class, 'store']);
    Route::put('/galleries/{id}', [GalleryController::class, 'update']);
    Route::delete('/galleries/{id}', [GalleryController::class, 'destroy']);

    Route::prefix('kelas/{kelas_id}')->group(function () {

        Route::get('struktur-nilai', [StrukturNilaiMapelController::class, 'index']);
        Route::post('struktur-nilai', [StrukturNilaiMapelController::class, 'store']);
        Route::get('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'show']);
        Route::put('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'update']);
        Route::delete('struktur-nilai/{id}', [StrukturNilaiMapelController::class, 'destroy']);
        Route::get('struktur-nilai/mapel/{mapel_id}/semester/{semester_id}', [StrukturNilaiMapelController::class, 'getByMapel']);

        Route::get('semester/{semester_id}/available-mapels', [StrukturNilaiMapelController::class, 'getAvailableMapels']);
        Route::get('struktur-nilai/{id}/nilai-count', [StrukturNilaiMapelController::class, 'getNilaiCount']);

        Route::post('struktur-nilai/{struktur_id}/nilai-detail/single', [NilaiDetailController::class, 'storeSingle']);
        Route::post('struktur-nilai/{struktur_id}/nilai-detail/bulk', [NilaiDetailController::class, 'storeBulk']);
        Route::get('struktur-nilai/{struktur_id}/nilai-detail', [NilaiDetailController::class, 'index']);
        Route::get('struktur-nilai/{struktur_id}/progress', [NilaiDetailController::class, 'getProgress']);
        Route::post('struktur-nilai/{struktur_id}/generate-nilai-akhir', [NilaiDetailController::class, 'generateNilaiAkhir']);
        Route::get('struktur-nilai/{struktur_id}/siswa/{siswa_id}', [NilaiDetailController::class, 'getSiswaDetail']);
    });

     Route::get('kelas/{kelas_id}/mapel', [KelasMapelController::class, 'index']);

});

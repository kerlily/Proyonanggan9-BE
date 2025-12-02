<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JadwalTemplate;
use App\Models\JadwalSlot;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class JadwalController extends Controller
{
    /**
     * Get jadwal untuk kelas (public view)
     * GET /api/kelas/{kelas_id}/jadwal?semester_id=1&tahun_ajaran_id=1
     *
     * Jika tidak ada query params, ambil semester & tahun ajaran yang aktif
     */
    public function index(Request $request, $kelas_id)
    {
        try {
            // Check authorization
            if (!$this->canViewJadwal($kelas_id)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Get semester & tahun ajaran (default: yang aktif)
            $semesterId = $request->query('semester_id');
            $tahunAjaranId = $request->query('tahun_ajaran_id');

            if (!$semesterId || !$tahunAjaranId) {
                $tahunAktif = TahunAjaran::where('is_active', true)->first();
                if (!$tahunAktif) {
                    return response()->json([
                        'message' => 'Tidak ada tahun ajaran aktif',
                        'data' => null
                    ], 404);
                }

                $semesterAktif = Semester::where('tahun_ajaran_id', $tahunAktif->id)
                    ->where('is_active', true)
                    ->first();

                $tahunAjaranId = $tahunAktif->id;
                $semesterId = $semesterAktif ? $semesterAktif->id : null;
            }

            if (!$semesterId) {
                return response()->json([
                    'message' => 'Tidak ada semester aktif',
                    'data' => null
                ], 404);
            }

            // Get jadwal template
            $jadwal = JadwalTemplate::with(['slots.mapel', 'kelas', 'semester', 'tahunAjaran'])
                ->where('kelas_id', $kelas_id)
                ->where('semester_id', $semesterId)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->first();

            if (!$jadwal) {
                return response()->json([
                    'message' => 'Jadwal belum dibuat untuk kelas ini',
                    'data' => null
                ], 404);
            }

            // Group slots by hari
            $slotsByHari = $jadwal->slots->groupBy('hari');

            return response()->json([
                'message' => 'Success',
                'jadwal' => [
                    'id' => $jadwal->id,
                    'nama' => $jadwal->nama,
                    'kelas' => [
                        'id' => $jadwal->kelas->id,
                        'nama' => $jadwal->kelas->nama,
                    ],
                    'semester' => [
                        'id' => $jadwal->semester->id,
                        'nama' => $jadwal->semester->nama,
                    ],
                    'tahun_ajaran' => [
                        'id' => $jadwal->tahunAjaran->id,
                        'nama' => $jadwal->tahunAjaran->nama,
                    ],
                    'is_active' => $jadwal->is_active,
                ],
                'slots_by_hari' => $slotsByHari,
                'all_slots' => $jadwal->slots,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting jadwal: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create atau replace jadwal template untuk kelas
     * POST /api/kelas/{kelas_id}/jadwal
     *
     * Body: {
     *   "semester_id": 1,
     *   "tahun_ajaran_id": 1,
     *   "nama": "Jadwal Semester Ganjil 2024/2025",
     *   "slots": [
     *     {
     *       "hari": "senin",
     *       "jam_mulai": "07:00",
     *       "jam_selesai": "07:45",
     *       "tipe_slot": "pelajaran",
     *       "mapel_id": 1,
     *       "urutan": 1
     *     },
     *     {
     *       "hari": "senin",
     *       "jam_mulai": "07:45",
     *       "jam_selesai": "08:30",
     *       "tipe_slot": "istirahat",
     *       "keterangan": "Istirahat",
     *       "urutan": 2
     *     }
     *   ]
     * }
     */
    public function store(Request $request, $kelas_id)
    {
        $validated = $request->validate([
            'semester_id' => 'nullable|exists:semester,id',
            'tahun_ajaran_id' => 'nullable|exists:tahun_ajaran,id',
            'nama' => 'nullable|string|max:255',
            'slots' => 'required|array|min:1',
            'slots.*.hari' => 'required|in:senin,selasa,rabu,kamis,jumat,sabtu',
            'slots.*.jam_mulai' => 'required|date_format:H:i',
            'slots.*.jam_selesai' => 'required|date_format:H:i|after:slots.*.jam_mulai',
            'slots.*.tipe_slot' => 'required|in:pelajaran,istirahat',
            'slots.*.mapel_id' => 'required_if:slots.*.tipe_slot,pelajaran|nullable|exists:mapel,id',
            'slots.*.keterangan' => 'required_if:slots.*.tipe_slot,istirahat|nullable|string|max:255',
            'slots.*.urutan' => 'required|integer|min:1',
        ]);

        // Ensure kelas exists
        Kelas::findOrFail($kelas_id);

        // Get semester & tahun ajaran (default: yang aktif)
        $semesterId = $validated['semester_id'] ?? null;
        $tahunAjaranId = $validated['tahun_ajaran_id'] ?? null;

        if (!$semesterId || !$tahunAjaranId) {
            $tahunAktif = TahunAjaran::where('is_active', true)->first();
            if (!$tahunAktif) {
                return response()->json([
                    'message' => 'Tidak ada tahun ajaran aktif dan tidak ada tahun_ajaran_id/semester_id yang diberikan'
                ], 422);
            }

            $semesterAktif = Semester::where('tahun_ajaran_id', $tahunAktif->id)
                ->where('is_active', true)
                ->first();

            $tahunAjaranId = $tahunAktif->id;
            $semesterId = $semesterAktif ? $semesterAktif->id : null;
        }

        if (!$semesterId) {
            return response()->json([
                'message' => 'Tidak ada semester aktif'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if jadwal already exists for this kelas+semester+tahun
            $existingJadwal = JadwalTemplate::where('kelas_id', $kelas_id)
                ->where('semester_id', $semesterId)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->first();

            if ($existingJadwal) {
                // Delete existing jadwal & slots
                $existingJadwal->slots()->delete();
                $existingJadwal->delete();
            }

            // Create new jadwal template
            $jadwal = JadwalTemplate::create([
                'kelas_id' => $kelas_id,
                'semester_id' => $semesterId,
                'tahun_ajaran_id' => $tahunAjaranId,
                'nama' => $validated['nama'] ?? null,
                'is_active' => true,
            ]);

            // Create slots
            foreach ($validated['slots'] as $slotData) {
                JadwalSlot::create([
                    'jadwal_template_id' => $jadwal->id,
                    'hari' => $slotData['hari'],
                    'jam_mulai' => $slotData['jam_mulai'],
                    'jam_selesai' => $slotData['jam_selesai'],
                    'tipe_slot' => $slotData['tipe_slot'],
                    'mapel_id' => $slotData['tipe_slot'] === 'pelajaran' ? $slotData['mapel_id'] : null,
                    'keterangan' => $slotData['tipe_slot'] === 'istirahat' ? $slotData['keterangan'] : null,
                    'urutan' => $slotData['urutan'],
                ]);
            }

            DB::commit();

            Log::info('Jadwal created/updated', [
                'kelas_id' => $kelas_id,
                'jadwal_id' => $jadwal->id,
                'total_slots' => count($validated['slots']),
            ]);

            return response()->json([
                'message' => 'Jadwal berhasil dibuat/diupdate',
                'jadwal' => $jadwal->load(['slots.mapel', 'kelas', 'semester', 'tahunAjaran'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating jadwal: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update jadwal (replace all slots)
     * PUT /api/kelas/{kelas_id}/jadwal/{id}
     */
    public function update(Request $request, $kelas_id, $id)
    {
        $jadwal = JadwalTemplate::where('kelas_id', $kelas_id)->findOrFail($id);

        $validated = $request->validate([
            'nama' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'slots' => 'required|array|min:1',
            'slots.*.hari' => 'required|in:senin,selasa,rabu,kamis,jumat,sabtu',
            'slots.*.jam_mulai' => 'required|date_format:H:i',
            'slots.*.jam_selesai' => 'required|date_format:H:i|after:slots.*.jam_mulai',
            'slots.*.tipe_slot' => 'required|in:pelajaran,istirahat',
            'slots.*.mapel_id' => 'required_if:slots.*.tipe_slot,pelajaran|nullable|exists:mapel,id',
            'slots.*.keterangan' => 'required_if:slots.*.tipe_slot,istirahat|nullable|string|max:255',
            'slots.*.urutan' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            // Update jadwal template
            $jadwal->nama = $validated['nama'] ?? $jadwal->nama;
            if (isset($validated['is_active'])) {
                $jadwal->is_active = $validated['is_active'];
            }
            $jadwal->save();

            // Delete old slots
            $jadwal->slots()->delete();

            // Create new slots
            foreach ($validated['slots'] as $slotData) {
                JadwalSlot::create([
                    'jadwal_template_id' => $jadwal->id,
                    'hari' => $slotData['hari'],
                    'jam_mulai' => $slotData['jam_mulai'],
                    'jam_selesai' => $slotData['jam_selesai'],
                    'tipe_slot' => $slotData['tipe_slot'],
                    'mapel_id' => $slotData['tipe_slot'] === 'pelajaran' ? $slotData['mapel_id'] : null,
                    'keterangan' => $slotData['tipe_slot'] === 'istirahat' ? $slotData['keterangan'] : null,
                    'urutan' => $slotData['urutan'],
                ]);
            }

            DB::commit();

            Log::info('Jadwal updated', [
                'kelas_id' => $kelas_id,
                'jadwal_id' => $jadwal->id,
                'total_slots' => count($validated['slots']),
            ]);

            return response()->json([
                'message' => 'Jadwal berhasil diupdate',
                'jadwal' => $jadwal->load(['slots.mapel', 'kelas', 'semester', 'tahunAjaran'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating jadwal: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete jadwal
     * DELETE /api/kelas/{kelas_id}/jadwal/{id}
     */
    public function destroy($kelas_id, $id)
    {
        $jadwal = JadwalTemplate::where('kelas_id', $kelas_id)->findOrFail($id);

        try {
            DB::beginTransaction();

            // Slots akan terhapus otomatis karena cascadeOnDelete
            $jadwal->delete();

            DB::commit();

            Log::info('Jadwal deleted', [
                'kelas_id' => $kelas_id,
                'jadwal_id' => $id,
            ]);

            return response()->json([
                'message' => 'Jadwal berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting jadwal: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Check if user can view jadwal
     */
    private function canViewJadwal($kelas_id)
    {
        // Admin/Guru API
        if (Auth::guard('api')->check()) {
            $user = Auth::guard('api')->user();
            if ($user && in_array($user->role, ['admin', 'guru'])) {
                return true;
            }
        }

        // Siswa
        if (Auth::guard('siswa')->check()) {
            $siswa = Auth::guard('siswa')->user();
            if ((int)$siswa->kelas_id === (int)$kelas_id) {
                return true;
            }
        }

        return false;
    }
}

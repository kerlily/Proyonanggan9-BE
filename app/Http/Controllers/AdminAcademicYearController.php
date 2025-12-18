<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TahunAjaran;
use App\Models\Semester;
use App\Models\Siswa;
use App\Models\Kelas;
use App\Models\RiwayatKelas;
use App\Models\WaliKelas;

class AdminAcademicYearController extends Controller
{
    public function changeYear(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable','string','max:100'],
            'repeat_student_ids' => ['nullable','array'],
            'repeat_student_ids.*' => ['integer','exists:siswa,id'],
            'copy_wali' => ['nullable','boolean'],
        ]);

        $repeatIds = $validated['repeat_student_ids'] ?? [];
        $copyWali = (bool) ($validated['copy_wali'] ?? false);
        $name = $validated['name'] ?? (date('Y') . '/' . (date('Y') + 1));
        $dry = $request->query('dry_run') == '1' || $request->input('dry_run') == true;

        $summary = [
            'promoted' => 0,
            'repeated' => 0,
            'graduated' => 0,
            'no_class_assigned_skipped' => 0,
            'copied_wali_count' => 0,
        ];

        $details = [
            'promote_list' => [],
            'copy_wali_list' => [],
        ];

        if (! $dry) {
            DB::beginTransaction();
        }

        try {
            // 1) Deactivate current active year
            $current = TahunAjaran::where('is_active', true)->first();

            if (! $dry) {
                if ($current) {
                    $current->is_active = false;
                    $current->save();
                }
            }

            // 2) Create new tahun ajaran
            if ($dry) {
                $newYear = new TahunAjaran(['nama' => $name, 'is_active' => true]);
            } else {
                $newYear = TahunAjaran::create([
                    'nama' => $name,
                    'is_active' => true,
                ]);
            }

            // 3) Create 2 semesters
            if ($dry) {
                $semGanjil = new Semester(['tahun_ajaran_id' => $newYear->id ?? null, 'nama' => 'ganjil', 'is_active' => true]);
                $semGenap = new Semester(['tahun_ajaran_id' => $newYear->id ?? null, 'nama' => 'genap', 'is_active' => false]);
            } else {
                $semGanjil = Semester::create([
                    'tahun_ajaran_id' => $newYear->id,
                    'nama' => 'ganjil',
                    'is_active' => true,
                ]);
                $semGenap = Semester::create([
                    'tahun_ajaran_id' => $newYear->id,
                    'nama' => 'genap',
                    'is_active' => false,
                ]);
            }

            // 4) Copy wali_kelas - FIXED untuk handle multiple wali per kelas
            $copiedWaliCount = 0;
            if ($copyWali && $current) {
                // Get SEMUA wali dari tahun ajaran sebelumnya
                $prevWalis = WaliKelas::where('tahun_ajaran_id', $current->id)->get();

                foreach ($prevWalis as $wk) {
                    if ($dry) {
                        $details['copy_wali_list'][] = [
                            'kelas_id' => $wk->kelas_id,
                            'kelas_name' => optional(Kelas::find($wk->kelas_id))->nama,
                            'guru_id' => $wk->guru_id,
                            'is_primary' => $wk->is_primary,
                        ];
                        $copiedWaliCount++;
                    } else {
                        // Cek apakah kombinasi guru + kelas sudah ada di tahun baru
                        $exists = WaliKelas::where('guru_id', $wk->guru_id)
                            ->where('kelas_id', $wk->kelas_id)
                            ->where('tahun_ajaran_id', $newYear->id)
                            ->exists();

                        // Hanya create jika belum ada (mencegah duplikasi)
                        if (!$exists) {
                            WaliKelas::create([
                                'guru_id' => $wk->guru_id,
                                'kelas_id' => $wk->kelas_id,
                                'tahun_ajaran_id' => $newYear->id,
                                'is_primary' => $wk->is_primary,
                            ]);
                            $copiedWaliCount++;
                        }
                    }
                }
            }
            $summary['copied_wali_count'] = $copiedWaliCount;

            // 5) Promote/repeat logic
            $siswaList = Siswa::where('is_alumni', false)->get();

            foreach ($siswaList as $siswa) {
                $fromKelas = $siswa->kelas_id ? Kelas::find($siswa->kelas_id) : null;

                // Repeat logic
                if (in_array($siswa->id, $repeatIds, true)) {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => $fromKelas ? ['id'=>$fromKelas->id,'nama'=>$fromKelas->nama,'tingkat'=>$fromKelas->tingkat,'section'=>$fromKelas->section] : null,
                        'to_kelas' => $fromKelas ? ['id'=>$fromKelas->id,'nama'=>$fromKelas->nama,'tingkat'=>$fromKelas->tingkat,'section'=>$fromKelas->section] : null,
                        'action' => 'repeat',
                    ];

                    if (! $dry) {
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $siswa->kelas_id ?? null]
                        );
                        if ($siswa->is_alumni) {
                            $siswa->is_alumni = false;
                            $siswa->save();
                        }
                    }

                    $summary['repeated']++;
                    continue;
                }

                // No class assigned
                if (! $siswa->kelas_id) {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => null,
                        'to_kelas' => null,
                        'action' => 'no_class_assigned',
                    ];
                    $summary['no_class_assigned_skipped']++;

                    if (! $dry) {
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => null]
                        );
                    }
                    continue;
                }

                $kelas = $fromKelas;
                if (! $kelas) {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => null,
                        'to_kelas' => null,
                        'action' => 'kelas_not_found',
                    ];
                    $summary['no_class_assigned_skipped']++;
                    if (! $dry) {
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => null]
                        );
                    }
                    continue;
                }

                $currentTingkat = (int) $kelas->tingkat;

                // Graduate logic
                if ($currentTingkat >= 6) {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => ['id'=>$kelas->id,'nama'=>$kelas->nama,'tingkat'=>$kelas->tingkat,'section'=>$kelas->section],
                        'to_kelas' => null,
                        'action' => 'graduate',
                    ];

                    if (! $dry) {
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $kelas->id]
                        );
                        $siswa->is_alumni = true;
                        $siswa->kelas_id = null;
                        $siswa->save();
                    }

                    $summary['graduated']++;
                    continue;
                }

                // Find target class
                $targetKelas = Kelas::where('tingkat', $currentTingkat + 1)
                    ->when($kelas->section, fn($q) => $q->where('section', $kelas->section))
                    ->first();

                if (! $targetKelas) {
                    $targetKelas = Kelas::where('tingkat', $currentTingkat + 1)->first();
                }

                if ($targetKelas) {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => ['id'=>$kelas->id,'nama'=>$kelas->nama,'tingkat'=>$kelas->tingkat,'section'=>$kelas->section],
                        'to_kelas' => ['id'=>$targetKelas->id,'nama'=>$targetKelas->nama,'tingkat'=>$targetKelas->tingkat,'section'=>$targetKelas->section],
                        'action' => 'promote',
                    ];

                    if (! $dry) {
                        $siswa->kelas_id = $targetKelas->id;
                        $siswa->save();

                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $targetKelas->id]
                        );
                    }

                    $summary['promoted']++;
                } else {
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => ['id'=>$kelas->id,'nama'=>$kelas->nama,'tingkat'=>$kelas->tingkat,'section'=>$kelas->section],
                        'to_kelas' => null,
                        'action' => 'graduate_fallback_no_next_class',
                    ];

                    if (! $dry) {
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $kelas->id]
                        );
                        $siswa->is_alumni = true;
                        $siswa->kelas_id = null;
                        $siswa->save();
                    }

                    $summary['graduated']++;
                }
            }

            // Dry run response
            if ($dry) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $maxList = 500;
                $details['promote_list_preview'] = array_slice($details['promote_list'], 0, $maxList);
                $details['promote_list_count'] = count($details['promote_list']);
                $details['copy_wali_list_preview'] = array_slice($details['copy_wali_list'], 0, $maxList);
                $details['copy_wali_list_count'] = count($details['copy_wali_list']);

                return response()->json([
                    'message' => 'Dry run complete — no changes saved.',
                    'tahun_ajaran_nama' => $name,
                    'summary' => $summary,
                    'details' => $details,
                ], 200);
            }

            DB::commit();

            return response()->json([
                'message' => 'Academic year changed and promotion completed.',
                'tahun_ajaran_id' => $newYear->id,
                'tahun_ajaran_nama' => $newYear->nama,
                'summary' => $summary,
                'sample_changes' => array_slice($details['promote_list'], 0, 20),
            ], 201);

        } catch (\Throwable $e) {
            if (! $dry && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return response()->json([
                'message' => 'Error during year change: ' . $e->getMessage()
            ], 500);
        }
    }
}

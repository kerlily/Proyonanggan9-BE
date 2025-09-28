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
    /**
     * Change academic year (promote students).
     *
     * Request JSON:
     * - name (optional) : string nama tahun ajaran baru (ex: "2025/2026")
     * - repeat_student_ids (optional) : array of integer siswa ids that should repeat
     * - copy_wali (optional) : boolean, if true will copy wali_kelas from previous year
     *
     * Optional query param:
     * - ?dry_run=1 : simulate changes and return a detailed plan without saving.
     *
     * Route: POST /api/admin/tahun-ajaran/change
     * Middleware: auth:api, is_admin
     */
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

        // Prepare summary and details collectors
        $summary = [
            'promoted' => 0,
            'repeated' => 0,
            'graduated' => 0,
            'no_class_assigned_skipped' => 0,
            'copied_wali_count' => 0,
        ];

        $details = [
            'promote_list' => [], // each: [siswa_id, nama, from_kelas, to_kelas, action]
            'copy_wali_list' => [], // each: [kelas_id, kelas_name, guru_id]
        ];

        // If dry run, we will simulate without writing to DB.
        // If not dry, we wrap in transaction.
        if (! $dry) {
            DB::beginTransaction();
        }

        try {
            // 1) find and deactivate current active year (simulate in dry_run)
            $current = TahunAjaran::where('is_active', true)->first();

            if (! $dry) {
                if ($current) {
                    $current->is_active = false;
                    $current->save();
                }
            }

            // 2) create new tahun ajaran
            if ($dry) {
                $newYear = new TahunAjaran(['nama' => $name, 'is_active' => true]);
                // not saved
            } else {
                $newYear = TahunAjaran::create([
                    'nama' => $name,
                    'is_active' => true,
                ]);
            }

            // 3) create 2 semesters for new year: ganjil (active), genap (not active)
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

            // 4) (optional) copy wali_kelas from previous active year to new year
            $copiedWaliCount = 0;
            if ($copyWali && $current) {
                $prevWalis = WaliKelas::where('tahun_ajaran_id', $current->id)->get();
                foreach ($prevWalis as $wk) {
                    // simulate or actually upsert
                    if ($dry) {
                        $details['copy_wali_list'][] = [
                            'kelas_id' => $wk->kelas_id,
                            'kelas_name' => optional(Kelas::find($wk->kelas_id))->nama,
                            'guru_id' => $wk->guru_id,
                        ];
                        $copiedWaliCount++;
                    } else {
                        WaliKelas::updateOrCreate(
                            ['kelas_id' => $wk->kelas_id, 'tahun_ajaran_id' => $newYear->id],
                            ['guru_id' => $wk->guru_id]
                        );
                        $copiedWaliCount++;
                    }
                }
            }
            $summary['copied_wali_count'] = $copiedWaliCount;

            // 5) Promote / repeat logic for all non-alumni students
            $siswaList = Siswa::where('is_alumni', false)->get();

            foreach ($siswaList as $siswa) {
                // load kelas if exists
                $fromKelas = $siswa->kelas_id ? Kelas::find($siswa->kelas_id) : null;

                // if student is requested to repeat
                if (in_array($siswa->id, $repeatIds, true)) {
                    // plan to keep kelas_id unchanged (repeat same class)
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => $fromKelas ? ['id'=>$fromKelas->id,'nama'=>$fromKelas->nama,'tingkat'=>$fromKelas->tingkat,'section'=>$fromKelas->section] : null,
                        'to_kelas' => $fromKelas ? ['id'=>$fromKelas->id,'nama'=>$fromKelas->nama,'tingkat'=>$fromKelas->tingkat,'section'=>$fromKelas->section] : null,
                        'action' => 'repeat',
                    ];

                    if (! $dry) {
                        // store riwayat (kelas_id can be null or value)
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $siswa->kelas_id ?? null]
                        );
                        // ensure not alumni
                        if ($siswa->is_alumni) {
                            $siswa->is_alumni = false;
                            $siswa->save();
                        }
                    }

                    $summary['repeated']++;
                    continue;
                }

                // not repeating => attempt to promote (or graduate)
                if (! $siswa->kelas_id) {
                    // no class assigned -> we'll still record riwayat with null if allowed, else count skip
                    $details['promote_list'][] = [
                        'siswa_id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'from_kelas' => null,
                        'to_kelas' => null,
                        'action' => 'no_class_assigned',
                    ];
                    $summary['no_class_assigned_skipped']++;

                    if (! $dry) {
                        // attempt to still write riwayat with null kelas if migration allows nullable
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $siswa->kelas_id ?? null]
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
                            ['kelas_id' => $siswa->kelas_id ?? null]
                        );
                    }
                    continue;
                }

                $currentTingkat = (int) $kelas->tingkat;

                if ($currentTingkat >= 6) {
                    // graduate
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

                // find target kelas tingkat+1, prefer same section
                $targetKelas = Kelas::where('tingkat', $currentTingkat + 1)
                    ->when($kelas->section, fn($q) => $q->where('section', $kelas->section))
                    ->first();

                if (! $targetKelas) {
                    // fallback: any kelas with tingkat+1
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
                        // update siswa kelas
                        $siswa->kelas_id = $targetKelas->id;
                        $siswa->save();

                        // create riwayat for new year
                        RiwayatKelas::updateOrCreate(
                            ['siswa_id' => $siswa->id, 'tahun_ajaran_id' => $newYear->id],
                            ['kelas_id' => $targetKelas->id]
                        );
                    }

                    $summary['promoted']++;
                } else {
                    // no target kelas found, mark as graduate fallback
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
            } // end foreach siswa

            // If dry run: rollback any accidental DB writes and return plan
            if ($dry) {
                // ensure no DB data written (we avoided writes), but rollback just in case
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                // to keep response size reasonable, limit detail arrays
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

            // commit transaction when not dry
            DB::commit();

            return response()->json([
                'message' => 'Academic year changed and promotion completed.',
                'tahun_ajaran_id' => $newYear->id,
                'tahun_ajaran_nama' => $newYear->nama,
                'summary' => $summary,
                // include a small sample
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

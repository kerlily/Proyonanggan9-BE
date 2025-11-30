<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Exception;
use App\Models\Guru;
use App\Models\TahunAjaran;
use App\Models\NilaiSikap;
use App\Models\Ketidakhadiran;

class ImportNilaiController extends Controller
{
    protected function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9\s]/', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    public function import(Request $request, $kelas_id, $semester_id)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $dryRun = (bool)$request->input('dry_run', false);

        $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();
        if (!$activeTahunAjaran) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        $semester = DB::table('semester')
            ->where('id', $semester_id)
            ->where('tahun_ajaran_id', $activeTahunAjaran->id)
            ->first();

        if (!$semester) {
            return response()->json(['message' => 'Semester tidak ditemukan atau tidak sesuai dengan tahun ajaran aktif'], 400);
        }

        $user = auth()->guard('api')->user() ?? auth()->user();
        $uploaderGuruId = null;
        if ($user) {
            $guru = Guru::where('user_id', $user->id)->first();
            if ($guru) {
                $uploaderGuruId = $guru->id;
            } else {
                if (Guru::where('id', $user->id)->exists()) {
                    $uploaderGuruId = $user->id;
                } else {
                    $uploaderGuruId = null;
                }
            }
        }

        $file = $request->file('file');
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (Exception $e) {
            return response()->json(['message' => 'Gagal membaca file: '.$e->getMessage()], 400);
        }

        $sheet = $spreadsheet->getSheetByName('Import Nilai') ?: $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows) || !isset($rows[1])) {
            return response()->json(['message'=>'File kosong atau header tidak ditemukan di baris 1 pada sheet Import Nilai'], 400);
        }

        $headers = $rows[1];
        $colForNama = null;
        $colForCatatan = null;
        $colForIjin = null;
        $colForSakit = null;
        $colForAlpa = null;
        $colForNilaiSikap = null;
        $colForDeskripsiSikap = null;
        $mapelCols = [];

        foreach ($headers as $col => $text) {
            $t = trim((string)$text);
            $tLow = mb_strtolower($t, 'UTF-8');
            if ($tLow === 'nama siswa' || $tLow === 'nama' || $tLow === 'nama_siswa') {
                $colForNama = $col; continue;
            }
            if ($tLow === 'catatan') { $colForCatatan = $col; continue; }
            if ($tLow === 'ijin') { $colForIjin = $col; continue; }
            if ($tLow === 'sakit') { $colForSakit = $col; continue; }
            if ($tLow === 'alpa') { $colForAlpa = $col; continue; }
            if ($tLow === 'nilai sikap') { $colForNilaiSikap = $col; continue; }
            if ($tLow === 'deskripsi sikap') { $colForDeskripsiSikap = $col; continue; }
            if (in_array($tLow, ['no','nomor','#'])) continue;
            if ($t !== '') $mapelCols[$col] = $t;
        }

        if (!$colForNama) {
            return response()->json(['message' => "Header 'Nama Siswa' tidak ditemukan. Pastikan kolom header persis 'Nama Siswa'."], 400);
        }

        $kelas = \App\Models\Kelas::with('mapels')->findOrFail($kelas_id);

        $siswaRows = DB::table('siswa')->where('kelas_id', $kelas_id)->get(['id','nama']);
        $siswaByNorm = [];
        foreach ($siswaRows as $s) {
            $siswaByNorm[$this->normalize($s->nama)][] = ['id'=>$s->id, 'nama'=>$s->nama];
        }

        $mapelRows = $kelas->mapels;

        if ($mapelRows->isEmpty()) {
            return response()->json([
                'message' => "Kelas {$kelas->nama} belum memiliki mapel yang di-assign. Silakan assign mapel terlebih dahulu."
            ], 422);
        }

        $mapelByNorm = [];
        foreach ($mapelRows as $m) $mapelByNorm[$this->normalize($m->nama)] = ['id'=>$m->id, 'nama'=>$m->nama];

        $mapelColToId = []; $mapelNotFound = [];
        foreach ($mapelCols as $col => $mapelName) {
            $norm = $this->normalize($mapelName);
            if (isset($mapelByNorm[$norm])) $mapelColToId[$col] = $mapelByNorm[$norm]['id'];
            else {
                $found = null;
                foreach ($mapelByNorm as $k => $mRow) {
                    if (strpos($k, $norm) !== false || strpos($norm, $k) !== false) { $found = $mRow; break; }
                }
                if ($found) $mapelColToId[$col] = $found['id'];
                else $mapelNotFound[$col] = $mapelName;
            }
        }

        $maxRow = max(array_keys($rows));
        $success = []; $failed = [];

        for ($r = 2; $r <= $maxRow; $r++) {
            if (!isset($rows[$r])) continue;
            $row = $rows[$r];
            $rawNama = trim((string)($row[$colForNama] ?? ''));
            if ($rawNama === '') continue;
            $normNama = $this->normalize($rawNama);

            if (!isset($siswaByNorm[$normNama])) {
                $failed[] = ['row'=>$r, 'nama'=>$rawNama, 'reason'=>'Siswa tidak ditemukan pada kelas ini'];
                continue;
            }
            if (count($siswaByNorm[$normNama]) > 1) {
                $failed[] = ['row'=>$r, 'nama'=>$rawNama, 'reason'=>'Nama siswa ambigu (lebih dari satu di DB)'];
                continue;
            }
            $siswa = $siswaByNorm[$normNama][0];

            $rawCatatan = trim((string)($row[$colForCatatan] ?? ''));
            $catatan = $rawCatatan === '' ? '-' : $rawCatatan;

            foreach ($mapelCols as $col => $mapelHeader) {
                $val = $row[$col] ?? null;
                if ($val === null || $val === '') continue;

                if (!isset($mapelColToId[$col])) {
                    $failed[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'reason'=>'Header mapel tidak cocok'];
                    continue;
                }
                $mapel_id = $mapelColToId[$col];

                if (!is_numeric($val)) {
                    $failed[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'reason'=>'Nilai bukan angka: '.(string)$val];
                    continue;
                }

                $nilaiNumeric = (int) round((float) $val);

                $entry = [
                    'siswa_id' => $siswa['id'],
                    'mapel_id' => $mapel_id,
                    'semester_id' => $semester_id,
                    'tahun_ajaran_id' => $activeTahunAjaran->id,
                ];
                $data = [
                    'nilai' => $nilaiNumeric,
                    'catatan' => $catatan,
                    'input_by_guru_id' => $uploaderGuruId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];

                if ($dryRun) {
                    $success[] = [
                        'row'=>$r,
                        'nama'=>$rawNama,
                        'mapel'=>$mapelHeader,
                        'nilai'=>$nilaiNumeric,
                        'siswa_id'=>$siswa['id'],
                        'tahun_ajaran_id'=>$activeTahunAjaran->id
                    ];
                } else {
                    try {
                        DB::table('nilai')->updateOrInsert($entry, $data);
                        $success[] = [
                            'row'=>$r,
                            'nama'=>$rawNama,
                            'mapel'=>$mapelHeader,
                            'nilai'=>$nilaiNumeric,
                            'siswa_id'=>$siswa['id'],
                            'tahun_ajaran_id'=>$activeTahunAjaran->id
                        ];
                    } catch (Exception $e) {
                        $failed[] = [
                            'row'=>$r,
                            'nama'=>$rawNama,
                            'mapel'=>$mapelHeader,
                            'reason' => 'DB error: '.$e->getMessage()
                        ];
                    }
                }
            }

            if ($colForIjin || $colForSakit || $colForAlpa) {
                $ijin = $colForIjin ? (int)($row[$colForIjin] ?? 0) : 0;
                $sakit = $colForSakit ? (int)($row[$colForSakit] ?? 0) : 0;
                $alpa = $colForAlpa ? (int)($row[$colForAlpa] ?? 0) : 0;

                if ($ijin > 0 || $sakit > 0 || $alpa > 0) {
                    if (!$dryRun) {
                        try {
                            Ketidakhadiran::updateOrCreate(
                                [
                                    'siswa_id' => $siswa['id'],
                                    'semester_id' => $semester_id,
                                    'tahun_ajaran_id' => $activeTahunAjaran->id,
                                ],
                                [
                                    'ijin' => $ijin,
                                    'sakit' => $sakit,
                                    'alpa' => $alpa,
                                    'input_by_guru_id' => $uploaderGuruId,
                                    'updated_at' => now(),
                                ]
                            );
                        } catch (Exception $e) {
                            $failed[] = [
                                'row'=>$r,
                                'nama'=>$rawNama,
                                'reason' => 'Ketidakhadiran DB error: '.$e->getMessage()
                            ];
                        }
                    }
                }
            }

            if ($colForNilaiSikap) {
                $nilaiSikap = strtoupper(trim((string)($row[$colForNilaiSikap] ?? '')));
                $deskripsiSikap = $colForDeskripsiSikap ? trim((string)($row[$colForDeskripsiSikap] ?? '')) : null;

                if (in_array($nilaiSikap, ['A', 'B', 'C', 'D', 'E'])) {
                    if (!$dryRun) {
                        try {
                            NilaiSikap::updateOrCreate(
                                [
                                    'siswa_id' => $siswa['id'],
                                    'semester_id' => $semester_id,
                                    'tahun_ajaran_id' => $activeTahunAjaran->id,
                                ],
                                [
                                    'nilai' => $nilaiSikap,
                                    'deskripsi' => $deskripsiSikap,
                                    'input_by_guru_id' => $uploaderGuruId,
                                    'updated_at' => now(),
                                ]
                            );
                        } catch (Exception $e) {
                            $failed[] = [
                                'row'=>$r,
                                'nama'=>$rawNama,
                                'reason' => 'Nilai Sikap DB error: '.$e->getMessage()
                            ];
                        }
                    }
                } elseif ($nilaiSikap !== '') {
                    $failed[] = [
                        'row'=>$r,
                        'nama'=>$rawNama,
                        'reason' => 'Nilai sikap harus A, B, C, D, atau E. Ditemukan: '.$nilaiSikap
                    ];
                }
            }
        }

        $summary = [
            'success_count' => count($success),
            'failed_count' => count($failed),
            'unmatched_mapel_headers' => array_values($mapelNotFound),
            'uploader_guru_id' => $uploaderGuruId,
            'tahun_ajaran' => [
                'id' => $activeTahunAjaran->id,
                'nama' => $activeTahunAjaran->nama
            ]
        ];

        return response()->json([
            'message' => 'Import selesai (dry_run='.($dryRun?1:0).')',
            'summary' => $summary,
            'details' => [
                'success' => $success,
                'failed' => $failed,
            ]
        ]);
    }
}

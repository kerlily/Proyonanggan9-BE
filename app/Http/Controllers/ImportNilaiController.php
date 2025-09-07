<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Exception;

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

        // determine uploader id from auth (ensure route uses auth middleware)
        $uploaderId = auth()->guard('api')->id() ?? auth()->id() ?? null;

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
        $mapelCols = [];
        foreach ($headers as $col => $text) {
            $t = trim((string)$text);
            $tLow = mb_strtolower($t, 'UTF-8');
            if ($tLow === 'nama siswa' || $tLow === 'nama' || $tLow === 'nama_siswa') {
                $colForNama = $col; continue;
            }
            if ($tLow === 'catatan') { $colForCatatan = $col; continue; }
            if (in_array($tLow, ['no','nomor','#'])) continue;
            if ($t !== '') $mapelCols[$col] = $t;
        }

        if (!$colForNama) {
            return response()->json(['message' => "Header 'Nama Siswa' tidak ditemukan. Pastikan kolom header persis 'Nama Siswa'."], 400);
        }
        if (empty($mapelCols)) {
            return response()->json(['message' => "Tidak ada kolom mapel (mata pelajaran) ditemukan di header."], 400);
        }

        // prefetch siswa and mapel
        $siswaRows = DB::table('siswa')->where('kelas_id', $kelas_id)->get(['id','nama']);
        $siswaByNorm = [];
        foreach ($siswaRows as $s) {
            $siswaByNorm[$this->normalize($s->nama)][] = ['id'=>$s->id, 'nama'=>$s->nama];
        }

        $mapelRows = DB::table('mapel')->get(['id','nama']);
        $mapelByNorm = [];
        foreach ($mapelRows as $m) $mapelByNorm[$this->normalize($m->nama)] = ['id'=>$m->id, 'nama'=>$m->nama];

        $mapelColToId = []; $mapelNotFound = [];
        foreach ($mapelCols as $col => $mapelName) {
            $norm = $this->normalize($mapelName);
            if (isset($mapelByNorm[$norm])) $mapelColToId[$col] = $mapelByNorm[$norm]['id'];
            else {
                // try substring fallback
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
                $failed[] = ['row'=>$r, 'nama'=>$rawNama, 'reason'=>'Siswa tidak ditemukan pada kelas ini (cocokkan sheet Daftar Siswa)'];
                continue;
            }
            if (count($siswaByNorm[$normNama]) > 1) {
                $failed[] = ['row'=>$r, 'nama'=>$rawNama, 'reason'=>'Nama siswa ambigu (lebih dari satu di DB)'];
                continue;
            }
            $siswa = $siswaByNorm[$normNama][0];

            // catatan default '-'
            $rawCatatan = trim((string)($row[$colForCatatan] ?? ''));
            $catatan = $rawCatatan === '' ? '-' : $rawCatatan;

            foreach ($mapelCols as $col => $mapelHeader) {
                $val = $row[$col] ?? null;
                if ($val === null || $val === '') continue;

                if (!isset($mapelColToId[$col])) {
                    $failed[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'reason'=>'Header mapel tidak cocok dengan tabel mapel'];
                    continue;
                }
                $mapel_id = $mapelColToId[$col];

                if (!is_numeric($val) && !is_float($val + 0)) {
                    $failed[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'reason'=>'Nilai bukan angka: '.(string)$val];
                    continue;
                }

                $entry = [
                    'siswa_id' => $siswa['id'],
                    'mapel_id' => $mapel_id,
                    'semester_id' => $semester_id,
                ];
                $data = [
                    'nilai' => $val,
                    'catatan' => $catatan,
                    'input_by_guru_id' => $uploaderId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];

                if ($dryRun) {
                    $success[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'nilai'=>$val,'siswa_id'=>$siswa['id']];
                } else {
                    DB::table('nilai')->updateOrInsert($entry, $data);
                    $success[] = ['row'=>$r,'nama'=>$rawNama,'mapel'=>$mapelHeader,'nilai'=>$val,'siswa_id'=>$siswa['id']];
                }
            }
        }

        $summary = [
            'success_count' => count($success),
            'failed_count' => count($failed),
            'unmatched_mapel_headers' => array_values($mapelNotFound),
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

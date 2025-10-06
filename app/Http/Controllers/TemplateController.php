<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateController extends Controller
{
    /**
     * Download template dua-sheet:
     * - Sheet1 "Daftar Siswa" : nama siswa (ambil dari table `siswa`)
     * - Sheet2 "Import Nilai" : header mapel otomatis dari table `mapel` (tanpa kolom ID)
     *
     * Route: GET /api/kelas/{kelas_id}/semester/{semester_id}/download-template
     * Middleware: auth:api, wali.kelas (atau is_admin)
     */
    public function downloadTemplate(Request $request, $kelas_id, $semester_id)
    {
        // ambil daftar nama siswa sesuai kelas dari DB
        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->orderBy('nama')
            ->get(['nama']);

        // Ambil kelas dengan mapel yang sudah di-assign
$kelas = \App\Models\Kelas::with('mapels')->findOrFail($kelas_id);
$mapels = $kelas->mapels->sortBy('nama')->pluck('nama')->toArray();

// Fallback jika kelas belum ada mapel assigned
if (empty($mapels)) {
    return response()->json([
        'message' => "Kelas {$kelas->nama} belum memiliki mapel yang di-assign. Silakan assign mapel terlebih dahulu melalui menu admin."
    ], 422);
}

        // buat spreadsheet
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Daftar Siswa
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Daftar Siswa');

        $instr_lines = [
            "Petunjuk:",
            "1) Sheet ini menampilkan daftar nama siswa persis sesuai yang ada di database.",
            "2) Gunakan nama persis dari sheet ini saat mengisi sheet 'Import Nilai' untuk menghindari error pencocokan.",
            "3) Jika ada nama yang berbeda/keliru, perbaiki data siswa di sistem dahulu atau hubungi admin.",
            "",
            "Dibuat: " . now()->toDateTimeString()
        ];
        // gabungkan instruksi di bagian atas (merged area)
        $sheet1->mergeCells('A1:E4');
        $sheet1->setCellValue('A1', implode("\n", $instr_lines));
        $sheet1->getStyle('A1')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical('top');

        // header tabel mulai row 6
        $startRow = 6;
        $sheet1->setCellValue("A{$startRow}", 'No');
        $sheet1->setCellValue("B{$startRow}", 'Nama Siswa');

        // styling header (warna biru, teks putih, center)
        $headerRange = "A{$startRow}:B{$startRow}";
        $sheet1->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet1->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4F81BD');
        $sheet1->getStyle($headerRange)->getAlignment()->setHorizontal('center')->setVertical('center');

        // isi nama siswa
        $row = $startRow + 1;
        foreach ($siswaList as $index => $s) {
            $sheet1->setCellValue("A{$row}", $index + 1);
            $sheet1->setCellValue("B{$row}", $s->nama ?? '');
            // alignment: nomor center, nama left
            $sheet1->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
            $sheet1->getStyle("B{$row}")->getAlignment()->setHorizontal('left');
            $row++;
        }

        // minimal rows = 20 jika kelas lebih kecil
        $minRows = 20;
        $currentCount = max($siswaList->count(), 0);
        if ($currentCount < $minRows) {
            for ($i = $currentCount + 1; $i <= $minRows; $i++) {
                $sheet1->setCellValue("A{$row}", $i);
                $sheet1->setCellValue("B{$row}", '');
                $sheet1->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
                $sheet1->getStyle("B{$row}")->getAlignment()->setHorizontal('left');
                $row++;
            }
        }

        // set column widths and borders
        $sheet1->getColumnDimension('A')->setWidth(6);
        $sheet1->getColumnDimension('B')->setWidth(46);
        // add thin border for the table area
        $lastRow = $row - 1;
        $sheet1->getStyle("A{$startRow}:B{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Sheet 2: Import Nilai (empty)
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Import Nilai');

        // header: No | Nama Siswa | <mapel...> | Catatan
        $headers = array_merge(['No', 'Nama Siswa'], $mapels, ['Catatan']);
        // write headers starting at row 1
        $col = 'A';
        foreach ($headers as $h) {
            $sheet2->setCellValue($col . '1', $h);
            // style header
            $sheet2->getStyle($col . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet2->getStyle($col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF4F81BD');
            $sheet2->getStyle($col . '1')->getAlignment()->setHorizontal('center')->setVertical('center');
            // set column widths: Nama Siswa wider, others moderate
            if ($col === 'A') $sheet2->getColumnDimension($col)->setWidth(6);
            elseif ($col === 'B') $sheet2->getColumnDimension($col)->setWidth(46);
            elseif ($col === end($headers)) $sheet2->getColumnDimension($col)->setWidth(30); // catatan
            else $sheet2->getColumnDimension($col)->setWidth(16);
            $col++;
        }

        // rows: same number as sheet1 (use lastRow) or at least 20
        $rowsToMake = max($currentCount, $minRows);
        for ($r = 2; $r <= 1 + $rowsToMake; $r++) {
            // No
            $sheet2->setCellValue("A{$r}", $r - 1);
            $sheet2->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            // Nama Siswa left blank (teacher will fill from Daftar Siswa)
            $sheet2->getStyle("B{$r}")->getAlignment()->setHorizontal('left');
            // other cells center blank
            // (no need to set values; but set alignment)
            $col = 'C';
            for ($i = 3; $i <= count($headers) - 1; $i++) {
                $sheet2->getStyle(chr(64 + $i) . "{$r}")->getAlignment()->setHorizontal('center');
            }
            // Catatan left align
            $sheet2->getStyle(chr(64 + count($headers)) . "{$r}")->getAlignment()->setHorizontal('left');
        }

        // borders for sheet2 table area
        $lastColLetter = chr(64 + count($headers));
        $sheet2->getStyle("A1:{$lastColLetter}" . (1 + $rowsToMake))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // filename
        $kelasNama = DB::table('kelas')->where('id', $kelas_id)->value('nama') ?? $kelas_id;
        $filename = "Template_Nilai_Kelas{$kelasNama}_Semester{$semester_id}.xlsx";

        // stream as download
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);

        return $response;
    }
}

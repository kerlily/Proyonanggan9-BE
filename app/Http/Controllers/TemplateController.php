<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateController extends Controller
{
    public function downloadTemplate(Request $request, $kelas_id, $semester_id)
    {
        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->orderBy('nama')
            ->get(['nama']);

        $kelas = \App\Models\Kelas::with('mapels')->findOrFail($kelas_id);
        $mapels = $kelas->mapels->sortBy('nama')->pluck('nama')->toArray();

        if (empty($mapels)) {
            return response()->json([
                'message' => "Kelas {$kelas->nama} belum memiliki mapel yang di-assign. Silakan assign mapel terlebih dahulu melalui menu admin."
            ], 422);
        }

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

        $sheet1->mergeCells('A1:E4');
        $sheet1->setCellValue('A1', implode("\n", $instr_lines));
        $sheet1->getStyle('A1')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical('top');

        $startRow = 6;
        $sheet1->setCellValue("A{$startRow}", 'No');
        $sheet1->setCellValue("B{$startRow}", 'Nama Siswa');

        $headerRange = "A{$startRow}:B{$startRow}";
        $sheet1->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet1->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4F81BD');
        $sheet1->getStyle($headerRange)->getAlignment()->setHorizontal('center')->setVertical('center');

        $row = $startRow + 1;
        foreach ($siswaList as $index => $s) {
            $sheet1->setCellValue("A{$row}", $index + 1);
            $sheet1->setCellValue("B{$row}", $s->nama ?? '');
            $sheet1->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
            $sheet1->getStyle("B{$row}")->getAlignment()->setHorizontal('left');
            $row++;
        }

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

        $sheet1->getColumnDimension('A')->setWidth(6);
        $sheet1->getColumnDimension('B')->setWidth(46);
        $lastRow = $row - 1;
        $sheet1->getStyle("A{$startRow}:B{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Sheet 2: Import Nilai
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Import Nilai');

        $headers = array_merge(
            ['No', 'Nama Siswa'],
            $mapels,
            ['Catatan', 'Ijin', 'Sakit', 'Alpa', 'Nilai Sikap', 'Deskripsi Sikap']
        );

        $col = 'A';
        foreach ($headers as $h) {
            $sheet2->setCellValue($col . '1', $h);
            $sheet2->getStyle($col . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet2->getStyle($col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF4F81BD');
            $sheet2->getStyle($col . '1')->getAlignment()->setHorizontal('center')->setVertical('center');

            if ($col === 'A') {
                $sheet2->getColumnDimension($col)->setWidth(6);
            } elseif ($col === 'B') {
                $sheet2->getColumnDimension($col)->setWidth(46);
            } elseif (in_array($h, ['Catatan', 'Deskripsi Sikap'])) {
                $sheet2->getColumnDimension($col)->setWidth(40);
            } elseif (in_array($h, ['Ijin', 'Sakit', 'Alpa'])) {
                $sheet2->getColumnDimension($col)->setWidth(10);
            } elseif ($h === 'Nilai Sikap') {
                $sheet2->getColumnDimension($col)->setWidth(12);
            } else {
                $sheet2->getColumnDimension($col)->setWidth(16);
            }
            $col++;
        }

        $rowsToMake = max($currentCount, $minRows);
        for ($r = 2; $r <= 1 + $rowsToMake; $r++) {
            $sheet2->setCellValue("A{$r}", $r - 1);
            $sheet2->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            $sheet2->getStyle("B{$r}")->getAlignment()->setHorizontal('left');

            for ($i = 3; $i <= count($headers); $i++) {
                $colLetter = chr(64 + $i);
                $headerName = $headers[$i - 1];
                if (in_array($headerName, ['Catatan', 'Deskripsi Sikap'])) {
                    $sheet2->getStyle($colLetter . "{$r}")->getAlignment()->setHorizontal('left');
                } else {
                    $sheet2->getStyle($colLetter . "{$r}")->getAlignment()->setHorizontal('center');
                }
            }
        }

        $lastColLetter = chr(64 + count($headers));
        $sheet2->getStyle("A1:{$lastColLetter}" . (1 + $rowsToMake))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $kelasNama = DB::table('kelas')->where('id', $kelas_id)->value('nama') ?? $kelas_id;
        $filename = "Template_Nilai_Kelas{$kelasNama}_Semester{$semester_id}.xlsx";

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

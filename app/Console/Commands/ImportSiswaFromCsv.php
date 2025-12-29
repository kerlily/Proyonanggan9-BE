<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Siswa;
use App\Models\Kelas;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ImportSiswaFromCsv extends Command
{
    protected $signature = 'import:siswa {--file=storage/app/import/siswa.csv} {--dry-run}';
    protected $description = 'Import siswa from CSV. CSV must contain nama,tahun_lahir,kelas_id or kelas_nama,is_alumni';

    public function handle()
    {
        $fileOption = $this->option('file');
        // resolve relative paths like "storage/app/import/siswa.csv"
        $path = Str::startsWith($fileOption, ['/', 'C:\\']) ? $fileOption : base_path($fileOption);
        // If file starts with "storage/", transform to storage_path for convenience
        if (Str::startsWith($fileOption, 'storage/')) {
            $path = storage_path(Str::after($fileOption, 'storage/'));
        }

        if (!file_exists($path)) {
            $this->error("File not found: $path");
            return 1;
        }

        $this->info("Reading CSV: $path");

        // Read entire file content and remove BOM
        $content = file_get_contents($path);

        // Remove BOM if present (UTF-8 BOM is EF BB BF)
        $content = $this->removeBOM($content);

        // Create temporary stream from cleaned content
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        // Read header
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (!$headers) {
            $this->error("Empty or invalid CSV");
            fclose($handle);
            return 1;
        }

        // Normalize headers - remove any remaining non-printable chars
        $headers = array_map(function($h){
            // Remove BOM and other non-printable characters
            $h = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/u', '', $h);
            return Str::lower(trim($h));
        }, $headers);

        $this->info("CSV Headers detected: " . implode(', ', $headers));

        // Collect rows and kelas names to resolve
        $rows = [];
        $kelasNamesToResolve = [];
        $lineNumber = 1; // Start from 1 (header)

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                $this->warn("Skipping empty row at line {$lineNumber}");
                continue;
            }

            // Pad row to match header count
            $row = array_pad($row, count($headers), null);

            // Combine with headers
            $assoc = array_combine($headers, $row);

            // Trim all values and remove BOM/non-printable chars
            $assoc = array_map(function($v) {
                if ($v === null) return null;
                // Remove any BOM or non-printable characters from values
                $v = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/u', '', $v);
                return trim($v);
            }, $assoc);

            // Debug first row
            if (count($rows) === 0) {
                $this->info("Sample row data: " . json_encode($assoc, JSON_UNESCAPED_UNICODE));
            }

            if (isset($assoc['kelas_nama']) && $assoc['kelas_nama'] !== '') {
                $kelasNamesToResolve[] = $assoc['kelas_nama'];
            }

            $rows[] = $assoc;
        }
        fclose($handle);

        // Resolve kelas_nama -> kelas_id if needed
        $kelasMap = [];
        if (!empty($kelasNamesToResolve)) {
            $kelasNamesToResolve = array_unique($kelasNamesToResolve);
            $kelasRows = Kelas::whereIn('nama', $kelasNamesToResolve)->get();
            foreach ($kelasRows as $k) {
                $kelasMap[$k->nama] = $k->id;
            }
        }

        $this->info('Total rows to import: ' . count($rows));
        if ($this->option('dry-run')) {
            $this->info('Dry run mode — no DB changes will be made.');
        }

        $errors = [];
        $imported = 0;

        foreach ($rows as $i => $r) {
            $rowNumber = $i + 2; // +2 because header + 0-index

            // Basic mapping & default
            $nama = $r['nama'] ?? null;
            $tahun_lahir = isset($r['tahun_lahir']) && $r['tahun_lahir'] !== '' ? (int)$r['tahun_lahir'] : null;
            $is_alumni = isset($r['is_alumni'])
            ? in_array(strtolower((string)$r['is_alumni']), ['1', 'true', 'ya', 'yes'], true)
            : false;

            // Kelas_id resolution
            $kelas_id = null;
            if (isset($r['kelas_id']) && $r['kelas_id'] !== '') {
                $kelas_id = (int)$r['kelas_id'];
            } elseif (isset($r['kelas_nama']) && isset($kelasMap[$r['kelas_nama']])) {
                $kelas_id = $kelasMap[$r['kelas_nama']];
            }

            // Debug info for problematic rows
            if (empty($nama)) {
                $this->warn("Row {$rowNumber} has empty nama. Raw data: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            }

            // Validation
            $validator = Validator::make([
                'nama' => $nama,
                'tahun_lahir' => $tahun_lahir,
                'kelas_id' => $kelas_id,
            ], [
                'nama' => 'required|string|max:255',
                'tahun_lahir' => 'required|integer|digits:4',
                'kelas_id' => 'nullable|exists:kelas,id',
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $validator->errors()->all(),
                    'data' => $r,
                ];
                continue;
            }

            // Create siswa via Eloquent so mutator hashes password
            try {
                if ($this->option('dry-run')) {
                    $this->line("DRY: would create {$nama} / {$tahun_lahir} / kelas_id={$kelas_id}");
                    $imported++;
                } else {
                    DB::transaction(function () use ($nama, $tahun_lahir, $kelas_id, $is_alumni, &$imported) {
                        $payload = [
                            'nama' => $nama,
                            'tahun_lahir' => $tahun_lahir,
                            'password' => (string)$tahun_lahir,
                            'kelas_id' => $kelas_id,
                            'is_alumni' => $is_alumni,
                        ];

                        Siswa::create($payload);
                        $imported++;
                    });
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                    'data' => $r,
                ];
                continue;
            }
        }

        $this->info("Imported: {$imported}");
        if (!empty($errors)) {
            $this->error("Errors: " . count($errors));
            foreach ($errors as $err) {
                $this->line("Row {$err['row']}: " . implode('; ', $err['errors']));
            }
        } else {
            $this->info("No row errors.");
        }

        $this->info("Done.");
        return empty($errors) ? 0 : 1;
    }

    /**
     * Remove BOM from string
     */
    private function removeBOM($text)
    {
        // Remove UTF-8 BOM
        if (substr($text, 0, 3) == pack('CCC', 0xef, 0xbb, 0xbf)) {
            $text = substr($text, 3);
        }
        // Remove UTF-16 BE BOM
        if (substr($text, 0, 2) == pack('CC', 0xfe, 0xff)) {
            $text = substr($text, 2);
        }
        // Remove UTF-16 LE BOM
        if (substr($text, 0, 2) == pack('CC', 0xff, 0xfe)) {
            $text = substr($text, 2);
        }
        return $text;
    }
}

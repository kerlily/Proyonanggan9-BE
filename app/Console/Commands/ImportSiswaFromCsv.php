<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Siswa;
use App\Models\Kelas;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportSiswaFromCsv extends Command
{
    protected $signature = 'import:siswa {--file=storage/app/import/siswa.csv} {--dry-run}';
    protected $description = 'Import siswa from CSV. CSV must contain nama,tahun_lahir,kelas_id or kelas_nama,is_alumni,created_at,updated_at';

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
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        if (!$headers) {
            $this->error("Empty or invalid CSV");
            return 1;
        }

        // normalize headers
        $origHeaders = $headers;
        $headers = array_map(function($h){ return Str::lower(trim($h)); }, $headers);

        // collect rows and kelas names to resolve
        $rows = [];
        $kelasNamesToResolve = [];

        while ($row = fgetcsv($handle)) {
            if (count($row) === 0) continue;
            // handle rows shorter than headers
            $row = array_pad($row, count($headers), null);
            $assoc = array_combine($headers, $row);
            // trim values
            $assoc = array_map(fn($v) => $v === null ? null : trim($v), $assoc);
            if (isset($assoc['kelas_nama']) && $assoc['kelas_nama'] !== '') {
                $kelasNamesToResolve[] = $assoc['kelas_nama'];
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        // resolve kelas_nama -> kelas_id if needed
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
            // basic mapping & default
            $nama = $r['nama'] ?? null;
            $tahun_lahir = isset($r['tahun_lahir']) && $r['tahun_lahir'] !== '' ? (int)$r['tahun_lahir'] : null;
            $is_alumni = isset($r['is_alumni']) && $r['is_alumni'] !== '' ? (bool)$r['is_alumni'] : false;
            $created_at = $r['created_at'] ?? null;
            $updated_at = $r['updated_at'] ?? null;

            // kelas_id resolution
            $kelas_id = null;
            if (isset($r['kelas_id']) && $r['kelas_id'] !== '') {
                $kelas_id = (int)$r['kelas_id'];
            } elseif (isset($r['kelas_nama']) && isset($kelasMap[$r['kelas_nama']])) {
                $kelas_id = $kelasMap[$r['kelas_nama']];
            }

            // validation
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
                    'row' => $i + 2, // +2 karena header + 0-index
                    'errors' => $validator->errors()->all(),
                    'data' => $r,
                ];
                continue;
            }

            // create siswa via Eloquent so mutator hashes password
            try {
                if ($this->option('dry-run')) {
                    $this->line("DRY: would create {$nama} / {$tahun_lahir} / kelas_id={$kelas_id}");
                } else {
                    DB::transaction(function () use ($nama, $tahun_lahir, $kelas_id, $is_alumni, $created_at, $updated_at, &$imported) {
                        $payload = [
                            'nama' => $nama,
                            'tahun_lahir' => $tahun_lahir,
                            // pass tahun_lahir as password so model mutator hashes it
                            'password' => (string)$tahun_lahir,
                            'kelas_id' => $kelas_id,
                            'is_alumni' => $is_alumni,
                        ];
                        if ($created_at) $payload['created_at'] = $created_at;
                        if ($updated_at) $payload['updated_at'] = $updated_at;

                        Siswa::create($payload);
                        $imported++;
                    });
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $i + 2,
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
        return 0;
    }
}

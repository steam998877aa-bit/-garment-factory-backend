<?php

namespace Tests\Feature;

use App\Services\BiometricAttendanceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class BiometricAttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_accepts_late_year_months_and_combines_separate_date_and_time(): void
    {
        $service = new class extends BiometricAttendanceImportService
        {
            protected function readGrid(string $path): array
            {
                return [
                    ['A' => '1001', 'D' => '31/10/2026', 'E' => '08:00'],
                    ['A' => '1002', 'D' => '30/11/2026', 'E' => '08:15'],
                    ['A' => '1003', 'D' => '31/12/2026', 'E' => '08:30'],
                    ['A' => '1004', 'D' => '31/13/2026', 'E' => '08:45'],
                ];
            }
        };

        $path = tempnam(sys_get_temp_dir(), 'biometric-import-');
        file_put_contents($path, 'test');

        try {
            $result = $service->import($path, true);
        } finally {
            unlink($path);
        }

        $this->assertSame(3, $result['parsed']);
        $this->assertSame(1, $result['skipped_rows']);
        $this->assertSame(1, $result['skipped_reasons']['invalid_date']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31'], $result['dates_imported']);
    }

    public function test_imports_all_data_rows_from_xls_with_mangled_headers_and_arabic_symbols(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '艳轻洼金');
        $sheet->setCellValue('D1', '日期◆');
        $sheet->setCellValue('E1', '日期时间※');
        $markers = ['ص', 'م', 'صباحاً', 'مساءً'];

        for ($row = 2; $row <= 500; $row++) {
            $sheet->setCellValue('A' . $row, (string) (1000 + $row));
            $sheet->setCellValue('D' . $row, '10/01/2026');
            $sheet->setCellValue(
                'E' . $row,
                '01/10/2026 09:24:27 ' . $markers[($row - 2) % count($markers)] . '◆※'
            );
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'biometric-import-');
        $path = $temporaryPath . '.xls';
        unlink($temporaryPath);
        (new Xls($spreadsheet))->save($path);
        $this->assertGreaterThan(0, filesize($path));
        $this->assertSame('Xls', IOFactory::identify($path));

        try {
            $result = app(BiometricAttendanceImportService::class)->import($path, true);
        } finally {
            unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        $this->assertSame(500, $result['total_rows']);
        $this->assertSame(499, $result['parsed']);
        $this->assertSame(1, $result['skipped_rows']);
        $this->assertSame(0, $result['skipped_reasons']['invalid_date']);
        $this->assertSame(['2026-10-01'], $result['dates_imported']);
    }
}

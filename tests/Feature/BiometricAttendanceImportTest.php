<?php

namespace Tests\Feature;

use App\Services\BiometricAttendanceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_import_parses_499_arabic_punch_timestamps_without_skipping_rows(): void
    {
        $service = new class extends BiometricAttendanceImportService
        {
            protected function readGrid(string $path): array
            {
                $rows = [];
                $markers = ['ص', 'م', 'صباحاً', 'مساءً'];

                for ($row = 1; $row <= 499; $row++) {
                    $rows[] = [
                        'A' => (string) (1000 + $row),
                        'D' => '10/01/2026',
                        'E' => '01/10/2026 09:24:27 ' . $markers[($row - 1) % count($markers)],
                    ];
                }

                return $rows;
            }
        };

        $path = tempnam(sys_get_temp_dir(), 'biometric-import-');
        file_put_contents($path, 'test');

        try {
            $result = $service->import($path, true);
        } finally {
            unlink($path);
        }

        $this->assertSame(499, $result['total_rows']);
        $this->assertSame(499, $result['parsed']);
        $this->assertSame(0, $result['skipped_rows']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(['2026-10-01'], $result['dates_imported']);
    }
}

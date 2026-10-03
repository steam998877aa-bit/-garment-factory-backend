<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class BiometricAttendanceImportService
{
    /**
     * Import attendance records from a biometric export file (Excel/CSV).
     *
     * Rules:
     * 1. Character encoding conversion (UTF-8, GBK, Windows-1256, etc.).
     * 2. Index-based column reading (Col A/C for Fingerprint ID, Col D/E for Date & Time, Col G for Punch Type).
     * 3. Deduplication & Time Windows:
     *    - Check-in: First punch between 07:00 AM and 10:00 AM.
     *    - Check-out: Last punch between 03:00 PM (15:00) and 07:00 PM (19:00).
     * 4. Automatic Absence generation: Registered employees with no check-in punch on import dates are marked 'absent'.
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("ملف البصمات غير موجود أو لا يمكن قراءته.");
        }

        $grid = $this->readGrid($path);
        if (empty($grid)) {
            return [
                'file' => basename($path),
                'dry_run' => $dryRun,
                'total_rows' => 0,
                'total' => 0,
                'parsed' => 0,
                'imported' => 0,
                'created' => 0,
                'updated' => 0,
                'matched' => 0,
                'unmatched' => 0,
                'failed' => 0,
                'unmatched_fingerprints' => [],
                'dates_imported' => [],
                'skipped_rows' => 0,
                'skipped_reasons' => [],
                'warnings' => [],
            ];
        }

        $totalGridRows = count($grid);
        $punchesByEmpAndDate = [];
        $unmatched = [];
        $importedDates = [];
        $skippedReasons = [
            'missing_fingerprint' => 0,
            'invalid_date' => 0,
        ];

        $employeesMap = Employee::query()
            ->whereNotNull('fingerprint_id')
            ->get()
            ->keyBy(fn ($e) => (string) $e->fingerprint_id);

        foreach ($grid as $rowIndex => $cells) {
            $valA = $this->sanitizeEncoding($cells['A'] ?? $cells[0] ?? '');
            $valB = $this->sanitizeEncoding($cells['B'] ?? $cells[1] ?? '');
            $valC = $this->sanitizeEncoding($cells['C'] ?? $cells[2] ?? '');
            $valD = $this->sanitizeEncoding($cells['D'] ?? $cells[3] ?? '');
            $valE = $this->sanitizeEncoding($cells['E'] ?? $cells[4] ?? '');
            $valF = $this->sanitizeEncoding($cells['F'] ?? $cells[5] ?? '');
            $valG = $this->sanitizeEncoding($cells['G'] ?? $cells[6] ?? '');
            $valH = $this->sanitizeEncoding($cells['H'] ?? $cells[7] ?? '');

            // Determine Fingerprint ID
            $fingerprintId = $this->extractFingerprintId($valC, $valA);

            if ($fingerprintId === null) {
                $skippedReasons['missing_fingerprint']++;
                continue; // Skip non-numeric header rows
            }

            // Summary format check (Col C = Check-in time, Col D = Check-out time)
            $isSummaryFormat = $this->isTimeString($valC) && $this->isTimeString($valD) && $this->normaliseDate($valB) !== null;

            if ($isSummaryFormat) {
                $date = $this->normaliseDate($valB);
                $checkIn = $this->normaliseTime($valC);
                $checkOut = $this->normaliseTime($valD);

                if ($date) {
                    $importedDates[$date] = true;
                    $punchesByEmpAndDate[$fingerprintId][$date]['summary'] = [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'notes' => $valH ?: ($valE ?: null),
                    ];
                }
                continue;
            }

            // Punch log format: 1 row per punch
            $datePart = $this->normaliseDate($valD);
            if (!empty($valE) && $this->isTimeString($valE)) {
                $dt = $datePart !== null ? $this->parseDateTime($datePart . ' ' . $valE) : null;
            } else {
                $dt = $this->parseDateTime(!empty($valE) ? $valE : $valD);
            }

            if ($dt === null) {
                if ($datePart && !empty($valE)) {
                    $dt = $this->parseDateTime($datePart . ' ' . $valE);
                }
            }

            if ($dt === null) {
                $skippedReasons['invalid_date']++;
                continue;
            }

            $date = $dt->toDateString();
            $time = $dt->toTimeString();
            $importedDates[$date] = true;

            $type = strtolower(!empty($valG) ? $valG : $valF);
            $direction = 'unknown';

            if ($type === 'i' || $type === 'in' || $type === '1' || mb_strpos($type, 'حضور') !== false) {
                $direction = 'in';
            } elseif ($type === 'o' || $type === 'out' || $type === '0' || mb_strpos($type, 'خروج') !== false) {
                $direction = 'out';
            } else {
                $direction = $dt->hour < 12 ? 'in' : 'out';
            }

            $punchesByEmpAndDate[$fingerprintId][$date]['punches'][] = [
                'time' => $time,
                'datetime' => $dt,
                'direction' => $direction,
                'notes' => $valH,
            ];
        }

        $rows = [];

        foreach ($punchesByEmpAndDate as $fId => $dates) {
            $employee = $employeesMap[(string) $fId] ?? null;

            if ($employee === null && !in_array((string) $fId, $unmatched, true)) {
                $unmatched[] = (string) $fId;
            }

            foreach ($dates as $date => $data) {
                if (isset($data['summary'])) {
                    $checkIn = $data['summary']['check_in'];
                    $checkOut = $data['summary']['check_out'];
                    $notes = $data['summary']['notes'];
                } else {
                    $punches = $data['punches'] ?? [];
                    [$checkIn, $checkOut, $notes] = $this->processDayPunches($punches);
                }

                $status = $this->determineStatus($checkIn, $notes);
                $workingHours = $this->calculateHours($checkIn, $checkOut);

                $rows[(string) $fId . '|' . $date] = [
                    'employee_id' => $employee?->id,
                    'fingerprint_id' => (string) $fId,
                    'date' => $date,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'working_hours' => $workingHours,
                    'status' => $status,
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Automatic Absence Generation for registered active employees with no check-in punch
        $allActiveEmployees = Employee::query()
            ->whereNotNull('fingerprint_id')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->get();

        foreach (array_keys($importedDates) as $date) {
            foreach ($allActiveEmployees as $emp) {
                $fId = (string) $emp->fingerprint_id;
                $key = $fId . '|' . $date;

                if (!isset($rows[$key])) {
                    $rows[$key] = [
                        'employee_id' => $emp->id,
                        'fingerprint_id' => $fId,
                        'date' => $date,
                        'check_in' => null,
                        'check_out' => null,
                        'working_hours' => 0,
                        'status' => Attendance::STATUS_ABSENT,
                        'notes' => 'غائب - لم تسجل بصمة',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } elseif ($rows[$key]['check_in'] === null && $rows[$key]['status'] === Attendance::STATUS_PRESENT) {
                    $rows[$key]['status'] = Attendance::STATUS_ABSENT;
                    $rows[$key]['working_hours'] = 0;
                }
            }
        }

        $finalRows = array_values($rows);
        $matchedCount = count(array_filter($finalRows, fn($r) => $r['employee_id'] !== null));
        $skippedRows = array_sum($skippedReasons);
        $warnings = [];

        if ($skippedReasons['missing_fingerprint'] > 0) {
            $warnings[] = "تم تخطي {$skippedReasons['missing_fingerprint']} صف لعدم وجود رقم بصمة رقمي.";
        }
        if ($skippedReasons['invalid_date'] > 0) {
            $warnings[] = "تم تخطي {$skippedReasons['invalid_date']} صف لعدم إمكانية قراءة التاريخ أو الوقت.";
        }

        if (!$dryRun && !empty($finalRows)) {
            foreach (array_chunk($finalRows, 500) as $chunk) {
                Attendance::upsert(
                    $chunk,
                    ['fingerprint_id', 'date'],
                    ['employee_id', 'check_in', 'check_out', 'working_hours', 'status', 'notes', 'updated_at']
                );
            }
        }

        return [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'total_rows' => $totalGridRows,
            'total' => $totalGridRows,
            'parsed' => count($finalRows),
            'imported' => $dryRun ? 0 : count($finalRows),
            'created' => count($finalRows),
            'updated' => 0,
            'matched' => $matchedCount,
            'unmatched' => count($unmatched),
            'failed' => 0,
            'unmatched_fingerprints' => array_values($unmatched),
            'dates_imported' => array_keys($importedDates),
            'skipped_rows' => $skippedRows,
            'skipped_reasons' => $skippedReasons,
            'warnings' => $warnings,
        ];
    }

    /**
     * Extract clean fingerprint ID from cell candidates.
     */
    protected function extractFingerprintId(string ...$candidates): ?string
    {
        foreach ($candidates as $val) {
            $cleaned = trim($val);
            if ($cleaned === '') continue;
            if (preg_match('/^(\d+)\.0+$/', $cleaned, $m)) {
                $cleaned = $m[1];
            }
            if (ctype_digit($cleaned)) {
                return (string) (int) $cleaned;
            }
        }
        return null;
    }

    /**
     * Process day punches:
     * - Check-In: First punch between 07:00 AM and 10:00 AM (07:00:00 - 10:00:00)
     * - Check-Out: Last punch between 03:00 PM and 07:00 PM (15:00:00 - 19:00:00)
     */
    protected function processDayPunches(array $punches): array
    {
        $checkIn = null;
        $checkOut = null;
        $notesList = [];

        usort($punches, fn($a, $b) => $a['datetime'] <=> $b['datetime']);

        // 1. Check-In between 07:00 AM and 10:00 AM
        foreach ($punches as $p) {
            $timeStr = $p['time'];
            if ($timeStr >= '07:00:00' && $timeStr <= '10:00:00') {
                $checkIn = $timeStr;
                if (!empty($p['notes'])) $notesList[] = $p['notes'];
                break; // First punch in 07:00-10:00 window
            }
        }

        // Fallback Check-In: Earliest 'in' punch before 12:00 if no punch strictly in 07:00-10:00
        if ($checkIn === null) {
            foreach ($punches as $p) {
                if ($p['time'] < '12:00:00' && $p['direction'] === 'in') {
                    $checkIn = $p['time'];
                    if (!empty($p['notes'])) $notesList[] = $p['notes'];
                    break;
                }
            }
        }

        // 2. Check-Out between 03:00 PM (15:00:00) and 07:00 PM (19:00:00)
        foreach (array_reverse($punches) as $p) {
            $timeStr = $p['time'];
            if ($timeStr >= '15:00:00' && $timeStr <= '19:00:00') {
                $checkOut = $timeStr;
                if (!empty($p['notes'])) $notesList[] = $p['notes'];
                break; // Last punch in 15:00-19:00 window
            }
        }

        // Fallback Check-Out: Latest 'out' punch after 12:00 if no punch in 15:00-19:00
        if ($checkOut === null) {
            foreach (array_reverse($punches) as $p) {
                if ($p['time'] >= '12:00:00' && $p['direction'] === 'out') {
                    $checkOut = $p['time'];
                    if (!empty($p['notes'])) $notesList[] = $p['notes'];
                    break;
                }
            }
        }

        $note = implode(', ', array_unique(array_filter($notesList))) ?: null;

        return [$checkIn, $checkOut, $note];
    }

    protected function sanitizeEncoding(mixed $value): string
    {
        if ($value === null) return '';
        $str = (string) $value;
        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = @mb_convert_encoding($str, 'UTF-8', ['GBK', 'GB2312', 'CP936', 'Windows-1256', 'ISO-8859-1']);
        }
        return trim($str);
    }

    protected function isTimeString(string $value): bool
    {
        return preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', trim($value)) === 1;
    }

    protected function readGrid(string $path): array
    {
        $spreadsheet = null;
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            $readers = ['Xlsx', 'Xls', 'Csv', 'Html'];
            foreach ($readers as $readerType) {
                try {
                    $reader = IOFactory::createReader($readerType);
                    if ($readerType === 'Csv') {
                        $reader->setInputEncoding('UTF-8');
                    }
                    if ($reader->canRead($path)) {
                        $spreadsheet = $reader->load($path);
                        break;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        if (!$spreadsheet) return [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, false, false, true);
            if (!empty($data)) return $data;
        }

        return [];
    }

    protected function normaliseDate(mixed $value): ?string
    {
        if (empty($value)) return null;
        if (is_numeric($value) && (float)$value > 40000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {}
        }
        try {
            $dt = $this->parseDateTime($value);
            return $dt?->toDateString();
        } catch (Throwable) {}

        return null;
    }

    protected function parseDateTime(mixed $value): ?Carbon
    {
        if (empty($value)) return null;

        if (is_numeric($value) && (float)$value > 40000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (Throwable) {}
        }

        $str = $this->sanitizeEncoding($value);
        if (empty($str)) return null;

        $str = str_replace(['ص', 'صباحا', 'صباحاً'], 'AM', $str);
        $str = str_replace(['م', 'مساء', 'مساءً'], 'PM', $str);

        try {
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(.*))?$/i', $str, $matches)) {
                $p1 = (int) $matches[1];
                $p2 = (int) $matches[2];
                $year = (int) $matches[3];
                $timePart = trim($matches[4] ?? '');

                if ($p1 > 12) {
                    $day = $p1;
                    $month = $p2;
                } elseif ($p2 > 12) {
                    $day = $p2;
                    $month = $p1;
                } else {
                    $day = $p1;
                    $month = $p2;
                }

                if (!checkdate($month, $day, $year)) {
                    return null;
                }

                $dateString = sprintf('%04d-%02d-%02d', $year, $month, $day);
                if (!empty($timePart)) {
                    $dateString .= ' ' . $timePart;
                }

                return Carbon::parse($dateString);
            }

            return Carbon::parse($str);
        } catch (Throwable) {
            return null;
        }
    }

    protected function normaliseTime(mixed $value): ?string
    {
        if (empty($value)) return null;

        if (is_numeric($value)) {
            $seconds = (int) round(((float)$value - floor((float)$value)) * 86400);
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        try {
            return Carbon::parse((string)$value)->format('H:i:s');
        } catch (Throwable) {}

        return null;
    }

    protected function calculateHours(?string $in, ?string $out): ?float
    {
        if ($in !== null && $out === null) {
            return 8.0;
        }

        if ($in === null) {
            return null;
        }

        $cIn = Carbon::parse($in);
        $cOut = Carbon::parse($out);

        if ($cOut->lt($cIn)) {
            $cOut->addDay();
        }

        return round($cIn->diffInMinutes($cOut) / 60, 2);
    }

    protected function determineStatus(?string $checkin, ?string $note): string
    {
        if ($note) {
            if (mb_strpos($note, 'إجازة') !== false || mb_strpos($note, 'اجازة') !== false) {
                return Attendance::STATUS_LEAVE;
            }
            if (mb_strpos($note, 'عطلة') !== false) {
                return Attendance::STATUS_HOLIDAY;
            }
        }

        return $checkin !== null ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT;
    }
}

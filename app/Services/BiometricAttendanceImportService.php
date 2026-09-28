<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
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
     * 3. Deduplication:
     *    - Check-in: Earliest punch between 07:00 AM and 10:00 AM.
     *    - Check-out: Latest punch between 04:00 PM (16:00) and 07:00 PM (19:00).
     * 4. Automatic Absence generation: Registered employees with no check-in punch on import dates are marked 'absent'.
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("ملف البصمات غير موجود أو لا يمكن قراءته.");
        }

        $grid = $this->readGrid($path);

        $punchesByEmpAndDate = [];
        $unmatched = [];
        $importedDates = [];

        $employeesMap = Employee::query()
            ->whereNotNull('fingerprint_id')
            ->get()
            ->keyBy('fingerprint_id');

        // Loop through all data rows
        foreach ($grid as $rowIndex => $cells) {
            $valA = $this->sanitizeEncoding($cells['A'] ?? '');
            $valB = $this->sanitizeEncoding($cells['B'] ?? '');
            $valC = $this->sanitizeEncoding($cells['C'] ?? '');
            $valD = $this->sanitizeEncoding($cells['D'] ?? '');
            $valE = $this->sanitizeEncoding($cells['E'] ?? '');
            $valF = $this->sanitizeEncoding($cells['F'] ?? '');
            $valG = $this->sanitizeEncoding($cells['G'] ?? '');
            $valH = $this->sanitizeEncoding($cells['H'] ?? '');

            // Determine Fingerprint ID (Column C or A)
            $fingerprintId = null;
            if (is_numeric($valC)) {
                $fingerprintId = $valC;
            } elseif (is_numeric($valA)) {
                $fingerprintId = $valA;
            }

            if ($fingerprintId === null) {
                continue; // Skip non-numeric header rows
            }

            // Check if format is Summary Format (Check-in in Col C, Check-out in Col D)
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

            // Otherwise: Punch Log Format (1 row per punch)
            $dt = $this->parseDateTime(!empty($valE) ? $valE : $valD);

            if ($dt === null) {
                $datePart = $this->normaliseDate($valD);
                if ($datePart && !empty($valE)) {
                    $dt = $this->parseDateTime($datePart . ' ' . $valE);
                }
            }

            if ($dt === null) {
                continue;
            }

            $date = $dt->toDateString();
            $time = $dt->toTimeString();
            $importedDates[$date] = true;

            // Determine punch direction (In / Out) from Column G or F
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

        // Process punches per employee per day
        foreach ($punchesByEmpAndDate as $fingerprintId => $dates) {
            $employee = $employeesMap[(string) $fingerprintId] ?? null;

            if ($employee === null && !in_array((string) $fingerprintId, $unmatched, true)) {
                $unmatched[] = (string) $fingerprintId;
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

                $rows[(string) $fingerprintId . '|' . $date] = [
                    'employee_id' => $employee?->id,
                    'fingerprint_id' => (string) $fingerprintId,
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

        // Automatic Absence Generation:
        // For each imported date, active employees with no check-in punch are marked as 'absent'
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
        $totalGridRows = count($grid);

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
        ];
    }

    /**
     * Process punches for a single employee on a given day:
     * - Check-in: First punch between 07:00 AM and 10:00 AM.
     * - Check-out: Last punch between 04:00 PM (16:00) and 07:00 PM (19:00).
     */
    protected function processDayPunches(array $punches): array
    {
        $checkIn = null;
        $checkOut = null;
        $notesList = [];

        usort($punches, fn($a, $b) => $a['datetime'] <=> $b['datetime']);

        // Check-In between 07:00 AM and 10:00 AM
        foreach ($punches as $p) {
            $timeStr = $p['time'];
            if ($timeStr >= '07:00:00' && $timeStr <= '10:00:00') {
                $checkIn = $timeStr;
                if (!empty($p['notes'])) $notesList[] = $p['notes'];
                break;
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

        // Check-Out between 16:00 (04:00 PM) and 19:00 (07:00 PM)
        foreach (array_reverse($punches) as $p) {
            $timeStr = $p['time'];
            if ($timeStr >= '16:00:00' && $timeStr <= '19:00:00') {
                $checkOut = $timeStr;
                if (!empty($p['notes'])) $notesList[] = $p['notes'];
                break;
            }
        }

        // Fallback Check-Out: Latest 'out' punch after 12:00 if no punch in 16:00-19:00
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
        if ($value === null) {
            return '';
        }

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
        $spreadsheet = IOFactory::load($path);
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, false, false, true);
            if (count($data) > 0) return $data;
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

        $str = str_replace(['ص', 'م'], ['AM', 'PM'], $str);

        try {
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?))?/i', $str, $matches)) {
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
        if (!$in || !$out) return null;
        $cIn = Carbon::parse($in);
        $cOut = Carbon::parse($out);
        if ($cOut->lt($cIn)) $cOut->addDay();
        return round($cIn->diffInMinutes($cOut) / 60, 2);
    }

    protected function determineStatus(?string $checkIn, ?string $note): string
    {
        if ($note) {
            if (mb_strpos($note, 'اجازة') !== false || mb_strpos($note, 'إجازة') !== false) {
                return Attendance::STATUS_LEAVE;
            }
            if (mb_strpos($note, 'عطلة') !== false) {
                return Attendance::STATUS_HOLIDAY;
            }
        }
        return $checkIn !== null ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT;
    }
}

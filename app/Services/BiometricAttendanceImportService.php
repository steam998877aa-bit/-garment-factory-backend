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
    public function import(string $path, bool $dryRun = false): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("ملف البصمات غير موجود أو لا يمكن قراءته.");
        }

        $grid = $this->readGrid($path);

        $rows = [];
        $errors = [];
        $unmatched = [];
        $currentFingerprintId = null;
        $employees = Employee::query()->pluck('id', 'fingerprint_id');

        foreach ($grid as $index => $cells) {
            $valA = trim((string)($cells['A'] ?? '')); // العمود الأول (رقم البصمة أو الاسم)
            $valB = trim((string)($cells['B'] ?? '')); // العمود الثاني (التاريخ)
            $valC = trim((string)($cells['C'] ?? '')); // الدخول
            $valD = trim((string)($cells['D'] ?? '')); // الخروج
            $valE = trim((string)($cells['E'] ?? '')); // ملاحظات

            // إذا كان العمود الأول رقماً، فهو بداية كتلة موظف جديد
            if (is_numeric($valA)) {
                $currentFingerprintId = $valA;
            }

            // محاولة قراءة التاريخ من العمود الثاني
            $date = $this->normaliseDate($valB);

            // إذا وجدنا تاريخاً وكان لدينا رقم بصمة نشط، نقوم بحفظ السجل
            if ($date && $currentFingerprintId) {
                $checkIn = $this->normaliseTime($valC);
                $checkOut = $this->normaliseTime($valD);

                $employeeId = $employees[$currentFingerprintId] ?? null;
                if ($employeeId === null && !in_array($currentFingerprintId, $unmatched, true)) {
                    $unmatched[] = (string)$currentFingerprintId;
                }

                $rows[] = [
                    'employee_id' => $employeeId,
                    'fingerprint_id' => (string)$currentFingerprintId,
                    'date' => $date,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'working_hours' => $this->calculateHours($checkIn, $checkOut),
                    'status' => $this->parseStatus($checkIn, $valE),
                    'notes' => $valE ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        $rows = $this->deduplicate($rows);
        $matchedCount = count(array_filter($rows, fn($r) => $r['employee_id'] !== null));

        if (!$dryRun) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Attendance::upsert($chunk, ['fingerprint_id', 'date'],
                    ['employee_id', 'check_in', 'check_out', 'working_hours', 'status', 'notes', 'updated_at']
                );
            }
        }

        return [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'parsed' => count($rows),
            'imported' => count($rows),
            'matched' => $matchedCount,
            'failed' => 0, // تجاهل الصفوف غير البياناتية من الحساب الفاشل
            'unmatched_fingerprints' => $unmatched,
            'errors' => $errors
        ];
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
        if (is_numeric($value) && $value > 40000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }
        try {
            // دعم صيغ التواريخ المختلفة
            return Carbon::parse((string)$value)->toDateString();
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
        } catch (Throwable) {
            return null;
        }
    }

    protected function calculateHours(?string $in, ?string $out): ?float
    {
        if (!$in || !$out) return null;
        $cIn = Carbon::parse($in);
        $cOut = Carbon::parse($out);
        if ($cOut->lt($cIn)) $cOut->addDay();
        return round($cIn->diffInMinutes($cOut) / 60, 2);
    }

    protected function parseStatus(?string $checkIn, string $note): string
    {
        if (mb_strpos($note, 'اجازة') !== false || mb_strpos($note, 'إجازة') !== false) return Attendance::STATUS_LEAVE;
        if (mb_strpos($note, 'عطلة') !== false) return Attendance::STATUS_HOLIDAY;
        return $checkIn ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT;
    }

    protected function deduplicate(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $key = $row['fingerprint_id'].'|'.$row['date'];
            $keyed[$key] = $row;
        }
        return array_values($keyed);
    }
}

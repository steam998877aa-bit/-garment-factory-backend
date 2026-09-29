<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class BiometricAttendanceImportService
{
    /**
     * معالجة واستيراد ملف البصمة.
     */
    public function import(string $filePath): array
    {
        $rows = $this->readGrid($filePath);

        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'الملف فارغ أو يتعذر قراءته.',
                'imported_count' => 0,
            ];
        }

        $importedCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                // تجاوز السطر الأول إذا كان يحتوي على عناوين الأعمدة
                if ($index === 0 && isset($row[0]) && !is_numeric($row[0])) {
                    continue;
                }

                $fingerprintId = $this->sanitizeEncoding($row[0] ?? null);
                $rawDate       = $row[1] ?? null;
                $checkInRaw    = $row[2] ?? null;
                $checkOutRaw   = $row[3] ?? null;
                $note          = $this->sanitizeEncoding($row[4] ?? null);

                if (empty($fingerprintId)) {
                    continue;
                }

                $date = $this->normaliseDate($rawDate);
                if (!$date) {
                    $errors[] = "السطر " . ($index + 1) . ": تاريخ غير صالح.";
                    continue;
                }

                $checkIn  = $this->normaliseTime($checkInRaw);
                $checkOut = $this->normaliseTime($checkOutRaw);

                // البحث عن الموظف باستخدام كود البصمة
                $employee = Employee::where('fingerprint_id', $fingerprintId)->first();

                $status       = $this->determineStatus($checkIn, $note);
                $workingHours = $this->calculateHours($checkIn, $checkOut);

                Attendance::updateOrCreate(
                    [
                        'fingerprint_id' => $fingerprintId,
                        'date'           => $date,
                    ],
                    [
                        'employee_id'   => $employee?->id,
                        'check_in'       => $checkIn,
                        'check_out'      => $checkOut,
                        'working_hours'  => $workingHours,
                        'status'         => $status,
                        'notes'          => $note,
                    ]
                );

                $importedCount++;
            }

            DB::commit();

            return [
                'success'        => true,
                'message'        => "تم استيراد {$importedCount} سجل بنجاح.",
                'imported_count' => $importedCount,
                'errors'         => $errors,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Biometric Import Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage(),
                'imported_count' => 0,
            ];
        }
    }

    /**
     * تنظيف ترميز النصوص وتحويلها إلى UTF-8.
     */
    protected function sanitizeEncoding(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', ['GBK', 'GB2312', 'CP936', 'Windows-1256', 'ISO-8859-1']);
        }

        return trim($str);
    }

    /**
     * التحقق مما إذا كانت القيمة نص وقت.
     */
    protected function isTimeString(string $value): bool
    {
        return preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', trim($value)) === 1;
    }

    /**
     * قراءة جداول البيانات من ملف الاكسيل/CSV.
     */
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

        if (!$spreadsheet) {
            return [];
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, false, false, true);
            if (!empty($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * توحيد صيغة التاريخ وتحويله إلى Y-m-d.
     */
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

    /**
     * تحليل النصوص وتحويلها إلى كائن Carbon.
     */
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

        // تحويل رموز الوقت العربية إلى الإنجليزية لدعم Carbon
        $str = str_replace(['ص', 'صباحا', 'صباحاً'], 'AM', $str);
        $str = str_replace(['م', 'مساء', 'مساءً'], 'PM', $str);
        $str = str_replace('/', '-', $str);

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

    /**
     * توحيد صيغة الوقت وتحويله إلى H:i:s.
     */
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

    /**
     * حساب عدد ساعات العمل بناءً على بصمتي الدخول والخروج.
     * في حال وجود بصمة دخول بدون خروج يتم احتساب 8 ساعات افتراضية.
     */
    protected function calculateHours(?string $in, ?string $out): ?float
    {
        // 1. وجود دخول بدون خروج -> احتساب 8 ساعات عمل افتراضية
        if ($in !== null && $out === null) {
            return 8.0;
        }

        // 2. غياب بصمة الدخول بالكامل
        if ($in === null) {
            return null;
        }

        // 3. وجود بصمتي الدخول والخروج معاً -> حساب الساعات الفعلية
        $cIn  = Carbon::parse($in);
        $cOut = Carbon::parse($out);

        // التعامل مع الشفتات الليلية (إذا كان وقت الخروج أصغر من الدخول)
        if ($cOut->lt($cIn)) {
            $cOut->addDay();
        }

        return round($cIn->diffInMinutes($cOut) / 60, 2);
    }

    /**
     * تحديد حالة الحضور بناءً على البصمة والملاحظات المرفقة.
     */
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

        // إرجاع "حاضر" في حال وجود بصمة دخول (سواء وُجد خروج أم لا)، و"غائب" في حال عدم وجودها
        return $checkin !== null ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT;
    }
}
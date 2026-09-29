<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reduces attendance rows to the counts the reports show.
 *
 * These figures are days and hours only. The service was carved out of the
 * former PayrollService when the financial side of the system was removed, so
 * that attendance reporting no longer sits inside a payroll concern.
 */
class AttendanceStatisticsService
{
    /**
     * Attendance statistics for one employee over a month.
     *
     * @return array<string, mixed>
     */
    public function statisticsFor(Employee $employee, int $year, int $month): array
    {
        $rows = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        return $this->summarise($rows, $year, $month);
    }

    /**
     * Reduce a set of attendance rows to the counts a month is reported by.
     *
     * @param Collection<int, Attendance> $rows
     * @return array<string, mixed>
     */
    public function summarise(Collection $rows, int $year, int $month): array
    {
        // معالجة وحساب البيانات لتغطية حالات وجود دخول بدون خروج (الدوام الناقص)
        $processedRows = $rows->map(function ($row) {
            $checkIn  = $row->check_in ?? null;
            $checkOut = $row->check_out ?? null;
            $status   = $row->status ?? null;
            $hours    = $row->working_hours ?? null;

            // إذا وجدت بصمة دخول بدون خروج وكان الموظف غير مسجل كـ "حاضر" أو ساعاته صفر
            if ($checkIn !== null && ($checkOut === null || $checkOut === '')) {
                $row->computed_status = Attendance::STATUS_PRESENT;
                $row->computed_hours  = ($hours && $hours > 0) ? (float)$hours : 8.0;
            } else {
                $row->computed_status = $status;
                $row->computed_hours  = (float)($hours ?? 0);
            }

            return $row;
        });

        $present = $processedRows->where('computed_status', Attendance::STATUS_PRESENT)->count();
        $absent  = $processedRows->where('computed_status', Attendance::STATUS_ABSENT)->count();
        $leave   = $processedRows->where('computed_status', Attendance::STATUS_LEAVE)->count();
        $holiday = $processedRows->where('computed_status', Attendance::STATUS_HOLIDAY)->count();
        
        $hours = round((float) $processedRows->sum('computed_hours'), 2);

        return [
            'year'                         => $year,
            'month'                        => $month,
            'days_in_month'               => Carbon::createFromDate($year, $month, 1)->daysInMonth,
            'recorded_days'                => $processedRows->count(),
            'present_days'                 => $present,
            'absent_days'                  => $absent,
            'leave_days'                   => $leave,
            'holiday_days'                 => $holiday,
            'working_hours'                => $hours,
            'average_hours_per_present_day' => $present > 0 ? round($hours / $present, 2) : 0.0,
        ];
    }
}
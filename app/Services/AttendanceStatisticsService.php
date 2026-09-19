<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
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
     * @param  Collection<int, Attendance>  $rows
     * @return array<string, mixed>
     */
    public function summarise(Collection $rows, int $year, int $month): array
    {
        $present = $rows->where('status', Attendance::STATUS_PRESENT)->count();
        $absent = $rows->where('status', Attendance::STATUS_ABSENT)->count();
        $leave = $rows->where('status', Attendance::STATUS_LEAVE)->count();
        $holiday = $rows->where('status', Attendance::STATUS_HOLIDAY)->count();
        $hours = round((float) $rows->sum('working_hours'), 2);

        return [
            'year' => $year,
            'month' => $month,
            'days_in_month' => Carbon::createFromDate($year, $month, 1)->daysInMonth,
            'recorded_days' => $rows->count(),
            'present_days' => $present,
            'absent_days' => $absent,
            'leave_days' => $leave,
            'holiday_days' => $holiday,
            'working_hours' => $hours,
            'average_hours_per_present_day' => $present > 0 ? round($hours / $present, 2) : 0.0,
        ];
    }
}

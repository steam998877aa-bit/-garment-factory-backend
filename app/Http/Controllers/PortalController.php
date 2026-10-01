<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttendanceResource;
use App\Http\Resources\PortalProfileResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee self-service.
 *
 * Every endpoint here is read-only and scoped to the employee record linked to
 * the authenticated account. No endpoint accepts an employee id - the subject
 * is always derived from the token, so there is no parameter to tamper with
 * and no way to read a colleague's record.
 */
class PortalController extends Controller
{
    public function __construct(
        protected AttendanceStatisticsService $statistics,
    ) {
    }

    /**
     * Dashboard: profile and this month's attendance.
     */
    public function summary(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $stats = $this->statistics->statisticsFor($employee, $year, $month);

        return response()->json([
            'status' => true,
            'message' => "Portal summary for {$employee->name}.",
            'profile' => new PortalProfileResource($employee),
            'vacation_balance' => (float) $employee->vacation_balance,
            'current_month' => $stats,
            'totals' => [
                'present_days'  => $stats['present_days'] ?? 0,
                'absent_days'   => $stats['absent_days'] ?? 0,
                'working_hours' => round((float) ($stats['working_hours'] ?? 0), 2),
                'average_hours' => round((float) ($stats['average_hours_per_present_day'] ?? 0), 2),
            ],
        ]);
    }

    /**
     * The employee's own profile and remaining vacation balance.
     */
    public function profile(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json([
            'status' => true,
            'message' => 'Your profile.',
            'data' => new PortalProfileResource($employee),
        ]);
    }

    /**
     * The employee's own attendance history.
     */
    public function attendance(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $filters = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'year'      => ['sometimes', 'integer', 'between:2000,2100'],
            'month'     => ['sometimes', 'integer', 'between:1,12'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $year  = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);

        // حساب الإحصائيات الشاملة باستخدام AttendanceStatisticsService
        $stats = $this->statistics->statisticsFor($employee, $year, $month);

        $query = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->when(isset($filters['date_from']), fn ($q) => $q->whereDate('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->whereDate('date', '<=', $filters['date_to']))
            ->when(isset($filters['year']), fn ($q) => $q->whereYear('date', $filters['year']))
            ->when(isset($filters['month']), fn ($q) => $q->whereMonth('date', $filters['month']))
            ->orderByDesc('date');

        $records = $query->paginate($filters['per_page'] ?? 31)->withQueryString();

        return response()->json([
            'status'  => true,
            'message' => "Found {$records->total()} attendance day(s).",
            'filters' => $filters,
            'totals'  => [
                'present_days'  => $stats['present_days'] ?? 0,
                'absent_days'   => $stats['absent_days'] ?? 0,
                'days'          => $stats['present_days'] ?? 0,
                'working_hours' => round((float) ($stats['working_hours'] ?? 0), 2),
                'average_hours' => round((float) ($stats['average_hours_per_present_day'] ?? 0), 2),
            ],
            'statistics' => $stats,
            'data'  => AttendanceResource::collection($records->items()),
            'meta'  => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    /**
     * Redirect to the employee's Cloudinary ID card or return a missing-file response.
     */
    public function idCard(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $path = $this->employee($request)->id_card_image;
        if (! $this->isHttpUrl($path)) {
            return $this->missingDocumentResponse();
        }

        return redirect()->away($path);
    }

    /**
     * Redirect to the employee's Cloudinary CV or return a missing-file response.
     */
    public function cv(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $path = $this->employee($request)->cv_file;
        if (! $this->isHttpUrl($path)) {
            return $this->missingDocumentResponse();
        }

        return redirect()->away($path);
    }

    /**
     * Resolve the staff record behind the authenticated account.
     */
    protected function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if(
            $employee === null,
            JsonResponse::HTTP_FORBIDDEN,
            'This account is not linked to an employee record.'
        );

        return $employee;
    }

    protected function isHttpUrl(?string $path): bool
    {
        return filter_var($path, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($path, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    protected function missingDocumentResponse(): JsonResponse
    {
        return response()->json([
            'url' => null,
            'message' => 'الملف غير موجود، يرجى إعادة الرفع',
        ], JsonResponse::HTTP_NOT_FOUND);
    }
}

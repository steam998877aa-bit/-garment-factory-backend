<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttendanceResource;
use App\Http\Resources\PortalProfileResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceStatisticsService;
use App\Services\EmployeeFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee self-service.
 *
 * Every endpoint here is read-only and scoped to the employee record linked to
 * the authenticated account. No endpoint accepts an employee id — the subject
 * is always derived from the token, so there is no parameter to tamper with
 * and no way to read a colleague's record.
 */
class PortalController extends Controller
{
    public function __construct(protected AttendanceStatisticsService $statistics)
    {
    }

    /**
     * Dashboard: profile and this month's attendance.
     */
    public function summary(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $year = (int) now()->year;
        $month = (int) now()->month;

        return response()->json([
            'status' => true,
            'message' => "Portal summary for {$employee->name}.",
            'profile' => new PortalProfileResource($employee),
            'vacation_balance' => (float) $employee->vacation_balance,
            'current_month' => $this->statistics->statisticsFor($employee, $year, $month),
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
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->when(isset($filters['date_from']), fn ($q) => $q->whereDate('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->whereDate('date', '<=', $filters['date_to']))
            ->when(isset($filters['year']), fn ($q) => $q->whereYear('date', $filters['year']))
            ->when(isset($filters['month']), fn ($q) => $q->whereMonth('date', $filters['month']))
            ->orderByDesc('date');

        $totals = (clone $query)
            ->reorder()
            ->selectRaw('count(*) as days, coalesce(sum(working_hours), 0) as hours')
            ->first();

        $records = $query->paginate($filters['per_page'] ?? 31)->withQueryString();

        return response()->json([
            'status' => true,
            'message' => "Found {$records->total()} attendance day(s).",
            'filters' => $filters,
            'totals' => [
                'days' => (int) ($totals->days ?? 0),
                'working_hours' => round((float) ($totals->hours ?? 0), 2),
            ],
            'data' => AttendanceResource::collection($records->items()),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * The employee's own ID card scan.
     */
    public function idCard(Request $request): StreamedResponse
    {
        return $this->streamOwnDocument(
            $this->employee($request)->id_card_image,
            'You have no ID card on file.',
        );
    }

    /**
     * The employee's own CV.
     */
    public function cv(Request $request): StreamedResponse
    {
        return $this->streamOwnDocument(
            $this->employee($request)->cv_file,
            'You have no CV on file.',
        );
    }

    /**
     * Resolve the staff record behind the authenticated account.
     *
     * An Admin or HR login with no linked employee record has nothing to show
     * here, which is a 403 rather than an error: the account is valid, it just
     * is not an employee.
     */
    protected function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if(
            $employee === null,
            JsonResponse::HTTP_FORBIDDEN,
            'This account is not linked to an employee record.',
        );

        return $employee;
    }

    /**
     * Send one of the employee's own documents.
     */
    protected function streamOwnDocument(?string $path, string $missingMessage): StreamedResponse
    {
        abort_if($path === null, JsonResponse::HTTP_NOT_FOUND, $missingMessage);
        $disk = Storage::disk(EmployeeFileService::DISK);
        abort_unless($disk->exists($path), JsonResponse::HTTP_NOT_FOUND, 'The stored file is missing.');

        return $disk->response(
            $path,
            basename($path),
            ['Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream'],
            'inline',
        );
    }
}

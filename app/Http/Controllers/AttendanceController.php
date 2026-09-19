<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\BiometricAttendanceImportService;
use App\Services\AttendanceStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AttendanceController extends Controller
{
    use ConfirmsPassword;

    public function __construct(
        protected BiometricAttendanceImportService $importer,
        protected AuditLogger $audit,
        protected AttendanceStatisticsService $statistics,
    ) {
    }

    /**
     * Import an attendance export produced by the biometric application.
     */
    public function importBiometric(Request $request): JsonResponse
    {
        $this->authorize('import', Attendance::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            // Not the 'boolean' rule: it accepts only 0 and 1, and in a
            // multipart body every field arrives as a string, so a client
            // sending true was rejected. boolean() below reads them all.
            'dry_run' => ['sometimes', 'in:0,1,true,false,TRUE,FALSE,yes,no,on,off'],
            // Verified by confirmPassword(); declared so it passes validation.
            'password' => ['sometimes', 'string'],
        ]);

        // A real import overwrites recorded attendance days, so it is
        // re-authenticated. A dry run changes nothing and is not.
        if (! $request->boolean('dry_run')) {
            $this->confirmPassword($request);
        }

        $dryRun = $request->boolean('dry_run');
        $upload = $request->file('file');

        // PHP names the uploaded temp file without an extension. Preserve the
        // original xls/xlsx extension so PhpSpreadsheet selects the right reader.
        $extension = strtolower($upload->getClientOriginalExtension());
        $stored = $upload->storeAs(
            'attendance-imports',
            Str::uuid()->toString() . '.' . $extension,
            'local',
        );

        try {
            $result = $this->importer->import(
                Storage::disk('local')->path($stored),
                $dryRun,
            );
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The attendance file could not be read. Please verify that it is a valid xls, xlsx, csv, or txt file.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            Storage::disk('local')->delete($stored);
        }

        // Report the name the user recognises, not the generated one.
        $result['file'] = $upload->getClientOriginalName();

        if (! $dryRun) {
            $this->audit->log('attendance.imported', [
                'file' => $result['file'],
                'imported' => $result['imported'],
                'matched' => $result['matched'],
                'failed' => $result['failed'],
                'unmatched_fingerprints' => $result['unmatched_fingerprints'],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => $dryRun
                ? "Validated {$result['parsed']} record(s). Nothing was saved."
                : "Imported {$result['imported']} attendance record(s).",
            'summary' => $result,
        ]);
    }

    /**
     * Record a day by hand, for staff the biometric device missed.
     *
     * Keyed on fingerprint and date like the importer, so recording a day that
     * already exists corrects it rather than creating a duplicate.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate([
            'employee_id' => ['required_without:fingerprint_id', 'integer', 'exists:employees,id'],
            'fingerprint_id' => ['required_without:employee_id', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'check_in' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'check_out' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'working_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:24'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', Attendance::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A shift that ends earlier than it starts has crossed midnight.
            // Saying so explicitly keeps a mistyped time from being read as a
            // sixteen hour shift.
            'overnight' => ['sometimes', 'boolean'],
        ]);

        $this->assertDateIsRecordable($data);
        $this->assertTimesAreCoherent($data, $request->boolean('overnight'));

        $employee = isset($data['employee_id'])
            ? Employee::findOrFail($data['employee_id'])
            : Employee::where('fingerprint_id', $data['fingerprint_id'])->first();

        $fingerprint = $data['fingerprint_id'] ?? $employee?->fingerprint_id;

        if ($fingerprint === null) {
            return response()->json([
                'status' => false,
                'message' => 'Could not determine a fingerprint id for this record.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attributes = [
            'employee_id' => $employee?->getKey(),
            'check_in' => $this->normaliseTime($data['check_in'] ?? null),
            'check_out' => $this->normaliseTime($data['check_out'] ?? null),
            'notes' => $data['notes'] ?? null,
        ];

        $attributes['status'] = $data['status']
            ?? ($attributes['check_in'] === null ? Attendance::STATUS_ABSENT : Attendance::STATUS_PRESENT);

        $attributes['working_hours'] = $data['working_hours']
            ?? $this->deriveHours($attributes['check_in'], $attributes['check_out'], $request->boolean('overnight'));

        $attendance = Attendance::updateOrCreate(
            ['fingerprint_id' => $fingerprint, 'date' => $data['date']],
            $attributes,
        );

        $this->audit->log($attendance->wasRecentlyCreated ? 'attendance.recorded' : 'attendance.corrected', [
            'attendance_id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'fingerprint_id' => $attendance->fingerprint_id,
            'date' => $data['date'],
            'status' => $attendance->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => $attendance->wasRecentlyCreated
                ? 'Attendance recorded.'
                : 'Attendance updated for this day.',
            'data' => new AttendanceResource($attendance->load('employee')),
        ], $attendance->wasRecentlyCreated ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }

    /**
     * Correct a single attendance row.
     */
    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('update', $attendance);

        $data = $request->validate([
            'check_in' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'check_out' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'working_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:24'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', Attendance::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'overnight' => ['sometimes', 'boolean'],
        ]);

        foreach (['check_in', 'check_out'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normaliseTime($data[$field]);
            }
        }

        $attendance->fill($data);

        // Recompute hours when the times moved and no explicit figure was given.
        if (! array_key_exists('working_hours', $data)) {
            $this->assertTimesAreCoherent(
                ['check_in' => $attendance->check_in, 'check_out' => $attendance->check_out],
                $request->boolean('overnight'),
            );

            $attendance->working_hours = $this->deriveHours(
                $attendance->check_in,
                $attendance->check_out,
                $request->boolean('overnight'),
            );
        }

        $attendance->save();

        $this->audit->log('attendance.corrected', [
            'attendance_id' => $attendance->id,
            'fingerprint_id' => $attendance->fingerprint_id,
            'date' => $attendance->date?->toDateString(),
            'changed' => array_keys($attendance->getChanges()),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Attendance updated.',
            'data' => new AttendanceResource($attendance->load('employee')),
        ]);
    }

    /**
     * Attendance statistics for a month — per employee, or for one of them.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);

        $filters = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'department' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        $year = $filters['year'] ?? (int) now()->year;
        $month = $filters['month'] ?? (int) now()->month;

        $employees = Employee::query()
            ->when(isset($filters['employee_id']), fn ($q) => $q->whereKey($filters['employee_id']))
            ->when(isset($filters['department']), fn ($q) => $q->where('department', $filters['department']))
            ->orderBy('name')
            ->get();

        $rows = Attendance::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('employee_id');

        $data = $employees->map(function (Employee $employee) use ($rows, $year, $month): array {
            $summary = $this->statistics->summarise(
                $rows->get($employee->getKey()) ?? collect(),
                $year,
                $month,
            );

            return [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'fingerprint_id' => $employee->fingerprint_id,
                    'department' => $employee->department,
                ],
            ] + $summary;
        });

        return response()->json([
            'status' => true,
            'message' => "Statistics for {$data->count()} employee(s), " . sprintf('%04d-%02d', $year, $month) . '.',
            'period' => ['year' => $year, 'month' => $month],
            'totals' => [
                'present_days' => (int) $data->sum('present_days'),
                'absent_days' => (int) $data->sum('absent_days'),
                'leave_days' => (int) $data->sum('leave_days'),
                'working_hours' => round((float) $data->sum('working_hours'), 2),
            ],
            'data' => $data->values()->all(),
        ]);
    }

    /**
     * Pad a H:i time to H:i:s, leaving nulls alone.
     */
    protected function normaliseTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    /**
     * Hours between two times, allowing for a shift that crosses midnight.
     */
    protected function deriveHours(?string $checkIn, ?string $checkOut, bool $overnight = false): ?float
    {
        if ($checkIn === null || $checkOut === null) {
            return null;
        }

        $in = Carbon::createFromFormat('H:i:s', $checkIn);
        $out = Carbon::createFromFormat('H:i:s', $checkOut);

        if ($overnight && $out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        return round($in->diffInMinutes($out) / 60, 2);
    }

    /**
     * Attendance cannot be recorded for a day that has not happened.
     *
     * Planned leave and holidays are the exception — those are booked ahead.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertDateIsRecordable(array $data): void
    {
        $date = Carbon::createFromFormat('Y-m-d', $data['date']);
        $status = $data['status'] ?? null;
        $plannable = in_array($status, [Attendance::STATUS_LEAVE, Attendance::STATUS_HOLIDAY], true);

        if ($date->isFuture() && ! $plannable) {
            throw ValidationException::withMessages([
                'date' => ['Attendance cannot be recorded for a future date. Only leave and holidays may be booked ahead.'],
            ]);
        }

        if ($date->greaterThan(now()->addYear())) {
            throw ValidationException::withMessages([
                'date' => ['This date is too far in the future.'],
            ]);
        }
    }

    /**
     * Reject a check-out earlier than the check-in unless it is declared as an
     * overnight shift, so a mistyped time is not silently doubled.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertTimesAreCoherent(array $data, bool $overnight): void
    {
        $in = $data['check_in'] ?? null;
        $out = $data['check_out'] ?? null;

        if ($in === null || $out === null || $overnight) {
            return;
        }

        $in = strlen($in) === 5 ? $in . ':00' : $in;
        $out = strlen($out) === 5 ? $out . ':00' : $out;

        if ($out <= $in) {
            throw ValidationException::withMessages([
                'check_out' => ['Check-out is not after check-in. Pass overnight=true if the shift crossed midnight.'],
            ]);
        }
    }
    /**
     * Search attendance records.
     *
     * Every filter is optional and they combine: a fingerprint id or employee
     * narrows to one person, `date` pins a single day, and `date_from`/`date_to`
     * bound a range such as a whole month.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);

        $filters = $request->validate([
            'fingerprint_id' => ['sometimes', 'string', 'max:255'],
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'unmatched_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Attendance::query()
            ->with('employee')
            ->when(
                isset($filters['fingerprint_id']),
                fn ($q) => $q->where('fingerprint_id', $filters['fingerprint_id']),
            )
            ->when(
                isset($filters['employee_id']),
                fn ($q) => $q->where('employee_id', $filters['employee_id']),
            )
            ->when(
                isset($filters['date']),
                fn ($q) => $q->whereDate('date', $filters['date']),
            )
            ->when(
                isset($filters['date_from']),
                fn ($q) => $q->whereDate('date', '>=', $filters['date_from']),
            )
            ->when(
                isset($filters['date_to']),
                fn ($q) => $q->whereDate('date', '<=', $filters['date_to']),
            )
            ->when(
                $request->boolean('unmatched_only'),
                fn ($q) => $q->whereNull('employee_id'),
            )
            ->orderByDesc('date')
            ->orderBy('fingerprint_id');

        // Totals describe the whole filtered set, not just the current page.
        $totals = (clone $query)
            ->reorder()
            ->selectRaw('count(*) as days, coalesce(sum(working_hours), 0) as hours')
            ->first();

        $records = $query->paginate($filters['per_page'] ?? 50)->withQueryString();

        return response()->json([
            'status' => true,
            'message' => $records->total() > 0
                ? "Found {$records->total()} attendance record(s)."
                : 'No attendance records match these filters.',
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
}

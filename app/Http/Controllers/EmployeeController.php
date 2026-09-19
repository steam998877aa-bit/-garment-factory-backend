<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceStatisticsService;
use App\Services\AuditLogger;
use App\Services\EmployeeFileService;
use App\Services\EmployeeImportService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    use ConfirmsPassword;

    public function __construct(
        protected AuditLogger $audit,
        protected EmployeeFileService $files,
        protected AttendanceStatisticsService $statistics,
        protected EmployeeImportService $importer,
    ) {
    }

    /**
     * List employees.
     *
     * Query parameters: department, status, position, shift, search, per_page.
     *
     * The department filter is an exact match on the employee's own
     * department, which is a free-text HR field with its own vocabulary
     * (الإدارة، الموارد البشرية، …). It is deliberately *not* resolved against
     * the production departments reference table: the two lists name different
     * things and must not be conflated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', $this->statusRule()],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shift' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        // Resolved so ?status=inactive finds the rows stored as `resigned`.
        $status = Employee::canonicalStatus($filters['status'] ?? null);

        $employees = Employee::query()
            ->when(isset($filters['department']), fn ($query) => $query->where('department', trim($filters['department'])))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['position']), fn ($query) => $query->where('position', trim($filters['position'])))
            ->when(isset($filters['shift']), fn ($query) => $query->where('shift', trim($filters['shift'])))
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = '%' . $filters['search'] . '%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('fingerprint_id', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('department', 'like', $term)
                        ->orWhere('position', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /**
     * Import the staff workbook.
     *
     * Employees are keyed on their fingerprint id, so re-importing a corrected
     * file updates the people it already created rather than duplicating them.
     * That also means a real import rewrites records already in the table,
     * which is why it is re-authenticated — the same bar as editing one person
     * by hand. A dry run writes nothing and is not.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('import', Employee::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
            // Not the 'boolean' rule: it accepts only 0 and 1, and in a
            // multipart body every field arrives as a string.
            'dry_run' => ['sometimes', 'in:0,1,true,false,TRUE,FALSE,yes,no,on,off'],
            // Verified by confirmPassword(); declared so it passes validation.
            'password' => ['sometimes', 'string'],
        ]);

        $dryRun = $request->boolean('dry_run');

        if (! $dryRun) {
            $this->confirmPassword($request);
        }

        $upload = $request->file('file');

        // PHP names the uploaded temp file without an extension, which leaves
        // PhpSpreadsheet guessing at the format. Park it on disk under its real
        // extension for the duration of the import instead.
        $stored = $upload->store('employee-imports', 'local');

        try {
            $result = $this->importer->import(Storage::disk('local')->path($stored), $dryRun);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            Storage::disk('local')->delete($stored);
        }

        // Report the name the user recognises, not the generated one.
        $result['file'] = $upload->getClientOriginalName();

        // Every tab skipped for want of a header means this is not the staff
        // workbook. Saying so beats reporting a successful import of nothing.
        if ($result['data_rows'] === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No staff rows were found. Check this is the staff workbook — every sheet was skipped.',
                'summary' => $result,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $dryRun) {
            $this->audit->log('employees.imported', [
                'file' => $result['file'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'rejected' => $result['rejected'],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => $dryRun
                ? "Validated {$result['data_rows']} row(s). Nothing was saved."
                : "Imported {$result['created']} new and updated {$result['updated']} existing employee(s).",
            'summary' => $result,
        ]);
    }

    /**
     * Show a single employee.
     */
    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee);
    }

    /**
     * Create an employee record.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $data = $this->validateEmployee($request);

        $files = $this->pullFiles($data);

        $employee = Employee::create($data);

        $this->storeFiles($employee, $files);

        $this->audit->log('employee.created', [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'fingerprint_id' => $employee->fingerprint_id,
            'department' => $employee->department,
        ]);

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Update an employee record.
     *
     * Sensitive: the caller must re-enter their own password.
     */
    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);
        $this->confirmPassword($request);

        $data = $this->validateEmployee($request, $employee);

        $files = $this->pullFiles($data);
        $before = $employee->getOriginal();

        $employee->update($data);

        $this->storeFiles($employee, $files);

        $this->audit->logUpdate('employee.updated', $employee, $before, [
            'employee_id' => $employee->id,
            'name' => $employee->name,
        ]);

        return new EmployeeResource($employee);
    }

    /**
     * Delete an employee. Admin only, and requires password confirmation.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);
        $this->confirmPassword($request);

        $snapshot = [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'fingerprint_id' => $employee->fingerprint_id,
            'department' => $employee->department,
        ];

        // Payroll was removed from the system, but the table it left behind is
        // still constrained against employees. Where historical statements
        // survive, this reports a clean conflict rather than letting the
        // foreign key surface as a 500.
        if (Schema::hasTable('payrolls')
            && DB::table('payrolls')->where('employee_id', $employee->getKey())->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'This employee has historical payroll records and cannot be deleted.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        $this->files->deleteAll($employee);

        $employee->delete();

        $this->audit->log('employee.deleted', $snapshot);

        return response()->json([
            'status' => true,
            'message' => 'Employee deleted.',
        ]);
    }

    /**
     * Stream the employee's ID card to an authorised caller.
     */
    public function idCard(Employee $employee): StreamedResponse
    {
        return $this->streamDocument($employee->id_card_image, 'This employee has no ID card on file.');
    }

    /**
     * Stream the employee's CV to an authorised caller.
     */
    public function cv(Employee $employee): StreamedResponse
    {
        return $this->streamDocument($employee->cv_file, 'This employee has no CV on file.');
    }

    /**
     * Employee report: profile plus attendance for a month.
     */
    public function report(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $filters = $request->validate([
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        $year = $filters['year'] ?? (int) now()->year;
        $month = $filters['month'] ?? (int) now()->month;

        $statistics = $this->statistics->statisticsFor($employee, $year, $month);

        return response()->json([
            'status' => true,
            'message' => "Report for {$employee->name}, " . sprintf('%04d-%02d', $year, $month) . '.',
            'employee' => new EmployeeResource($employee),
            'attendance' => $statistics,
        ]);
    }

    /**
     * Create a self-service portal login for an employee.
     *
     * Issues an account carrying the Employee role, linked to this staff
     * record. The temporary password is returned once and never stored in
     * readable form — it cannot be recovered afterwards, only reset.
     */
    public function createPortalAccount(Request $request, Employee $employee): JsonResponse
    {
        // Creating credentials for someone else is an Admin action.
        $this->authorize('delete', $employee);
        $this->confirmPassword($request);

        if ($employee->user()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'This employee already has a portal account.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        $data = $request->validate([
            'username' => ['sometimes', 'string', 'max:255', 'unique:users,username'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email'],
            'password' => ['sometimes', 'string'],
        ]);

        $email = $data['email'] ?? $employee->email;

        if ($email === null) {
            return response()->json([
                'status' => false,
                'message' => 'This employee has no email address; supply one to create the account.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'A user account already exists with this email address.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        $role = Role::where('name', 'Employee')->first();

        if ($role === null) {
            return response()->json([
                'status' => false,
                'message' => 'The Employee role is missing. Run the role seeder first.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        $username = $data['username'] ?? $this->portalUsernameFor($employee);
        $temporaryPassword = Str::password(12);

        $user = User::create([
            'name' => $employee->name,
            'username' => $username,
            'email' => $email,
            'password' => $temporaryPassword,
            'role_id' => $role->getKey(),
            'employee_id' => $employee->getKey(),
        ]);

        $this->audit->log('employee.portal_account_created', [
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'username' => $user->username,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Portal account created. Give the employee this password — it is not recoverable.',
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $role->name,
                'temporary_password' => $temporaryPassword,
            ],
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * A free username for an employee's portal login.
     */
    protected function portalUsernameFor(Employee $employee): string
    {
        $base = Str::slug($employee->fingerprint_id) ?: 'employee' . $employee->getKey();
        $candidate = $base;
        $suffix = 1;

        while (User::where('username', $candidate)->exists()) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }
    /**
     * Take any uploaded files out of the validated data.
     *
     * They are columns on the model but must not be mass assigned — the stored
     * value is a path, not the upload itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, \Illuminate\Http\UploadedFile>
     */
    protected function pullFiles(array &$data): array
    {
        $files = [];

        foreach (['id_card_image', 'cv_file'] as $field) {
            if (isset($data[$field])) {
                $files[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        return $files;
    }

    /**
     * Persist uploaded documents against an employee.
     *
     * @param  array<string, \Illuminate\Http\UploadedFile>  $files
     */
    protected function storeFiles(Employee $employee, array $files): void
    {
        if ($files === []) {
            return;
        }

        if (isset($files['id_card_image'])) {
            $employee->id_card_image = $this->files->storeIdCard($employee, $files['id_card_image']);
        }

        if (isset($files['cv_file'])) {
            $employee->cv_file = $this->files->storeCv($employee, $files['cv_file']);
        }

        $employee->save();
    }

    /**
     * Send a stored document, or 404 when it is absent.
     */
    protected function streamDocument(?string $path, string $missingMessage): StreamedResponse
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
    /**
     * @return array<string, mixed>
     */
    protected function validateEmployee(Request $request, ?Employee $employee = null): array
    {
        $unique = 'unique:employees,fingerprint_id'.($employee ? ','.$employee->getKey() : '');
        $creating = $employee === null;

        $validated = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'fingerprint_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', $unique],
            // The employee's own HR department — free text with its own
            // vocabulary, unrelated to the production departments table.
            // Required on create, and accepted on update so a transfer between
            // HR departments is saved and shows in the employee's details.
            'department' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            // Optional on create: an employee being added is working here, so
            // the column defaults to active rather than making every caller
            // state the obvious.
            'status' => ['sometimes', 'nullable', 'string', $this->statusRule()],
            'documents' => ['nullable', 'string'],

            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:employees,email' . ($employee ? ',' . $employee->getKey() : '')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shift' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vacation_balance' => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],

            'id_card_image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'cv_file' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],
            // Accepted so the confirmation field does not fail validation; it is
            // verified by confirmPassword(), never written to the model.
            'password' => ['sometimes', 'string'],
        ]);

        unset($validated['password']);

        // Store the canonical spelling, never the alias the caller happened to
        // use. An empty status is dropped rather than written: on create the
        // column default applies, and on update it means "leave it alone".
        if (array_key_exists('status', $validated)) {
            $status = Employee::canonicalStatus($validated['status']);

            if ($status === null) {
                unset($validated['status']);
            } else {
                $validated['status'] = $status;
            }
        }

        return $validated;
    }

    /**
     * Validation rule accepting any known status, alias or casing.
     *
     * Written as a closure rather than `in:` so `inactive` and `Active` are
     * accepted the same way the query filter accepts them, while the message
     * still names the values the API actually stores.
     */
    protected function statusRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || Employee::canonicalStatus($value) === null) {
                $fail(sprintf(
                    'The selected %s is invalid. Accepted values are: %s (inactive is accepted as resigned).',
                    $attribute,
                    implode(', ', Employee::STATUSES),
                ));
            }
        };
    }

}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Resources\ProductionResource;
use App\Models\AuditLog;
use App\Models\Production;
use App\Services\AuditLogger;
use App\Services\DepartmentSerialNumberService;
use App\Services\MonthlyProductionImportService;
use App\Services\NameNormalizer;
use App\Services\ProductFileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionController extends Controller
{
    use ConfirmsPassword;

    public function __construct(
        protected DepartmentSerialNumberService $serials,
        protected AuditLogger $audit,
        protected ProductFileService $files,
        protected NameNormalizer $names,
        protected MonthlyProductionImportService $importer,
    ) {
    }

    /**
     * Import the monthly production workbook.
     *
     * Rows are keyed on their origin in the sheet — tab name plus row number —
     * so re-importing a file updates the rows it already created rather than
     * duplicating them. That also means a real import rewrites work already in
     * the table, which is why it is re-authenticated. A dry run writes nothing
     * and is not.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('import', Production::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
            // Not the 'boolean' rule: it accepts only 0 and 1, and in a
            // multipart body every field arrives as a string, so a client
            // sending true was rejected. boolean() below reads them all.
            'dry_run' => ['sometimes', 'in:0,1,true,false,TRUE,FALSE,yes,no,on,off'],
            // The workbook records no date of its own; pass the day it covers.
            'production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
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
        $stored = $upload->store('production-imports', 'local');

        try {
            $result = $this->importer->import(
                Storage::disk('local')->path($stored),
                $dryRun,
                $request->input('production_date') ?: null,
            );
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

        // Every sheet skipped for want of a header means this is not the
        // monthly workbook. Saying so beats reporting a successful import of
        // nothing at all.
        if ($result['data_rows'] === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No production rows were found. Check this is the monthly workbook — every sheet was skipped.',
                'summary' => $result,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $dryRun) {
            $this->audit->log('production.imported', [
                'file' => $result['file'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'departments' => $result['departments'],
                'pieces' => $result['pieces'],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => $dryRun
                ? "Validated {$result['data_rows']} row(s). Nothing was saved."
                : "Imported {$result['created']} new and updated {$result['updated']} existing row(s).",
            'summary' => $result,
        ]);
    }

    /**
     * List productions, ordered as the department lists read on paper.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Production::class);

        $filters = $request->validate($this->filterRules());

        $productions = $this->filtered($request, $filters)
            ->orderBy('department')
            ->orderBy('serial_number')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ProductionResource::collection($productions);
    }

    /**
     * Show one production with its full transfer journey.
     */
    public function show(Production $production): ProductionResource
    {
        $this->authorize('view', $production);

        return new ProductionResource($production->load(['transfers.transferredBy']));
    }

    /**
     * Create a product and place it at the end of its department list.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Production::class);

        $data = $request->validate($this->rules());

        $images = $this->imageUploads($request);
        $guides = $this->guideUploads($request);

        $data['design_status'] ??= '';
        $data = $this->canonicalisePlacement($data);

        $production = Production::create($data);

        if ($images !== null) {
            $production->images = $this->files->storeImages($images);
        }

        if ($guides !== null) {
            $production->guide_files = $this->files->storeGuideFiles($guides);
        }

        if ($images !== null || $guides !== null) {
            $production->save();
        }

        $this->serials->assign($production);

        $this->audit->log('production.created', [
            'production_id' => $production->id,
            'barcode' => $production->barcode,
            'item_number' => $production->item_number,
            'department' => $production->department,
            'serial_number' => $production->serial_number,
            'images' => count($production->images ?? []),
            'guide_files' => count($production->guide_files ?? []),
        ]);

        return (new ProductionResource($production))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Update a product.
     *
     * Department changes are deliberately not accepted here — moving an item
     * between departments goes through the transfer endpoint so the journey is
     * always recorded in the ledger.
     *
     * Uploaded images and guide files are added to the product rather than
     * replacing what is already there; existing ones are removed explicitly via
     * remove_images and remove_guide_files, so a partial update can never
     * silently discard a photograph or a routing sheet.
     */
    public function update(Request $request, Production $production): ProductionResource
    {
        $this->authorize('update', $production);

        $data = $request->validate($this->rules($production));

        // Captured before the write: Eloquent resyncs originals during save.
        $before = $production->getOriginal();

        $images = $this->imageUploads($request);
        $guides = $this->guideUploads($request);
        $remove = $data['remove_images'] ?? null;
        $removeGuides = $data['remove_guide_files'] ?? null;
        unset(
            $data['remove_images'],
            $data['remove_guide_files'],
        );

        $production->fill($data);

        if ($remove !== null) {
            $production->images = $this->files->removeImages($production, $remove);
        }

        if ($images !== null) {
            $production->images = array_merge(
                $production->images ?? [],
                $this->files->storeImages($images),
            );
        }

        if ($removeGuides !== null) {
            $production->guide_files = $this->files->removeGuideFiles($production, $removeGuides);
        }

        if ($guides !== null) {
            $production->guide_files = array_merge(
                $production->guide_files ?? [],
                $this->files->storeGuideFiles($guides),
            );
        }

        $production->save();

        $this->audit->logUpdate('production.updated', $production, $before, [
            'production_id' => $production->id,
            'barcode' => $production->barcode,
        ]);

        return new ProductionResource($production);
    }

    /**
     * Delete a product that has no transfer history.
     */
    public function destroy(Request $request, Production $production): JsonResponse
    {
        $this->authorize('delete', $production);
        $this->confirmPassword($request);

        if ($production->transfers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن حذف منتج له سجل تحويلات.',
            ], JsonResponse::HTTP_CONFLICT);
        }

        $snapshot = [
            'production_id' => $production->id,
            'barcode' => $production->barcode,
            'item_number' => $production->item_number,
            'department' => $production->department,
            'serial_number' => $production->serial_number,
        ];

        $this->files->deleteAll($production);
        $production->delete();

        $this->audit->log('production.deleted', $snapshot);

        return response()->json([
            'status' => true,
            'message' => 'تم حذف المنتج.',
        ]);
    }

    /**
     * Stream one of the product's images to an authorised caller.
     */
    public function image(Request $request, Production $production, int $index): StreamedResponse
    {
        $path = ($production->images ?? [])[$index] ?? null;

        abort_if($path === null, JsonResponse::HTTP_NOT_FOUND, 'Image not found.');
        abort_unless(Storage::disk(ProductFileService::DISK)->exists($path), JsonResponse::HTTP_NOT_FOUND, 'Image file is missing.');

        return Storage::disk(ProductFileService::DISK)->response($path);
    }

    /**
     * Stream one of the product's guide files to an authorised caller.
     *
     * The index is optional so the original single-file URL keeps working: with
     * no index it serves the first guide file, which is the only one a product
     * had before guide files became a list.
     */
    public function guideFile(Request $request, Production $production, ?int $index = null): StreamedResponse
    {
        $path = ($production->guide_files ?? [])[$index ?? 0] ?? null;

        abort_if($path === null, JsonResponse::HTTP_NOT_FOUND, 'This product has no guide file at that position.');
        $disk = Storage::disk(ProductFileService::DISK);
        abort_unless($disk->exists($path), JsonResponse::HTTP_NOT_FOUND, 'Guide file is missing.');

        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

        return $disk->response(
            $path,
            basename($path),
            ['Content-Type' => $mimeType],
            'inline',
        );
    }

    /**
     * The guide files on this request, whichever field name they arrived under.
     *
     * Guide files are a list now, but a client may send them as `guide_file`
     * (one file — the shape this API used to take), `guide_file[]`, or
     * `guide_files[]`. All three are read here so the rest of the controller
     * only ever deals with a list.
     *
     * They are validated here rather than in rules() because the request itself
    /**
     * Product image uploads on this request, under whichever field name they arrived.
     *
     * Accepts `images`, `images[]`, `image`, or `images[0]`.
     *
     * @return list<UploadedFile>|null
     */
    protected function imageUploads(Request $request): ?array
    {
        $files = array_merge(
            $this->uploadList($request->file('images')),
            $this->uploadList($request->file('image')),
        );

        if ($files === []) {
            return null;
        }

        Validator::make(['images' => $files], [
            'images' => ['array', 'max:10'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ], [
            'images.*.file' => 'فشل رفع صورة المنتج. يرجى التحقق من حجم الصورة ونوعها.',
            'images.*.uploaded' => 'فشل رفع صورة المنتج. يرجى التحقق من حجم الصورة ونوعها.',
            'images.*.mimes' => 'يجب أن تكون صورة المنتج من نوع: jpg, jpeg, png, webp.',
            'images.*.max' => 'يجب ألا يزيد حجم صورة المنتج عن 20 ميجابايت.',
        ], [
            'images.*' => 'صورة المنتج',
        ])->validate();

        return $files;
    }

    /**
     * The guide files on this request, whichever field name they arrived under.
     *
     * Guide files are a list now, but a client may send them as `guide_file`
     * (one file — the shape this API used to take), `guide_file[]`, or
     * `guide_files[]`. All three are read here so the rest of the controller
     * only ever deals with a list.
     *
     * They are validated here rather than in rules() because the request itself
     * is left untouched: Laravel caches its converted uploads on first read, so
     * rewriting the file bag to fold one field into another is not reliably
     * visible to the validator afterwards.
     *
     * @return list<UploadedFile>|null Null when the request carries none, which
     *                                 is what distinguishes "no change" from
     *                                 "an empty list".
     */
    protected function guideUploads(Request $request): ?array
    {
        $files = array_merge(
            $this->uploadList($request->file('guide_files')),
            $this->uploadList($request->file('guide_file')),
        );

        if ($files === []) {
            return null;
        }

        Validator::make(['guide_files' => $files], [
            'guide_files' => ['array', 'max:10'],
            // A technical pack as PDF, or a photographed sheet.
            'guide_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
        ], [
            'guide_files.*.file' => 'فشل رفع ملف التوجيه. يرجى التحقق من حجم الملف ونوعه.',
            'guide_files.*.uploaded' => 'فشل رفع ملف التوجيه. يرجى التحقق من حجم الملف ونوعه.',
            'guide_files.*.mimes' => 'يجب أن يكون ملف التوجيه من نوع: pdf, jpg, jpeg, png, webp.',
            'guide_files.*.max' => 'يجب ألا يزيد حجم ملف التوجيه عن 20 ميجابايت.',
        ], [
            'guide_files.*' => 'ملف التوجيه',
        ])->validate();

        return $files;
    }

    /**
     * @return list<UploadedFile>
     */
    protected function uploadList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            is_array($value) ? $value : [$value],
            fn ($file): bool => $file instanceof UploadedFile,
        ));
    }

    /**
     * Department and workshop summary for the workshop screen.
     *
     * Returns what the mobile list header shows: how many items and how many
     * pieces sit with each workshop, optionally scoped to a department or month.
     */
    public function workshops(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Production::class);

        $filters = $request->validate($this->filterRules());

        $rows = $this->filtered($request, $filters)
            ->reorder()
            ->selectRaw('department, workshop, count(*) as items, coalesce(sum(quantity), 0) as quantity')
            ->groupBy('department', 'workshop')
            ->orderBy('department')
            ->orderBy('workshop')
            ->get();

        return response()->json([
            'status' => true,
            'message' => "Found {$rows->count()} department/workshop grouping(s).",
            'filters' => $filters,
            'totals' => [
                'items' => (int) $rows->sum('items'),
                'quantity' => (int) $rows->sum('quantity'),
            ],
            'data' => $rows->map(fn ($row): array => [
                'department' => $row->department,
                'workshop' => $row->workshop,
                'items' => (int) $row->items,
                'quantity' => (int) $row->quantity,
            ])->all(),
        ]);
    }

    /**
     * The activity trail for one product: every department/workshop move plus
     * the audit entries recorded against it, newest first.
     */
    public function activity(Production $production): JsonResponse
    {
        $this->authorize('view', $production);

        $transfers = $production->transfers()
            ->with('transferredBy')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($transfer): array => [
                'type' => 'transfer',
                'action' => 'production.transferred',
                'from_department' => $transfer->from_department,
                'to_department' => $transfer->to_department,
                'from_workshop' => $transfer->from_workshop,
                'to_workshop' => $transfer->to_workshop,
                'quantity' => $transfer->quantity,
                'user' => $transfer->transferredBy?->username,
                'at' => $transfer->created_at?->format('Y-m-d H:i:s'),
            ]);

        // Audit details are stored as a json string; entries naming this
        // product are matched on its id.
        $logs = AuditLog::query()
            ->where('details', 'like', '%"production_id":' . $production->getKey() . ',%')
            ->orWhere('details', 'like', '%"production_id":' . $production->getKey() . '}%')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'type' => 'audit',
                'action' => $log->action,
                'user' => $log->username,
                'user_id' => $log->user_id,
                'details' => json_decode((string) $log->details, true) ?? $log->details,
                'at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);

        $entries = $transfers->concat($logs)->sortByDesc('at')->values();

        return response()->json([
            'status' => true,
            'message' => "Found {$entries->count()} activity entr(ies).",
            'production' => [
                'id' => $production->id,
                'barcode' => $production->barcode,
                'item_number' => $production->item_number,
                'department' => $production->department,
                'workshop' => $production->workshop,
                'quantity' => $production->quantity,
            ],
            'data' => $entries->all(),
        ]);
    }

    /**
     * Production statistics by piece count.
     *
     * Everything here sums `quantity` — pieces — never row counts; a row count
     * is reported alongside as `items` so the two are never confused. Totals
     * mirror the factory worksheet: everything, everything bar packaging, and
     * everything bar packaging and sewing.
     *
     * Basic (البيزك) is reported on its own: it appears as its own row in
     * by_department, gets a dedicated `basic` block, and is held out of the
     * `production_only` figures so it never inflates a line-department total.
     *
     * Results are bucketed by day, week or month. The date used is the one the
     * product record was created, because the table carries no production date
     * of its own — `month` is a bare integer with no year attached.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Production::class);

        $filters = $request->validate([
            'period' => ['sometimes', 'string', 'in:day,week,month'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'product_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        $period = $filters['period'] ?? 'month';

        $base = $this->filtered($request, $filters)->reorder();

        if (isset($filters['date'])) {
            $base->whereRaw('coalesce(production_date, date(created_at)) = ?', [$filters['date']]);
        }

        if (isset($filters['date_from'])) {
            $base->whereRaw('coalesce(production_date, date(created_at)) >= ?', [$filters['date_from']]);
        }

        if (isset($filters['date_to'])) {
            $base->whereRaw('coalesce(production_date, date(created_at)) <= ?', [$filters['date_to']]);
        }

        // Report on the day the work actually happened. production_date is
        // authoritative; created_at is only a fallback for rows recorded before
        // the column existed.
        $on = 'coalesce(production_date, date(created_at))';

        $bucket = match ($period) {
            'day' => "date_format({$on}, '%Y-%m-%d')",
            'week' => "concat(date_format({$on}, '%x-W'), lpad(week({$on}, 3), 2, '0'))",
            default => "date_format({$on}, '%Y-%m')",
        };

        // One pass over the filtered set; every aggregate below is derived from
        // these rows rather than issuing a query per total.
        $rows = (clone $base)
            ->selectRaw("{$bucket} as bucket, department, product_line, workshop, count(*) as item_count, coalesce(sum(quantity), 0) as pieces")
            ->groupByRaw("{$bucket}, department, product_line, workshop")
            ->orderByRaw('bucket desc')
            ->orderBy('department')
            ->orderBy('workshop')
            ->get();

        $packaging = (array) config('garment_factory.statistics.packaging_departments', []);
        $sewing = (array) config('garment_factory.statistics.sewing_departments', []);
        $basic = (array) config('garment_factory.statistics.basic_departments', []);
        $basicLines = (array) config('garment_factory.statistics.basic_product_lines', []);

        return response()->json([
            'status' => true,
            'message' => "Statistics for {$rows->count()} department/workshop grouping(s) across " .
                $rows->pluck('bucket')->unique()->count() . " {$period}(s).",
            'period' => $period,
            'filters' => $filters,
            'excluded_departments' => [
                'packaging' => array_values($packaging),
                'sewing' => array_values($sewing),
                'basic' => array_values($basic),
                'basic_product_lines' => array_values($basicLines),
            ],
            // Every department, Basic included.
            'totals' => $this->quantityTotals($rows, $packaging, $sewing),
            // Basic on its own.
            'basic' => $this->basicTotals($rows, $basic, $basicLines),
            // The production line with Basic taken out.
            'production_only' => $this->quantityTotals(
                $this->withoutBasic($rows, $basic, $basicLines),
                $packaging,
                $sewing,
            ),
            'by_department' => $this->sumBy($rows, ['department']),
            'by_workshop' => $this->sumBy($rows, ['department', 'workshop']),
            'buckets' => $rows->groupBy('bucket')->map(fn ($group, $key): array => [
                'period' => (string) $key,
                'totals' => $this->quantityTotals($group, $packaging, $sewing),
                'basic' => $this->basicTotals($group, $basic, $basicLines),
                'production_only' => $this->quantityTotals(
                    $this->withoutBasic($group, $basic, $basicLines),
                    $packaging,
                    $sewing,
                ),
                'by_department' => $this->sumBy($group, ['department']),
                'by_workshop' => $this->sumBy($group, ['department', 'workshop']),
            ])->values()->all(),
        ]);
    }

    /**
     * The four headline piece totals for a set of grouped rows.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  list<string>  $packaging
     * @param  list<string>  $sewing
     * @return array<string, int>
     */
    protected function quantityTotals($rows, array $packaging, array $sewing): array
    {
        $sum = fn ($subset): int => (int) $subset->sum('pieces');

        $withoutPackaging = $rows->reject(
            fn ($row): bool => in_array((string) $row->department, $packaging, true),
        );

        $withoutBoth = $withoutPackaging->reject(
            fn ($row): bool => in_array((string) $row->department, $sewing, true),
        );

        return [
            'items' => (int) $rows->sum('item_count'),
            'grand_total' => $sum($rows),
            'excluding_packaging' => $sum($withoutPackaging),
            'excluding_packaging_and_sewing' => $sum($withoutBoth),
        ];
    }

    /**
     * Basic's own figures, reported apart from the production line.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  list<string>  $basic
     * @return array<string, mixed>
     */
    protected function basicTotals($rows, array $basic, array $basicLines = []): array
    {
        $only = $rows->filter(fn ($row): bool => $this->isBasic($row, $basic, $basicLines));

        return [
            'departments' => $only->pluck('department')->unique()->values()->all(),
            'product_lines' => $only->pluck('product_line')->filter()->unique()->values()->all(),
            'items' => (int) $only->sum('item_count'),
            'quantity' => (int) $only->sum('pieces'),
            'by_department' => $this->sumBy($only, ['department']),
            'by_workshop' => $this->sumBy($only, ['department', 'workshop']),
        ];
    }

    /**
     * Is this grouping part of Basic?
     *
     * Basic is identified by its product line — the monthly workbook files its
     * rows under a real stage such as مسلم — and, for older rows entered before
     * the line existed, by a department named البيزك.
     *
     * @param  list<string>  $basic
     * @param  list<string>  $basicLines
     */
    protected function isBasic($row, array $basic, array $basicLines): bool
    {
        return in_array((string) $row->product_line, $basicLines, true)
            || in_array((string) $row->department, $basic, true);
    }

    /**
     * The same rows with every Basic department removed.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  list<string>  $basic
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function withoutBasic($rows, array $basic, array $basicLines = [])
    {
        return $rows->reject(fn ($row): bool => $this->isBasic($row, $basic, $basicLines));
    }
    /**
     * Collapse grouped rows onto the given keys, summing pieces.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    protected function sumBy($rows, array $keys): array
    {
        return $rows
            ->groupBy(fn ($row): string => implode('|', array_map(
                fn (string $key): string => (string) ($row->{$key} ?? ''),
                $keys,
            )))
            ->map(function ($group) use ($keys): array {
                $first = $group->first();

                $out = [];

                foreach ($keys as $key) {
                    $out[$key] = $first->{$key};
                }

                $out['items'] = (int) $group->sum('item_count');
                $out['quantity'] = (int) $group->sum('pieces');

                return $out;
            })
            ->sortByDesc('quantity')
            ->values()
            ->all();
    }
    /**
     * Filters shared by the listing and the workshop summary.
     *
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'product_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
            'month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'design_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'item_number' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'unassigned_workshop' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * Apply the shared production filters to a query.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Production>
     */
    protected function filtered(Request $request, array $filters): Builder
    {
        // Placement filters are canonicalised the same way stored values are,
        // so ?department=خياطه or ?department=التغليف find the rows filed under
        // خياطة and امبلاج instead of quietly returning nothing.
        $departmentValue = $filters['department'] ?? null;
        $workshopValue = $filters['workshop'] ?? null;

        // The mobile section labels use a combined value such as
        // "خياطة <صالح>". Treat it as the department and workshop filters it
        // represents, while continuing to support separate query parameters.
        if (($workshopValue === null || trim((string) $workshopValue) === '')
            && is_string($departmentValue)
            && preg_match('/^(.*?)\s*(?:\(([^)]+)\)|<([^>]+)>)\s*$/u', trim($departmentValue), $matches) === 1) {
            $candidateDepartment = trim($matches[1]);
            $candidateWorkshop = trim(($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? ''));

            if ($this->names->department($candidateDepartment) !== null
                && $this->names->workshop($candidateWorkshop) !== null) {
                $departmentValue = $candidateDepartment;
                $workshopValue = $candidateWorkshop;
            }
        }

        $department = $this->canonicalFilter('department', $departmentValue);
        $workshop = $this->canonicalFilter('workshop', $workshopValue);

        return Production::query()
            ->when($department !== null, fn ($q) => $q->where('department', $department))
            ->when($workshop !== null, fn ($q) => $q->where('workshop', $workshop))
            ->when(isset($filters['product_line']), fn ($q) => $q->where('product_line', trim((string) $filters['product_line'])))
            ->when(isset($filters['month']), fn ($q) => $q->where('month', $filters['month']))
            ->when(isset($filters['year']), function ($q) use ($filters) {
                $year = $filters['year'];

                $q->where(function ($yearQuery) use ($year) {
                    $yearQuery->whereYear('production_date', $year)
                        ->orWhere(function ($fallbackQuery) use ($year) {
                            $fallbackQuery->whereNull('production_date')
                                ->whereYear('created_at', $year);
                        });
                });
            })
            ->when(isset($filters['design_status']), fn ($q) => $q->where('design_status', $filters['design_status']))
            ->when(isset($filters['barcode']), fn ($q) => $q->where('barcode', $filters['barcode']))
            ->when(isset($filters['item_number']), fn ($q) => $q->where('item_number', $filters['item_number']))
            ->when($request->boolean('unassigned_workshop'), fn ($q) => $q->whereNull('workshop'))
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';

                $q->where(function ($inner) use ($term) {
                    $inner->where('model_name', 'like', $term)
                        ->orWhere('barcode', 'like', $term)
                        ->orWhere('workshop', 'like', $term);
                });
            });
    }
    /**
     * Canonical form of a department or workshop used as a *filter* value.
     *
     * Unlike canonicalisePlacement(), an unrecognised name is not rejected: a
     * filter is a question, not a write, so an unknown value is passed through
     * untouched and simply matches nothing.
     */
    protected function canonicalFilter(string $kind, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $canonical = $kind === 'department'
            ? $this->names->department($value)
            : $this->names->workshop($value);

        if ($canonical !== null) {
            return $canonical;
        }

        // A name that resolves to nothing used to be passed through to the
        // query, which matched no rows and returned an empty list with a 200 —
        // indistinguishable from a department that genuinely holds no stock.
        // It is reported instead, with the accepted values.
        throw ValidationException::withMessages([
            $kind => array_values(array_filter([
                $kind === 'department'
                    ? 'Unknown department. Allowed: '.implode(', ', $this->names->departmentNames())
                    : 'Unknown workshop. Allowed: '.implode(', ', $this->names->workshopNames()),
                $this->compositeHint(trim($value)),
            ])),
        ]);
    }

    /**
     * Explain a name that carries more than the department.
     *
     * The worksheet writes the department, workshop and product line into one
     * string — "بيزك - خياطة <صالح>". Those are three separate filters here, so
     * a caller sending the combined form is told how to split it rather than
     * being left with a bare "unknown department".
     */
    protected function compositeHint(string $value): ?string
    {
        $workshop = null;
        $line = null;
        $rest = $value;

        if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $rest, $matches) === 1) {
            $line = $this->names->department(trim($matches[1]));
            $rest = trim($matches[2]);
        }

        if (preg_match('/^(.*?)\s*(?:\(([^)]+)\)|<([^>]+)>)\s*$/u', $rest, $matches) === 1) {
            $workshop = trim(($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? ''));
            $rest = trim($matches[1]);
        }

        if ($workshop === null && $line === null) {
            return null;
        }

        $parts = ['department='.($this->names->department($rest) ?? $rest)];

        if ($workshop !== null && $workshop !== '') {
            $parts[] = 'workshop='.($this->names->workshop($workshop) ?? $workshop);
        }

        if ($line !== null) {
            $parts[] = 'product_line='.$line;
        }

        return 'This name combines several fields. Send them separately: '.implode('&', $parts).'.';
    }

    /**
     * Replace typed department and workshop names with their canonical form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function canonicalisePlacement(array $data): array
    {
        foreach (['department' => 'department', 'workshop' => 'workshop'] as $field => $kind) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }

            $canonical = $kind === 'department'
                ? $this->names->department($data[$field])
                : $this->names->workshop($data[$field]);

            if ($canonical === null) {
                $allowed = $kind === 'department'
                    ? $this->names->departmentNames()
                    : $this->names->workshopNames();

                throw ValidationException::withMessages([
                    $field => ['Unknown '.$kind.'. Allowed: '.implode(', ', $allowed)],
                ]);
            }

            $data[$field] = $canonical;
        }

        return $data;
    }
    /**
     * Validation rules for the product form.
     *
     * @return array<string, mixed>
     */
    protected function rules(?Production $production = null): array
    {
        $creating = $production === null;
        $required = $creating ? 'required' : 'sometimes';
        $key = $production?->getKey();

        // Department and workshop are set when the product is created. After
        // that they change only through the transfer endpoint, which renumbers
        // the department lists and records the move in the ledger — accepting
        // them here would move stock with no audit trail.
        $placement = $creating ? [
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
        ] : [];

        return $placement + [
            // Model name.
            'model_name' => [$required, 'string', 'max:255'],
            // A row is a batch line, not a catalogue entry: the same product
            // legitimately appears more than once, in different departments and
            // as separate batches within one department, so neither the model
            // number nor the barcode is unique. They identify the product; the
            // row is identified by its id.
            'item_number' => [$required, 'integer', 'min:0'],
            'barcode' => [$required, 'string', 'max:255'],

            'sizes' => [$required, 'array', 'min:1'],
            'sizes.*' => ['required', 'string', 'max:50'],

            'colors' => [$required, 'array', 'min:1'],
            'colors.*' => ['required', 'string', 'max:100'],

            'quantity' => [$required, 'integer', 'min:0'],

            // The cap keeps a long paste inside the TEXT column — without it an
            // oversized body reaches MySQL and comes back as a 500 rather than
            // a validation error.
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],

            'month' => [$creating ? 'required' : 'sometimes', 'integer', 'between:1,12'],
            'production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'fabric' => [$required, 'string', 'max:255'],
            'design_status' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Product image uploads are validated in imageUploads(), which accepts
            // them under images, images[], image, or images[0].

            // Paths of existing images to detach, sent on update.
            'remove_images' => ['sometimes', 'array'],
            'remove_images.*' => ['string'],

            // Guide file uploads are validated in guideUploads(), which accepts
            // them under guide_file, guide_file[] or guide_files[].

            // Paths of existing guide files to detach, sent on update.
            'remove_guide_files' => ['sometimes', 'array'],
            'remove_guide_files.*' => ['string'],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Resources\ProductionResource;
use App\Http\Resources\ProductionTransferResource;
use App\Models\Production;
use App\Models\ProductionTransfer;
use App\Services\AuditLogger;
use App\Services\DepartmentSerialNumberService;
use App\Services\NameNormalizer;
use App\Services\ProductionTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionTransferController extends Controller
{
    use ConfirmsPassword;

    public function __construct(
        protected DepartmentSerialNumberService $serials,
        protected AuditLogger $audit,
        protected ProductionTransferService $transfers,
        protected NameNormalizer $names,
    ) {
    }

    /**
     * The transfer ledger — the complete journey of items between departments.
     *
     * Query parameters: production_id, department, per_page. The department
     * filter matches either leg of the move and is canonicalised the same way
     * the ledger's stored names are, so an alias finds the same rows the
     * production list does.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductionTransfer::class);

        $filters = $request->validate([
            'production_id' => ['sometimes', 'integer'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $department = null;

        if (isset($filters['department']) && trim($filters['department']) !== '') {
            // Reported rather than passed through: an unresolvable name matched
            // no rows and returned an empty ledger with a 200, which reads the
            // same as a department that has simply never been transferred to.
            $department = $this->names->department($filters['department']);

            if ($department === null) {
                throw ValidationException::withMessages([
                    'department' => ['Unknown department. Allowed: '.implode(', ', $this->names->departmentNames())],
                ]);
            }
        }

        $transfers = ProductionTransfer::query()
            ->with(['production', 'transferredBy'])
            ->when(isset($filters['production_id']), fn ($query) => $query->where('production_id', $filters['production_id']))
            ->when($department !== null, fn ($query) => $query->where(function ($inner) use ($department) {
                $inner->where('from_department', $department)->orWhere('to_department', $department);
            }))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ProductionTransferResource::collection($transfers);
    }

    /**
     * The journey of one production item, oldest leg first.
     */
    public function history(Production $production): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductionTransfer::class);

        $transfers = $production->transfers()
            ->with('transferredBy')
            ->orderBy('id')
            ->get();

        return ProductionTransferResource::collection($transfers);
    }

    /**
     * Move an item to another department.
     *
     * Records who authorised the move and when, moves the item onto the
     * destination department's list with a fresh serial number, and closes the
     * gap left in the source department's numbering.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductionTransfer::class);
        $this->confirmPassword($request);

        $data = $request->validate([
            'production_id' => ['required', 'integer', 'exists:productions,id'],
            'to_department' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'to_workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            // Verified by confirmPassword(); declared so it passes validation.
            'password' => ['sometimes', 'string'],
        ]);

        // Names are canonicalised against the reference tables, so "خياطه" and
        // "خياطة" land on one department instead of quietly becoming two.
        $toDepartment = $this->names->department($data['to_department']);

        if ($toDepartment === null) {
            throw ValidationException::withMessages([
                'to_department' => ['Unknown department. Allowed: '.implode(', ', $this->names->departmentNames())],
            ]);
        }

        $toWorkshop = null;

        if (array_key_exists('to_workshop', $data) && $data['to_workshop'] !== null) {
            $toWorkshop = $this->names->workshop($data['to_workshop']);

            if ($toWorkshop === null) {
                throw ValidationException::withMessages([
                    'to_workshop' => ['Unknown workshop. Allowed: '.implode(', ', $this->names->workshopNames())],
                ]);
            }
        }

        $production = Production::findOrFail($data['production_id']);
        $from = $production->department;
        $fromWorkshop = $production->workshop;

        if ($from === $toDepartment) {
            throw ValidationException::withMessages([
                'to_department' => ['The item is already in this department.'],
            ]);
        }

        if ($data['quantity'] > $production->quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Cannot transfer {$data['quantity']} units; the item only holds {$production->quantity}."],
            ]);
        }

        $result = $this->transfers->transfer(
            $production,
            $toDepartment,
            $toWorkshop,
            $data['quantity'],
            $request->user(),
        );

        $transfer = $result['transfer'];
        $target = $result['target'];

        if (array_key_exists('notes', $data) && $data['notes'] !== null) {
            $target->notes = $data['notes'];
            $target->save();
        }

        $this->audit->log('production.transferred', [
            'transfer_id' => $transfer->id,
            'production_id' => $production->id,
            'barcode' => $production->barcode,
            'item_number' => $production->item_number,
            'from_department' => $from,
            'to_department' => $toDepartment,
            'from_workshop' => $fromWorkshop,
            'to_workshop' => $toWorkshop,
            'quantity' => $data['quantity'],
            'mode' => $result['mode'],
            'source_remaining' => $production->fresh()->quantity,
            'target_production_id' => $target->id,
            'target_quantity' => $target->quantity,
            'new_serial_number' => $target->serial_number,
        ]);
        return response()->json([
            'status' => true,
            'message' => match ($result['mode']) {
                ProductionTransferService::MODE_WHOLE => 'The whole batch was moved.',
                ProductionTransferService::MODE_MERGE => 'Pieces were split off and merged into the existing batch in that department.',
                default => 'The batch was split.',
            },
            'mode' => $result['mode'],
            'data' => new ProductionTransferResource($transfer->load(['production', 'transferredBy'])),
            'source' => new ProductionResource($production->fresh()),
            'target' => new ProductionResource($target->fresh()),
        ], JsonResponse::HTTP_CREATED);
    }
}

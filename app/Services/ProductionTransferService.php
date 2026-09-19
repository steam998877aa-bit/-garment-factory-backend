<?php

namespace App\Services;

use App\Models\Production;
use App\Models\ProductionTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves pieces between departments, keeping quantities honest on both sides.
 *
 * A transfer of the whole batch moves the row. A transfer of part of it splits
 * the batch: the source keeps what stayed behind and the destination receives a
 * row of its own, merged into an existing row for the same garment if that
 * department already holds one. Either way the pieces are conserved — the sum
 * across departments before and after a transfer is identical.
 */
class ProductionTransferService
{
    public const MODE_WHOLE = 'whole_batch';

    public const MODE_SPLIT = 'split';

    public const MODE_MERGE = 'split_merged';

    public function __construct(protected DepartmentSerialNumberService $serials)
    {
    }

    /**
     * Perform the move.
     *
     * @return array{transfer: ProductionTransfer, target: Production, mode: string}
     */
    public function transfer(
        Production $production,
        string $toDepartment,
        ?string $toWorkshop,
        int $quantity,
        User $user,
    ): array {
        return DB::transaction(function () use ($production, $toDepartment, $toWorkshop, $quantity, $user): array {
            $fromDepartment = $production->department;
            $fromWorkshop = $production->workshop;
            $whole = $quantity >= $production->quantity;

            $transfer = ProductionTransfer::create([
                'production_id' => $production->getKey(),
                'from_department' => $fromDepartment,
                'to_department' => $toDepartment,
                'from_workshop' => $fromWorkshop,
                'to_workshop' => $toWorkshop,
                'quantity' => $quantity,
                'transferred_by' => $user->getKey(),
            ]);

            if ($whole) {
                $this->serials->moveToDepartment($production, $toDepartment);
                $production->workshop = $toWorkshop;
                $production->save();

                return ['transfer' => $transfer, 'target' => $production, 'mode' => self::MODE_WHOLE];
            }

            // Part of the batch stays behind.
            $production->quantity -= $quantity;
            $production->save();

            [$target, $mode] = $this->receive($production, $toDepartment, $toWorkshop, $quantity);

            return ['transfer' => $transfer, 'target' => $target, 'mode' => $mode];
        });
    }

    /**
     * Land the moved pieces in the destination department.
     *
     * Merging is only safe when the destination holds exactly one row for the
     * garment. A production row is a batch line, not a catalogue entry, so a
     * department can legitimately hold several rows for the same barcode —
     * separate batches with their own quantities, workshops and notes. Picking
     * one of them to absorb the pieces would silently fold two batches the
     * factory keeps apart into one, so an ambiguous destination gets a new
     * batch line of its own instead.
     *
     * @return array{0: Production, 1: string}
     */
    protected function receive(
        Production $source,
        string $toDepartment,
        ?string $toWorkshop,
        int $quantity,
    ): array {
        $candidates = Production::query()
            ->where('barcode', $source->barcode)
            ->where('department', $toDepartment)
            ->limit(2)
            ->get();

        $existing = $candidates->count() === 1 ? $candidates->first() : null;

        if ($existing !== null) {
            $existing->quantity += $quantity;

            if ($toWorkshop !== null) {
                $existing->workshop = $toWorkshop;
            }

            $existing->save();

            return [$existing, self::MODE_MERGE];
        }

        // source_sheet/source_row identify a row of an imported workbook. A
        // split is a new batch line that no worksheet row describes, so it must
        // not inherit that identity — doing so both collides with the unique
        // index and would make a re-import overwrite the split.
        $split = $source->replicate([
            'serial_number',
            'images',
            'guide_files',
            'source_sheet',
            'source_row',
            'created_at',
            'updated_at',
        ]);

        $split->department = $toDepartment;
        $split->workshop = $toWorkshop;
        $split->quantity = $quantity;
        $split->split_from_id = $source->getKey();
        $split->serial_number = null;
        $split->save();

        $this->serials->assign($split);

        return [$split, self::MODE_SPLIT];
    }
}

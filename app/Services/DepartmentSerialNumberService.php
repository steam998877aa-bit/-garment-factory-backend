<?php

namespace App\Services;

use App\Models\Production;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the per-department serial numbers on productions.
 *
 * Every department keeps its own list numbered from 1 upwards. Appending an
 * item to a department puts it at the end of that list; moving an item out
 * closes the gap it leaves behind, so a department list is always a contiguous
 * 1..N sequence in the order the items were added.
 */
class DepartmentSerialNumberService
{
    /**
     * Give a production the next serial number in its current department.
     *
     * Returns the assigned number. Rows already holding a serial keep it.
     */
    public function assign(Production $production, bool $force = false): ?int
    {
        if (! $force && $production->serial_number !== null) {
            return $production->serial_number;
        }

        $serial = $this->nextSerialFor($production->department, $production->getKey());

        $production->serial_number = $serial;
        $production->save();

        return $serial;
    }

    /**
     * Move a production onto another department's list.
     *
     * The item takes the next free serial in the destination department and the
     * source department is resequenced so its numbering stays contiguous.
     */
    public function moveToDepartment(Production $production, ?string $toDepartment): int
    {
        $from = $production->department;

        return DB::transaction(function () use ($production, $from, $toDepartment): int {
            $serial = $this->nextSerialFor($toDepartment, $production->getKey());

            $production->department = $toDepartment;
            $production->serial_number = $serial;
            $production->save();

            // Close the hole the item left in the department it came from.
            if ($from !== $toDepartment) {
                $this->resequence($from);
            }

            return $serial;
        });
    }

    /**
     * Renumber a department's list to a contiguous 1..N sequence.
     *
     * Ordering follows the existing serial numbers, with rows that have none
     * appended in insertion order.
     *
     * @return int Number of rows whose serial changed.
     */
    public function resequence(?string $department): int
    {
        return DB::transaction(function () use ($department): int {
            $rows = Production::query()
                ->where(fn ($query) => $department === null
                    ? $query->whereNull('department')
                    : $query->where('department', $department))
                ->orderByRaw('serial_number is null')
                ->orderBy('serial_number')
                ->orderBy('id')
                ->get();

            $changed = 0;
            $position = 1;

            foreach ($rows as $row) {
                if ($row->serial_number !== $position) {
                    $row->serial_number = $position;
                    $row->save();
                    $changed++;
                }

                $position++;
            }

            return $changed;
        });
    }

    /**
     * Renumber every department list in the table.
     *
     * @return array<string, int> Department name (or "(unassigned)") => rows changed.
     */
    public function resequenceAll(): array
    {
        $departments = Production::query()
            ->select('department')
            ->distinct()
            ->pluck('department');

        $report = [];

        foreach ($departments as $department) {
            $report[$department ?? '(unassigned)'] = $this->resequence($department);
        }

        return $report;
    }

    /**
     * The next free serial number in a department list.
     */
    protected function nextSerialFor(?string $department, ?int $ignoreId = null): int
    {
        $max = Production::query()
            ->where(fn ($query) => $department === null
                ? $query->whereNull('department')
                : $query->where('department', $department))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->max('serial_number');

        return (int) $max + 1;
    }
}

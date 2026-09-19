<?php

namespace App\Http\Controllers;

use App\Http\Resources\DepartmentResource;
use App\Http\Resources\WorkshopResource;
use App\Models\Department;
use App\Models\Production;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The reference lists a client needs to build its dropdowns.
 *
 * These are the canonical department and workshop names — the exact strings the
 * rest of the API accepts in a `department=` or `workshop=` filter and on a
 * production write. They are read from the reference tables rather than derived
 * from production rows, so a department that currently holds nothing still
 * appears with a zero count instead of vanishing from the list.
 *
 * Both lists are unpaginated: there are fifteen departments and six workshops,
 * and a dropdown that arrives in pages is worse than useless.
 */
class ReferenceDataController extends Controller
{
    /**
     * Product lines a production row may belong to.
     *
     * Unlike departments and workshops this is not a table — the importer
     * writes one of two literals, and there is nothing to administer.
     *
     * @var list<string>
     */
    public const PRODUCT_LINES = ['عام', 'البيزك'];

    /**
     * Every department, with what it currently holds.
     *
     * Inactive departments are withheld by default because the name resolver
     * only accepts active names: offering one in a dropdown would produce a
     * filter the API then rejects. Pass include_inactive=1 to list them anyway,
     * for an administrative screen that needs the full picture.
     */
    public function departments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Production::class);

        $request->validate([
            // Deliberately not the 'boolean' rule: that accepts only 0 and 1,
            // and a query flag is typed by hand and arrives as a string.
            // $request->boolean() reads every spelling listed here.
            'include_inactive' => ['sometimes', 'in:0,1,true,false,TRUE,FALSE,yes,no,on,off'],
        ]);

        $counts = $this->countsBy('department');

        $departments = Department::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->each(function (Department $department) use ($counts): void {
                $row = $counts->get($department->name);

                $department->items = (int) ($row->items ?? 0);
                $department->quantity = (int) ($row->quantity ?? 0);
            });

        return response()->json([
            'status' => true,
            'message' => "Found {$departments->count()} department(s).",
            'totals' => [
                'items' => (int) $departments->sum('items'),
                'quantity' => (int) $departments->sum('quantity'),
            ],
            'product_lines' => $this->productLines(),
            'data' => DepartmentResource::collection($departments),
            'unlisted' => $this->unlisted($counts, $departments->pluck('name')->all()),
        ]);
    }

    /**
     * Every workshop, with what it currently holds across all departments.
     */
    public function workshops(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Production::class);

        $request->validate([
            // Deliberately not the 'boolean' rule: that accepts only 0 and 1,
            // and a query flag is typed by hand and arrives as a string.
            // $request->boolean() reads every spelling listed here.
            'include_inactive' => ['sometimes', 'in:0,1,true,false,TRUE,FALSE,yes,no,on,off'],
        ]);

        $counts = $this->countsBy('workshop');

        $workshops = Workshop::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->each(function (Workshop $workshop) use ($counts): void {
                $row = $counts->get($workshop->name);

                $workshop->items = (int) ($row->items ?? 0);
                $workshop->quantity = (int) ($row->quantity ?? 0);
            });

        return response()->json([
            'status' => true,
            'message' => "Found {$workshops->count()} workshop(s).",
            'totals' => [
                'items' => (int) $workshops->sum('items'),
                'quantity' => (int) $workshops->sum('quantity'),
                // Rows filed under no workshop at all. A client building a
                // "unassigned" bucket needs this; ?unassigned_workshop=1 on
                // /api/productions lists them.
                'unassigned_items' => Production::whereNull('workshop')->count(),
            ],
            'data' => WorkshopResource::collection($workshops),
            'unlisted' => $this->unlisted($counts, $workshops->pluck('name')->all()),
        ]);
    }

    /**
     * Production counts grouped by one placement column, keyed on the name.
     *
     * @return Collection<string, object>
     */
    protected function countsBy(string $column): Collection
    {
        return Production::query()
            ->whereNotNull($column)
            ->selectRaw("{$column} as name, count(*) as items, coalesce(sum(quantity), 0) as quantity")
            ->groupBy($column)
            ->get()
            ->keyBy('name');
    }

    /**
     * Names present on production rows that the reference table does not list.
     *
     * Always empty in a healthy database. A name here is stock filed under a
     * spelling nothing can resolve — it will not appear in any dropdown and a
     * filter for it is rejected — so it is surfaced rather than left to be
     * discovered as a missing row in a total.
     *
     * @param  Collection<string, object>  $counts
     * @param  list<string>  $known
     * @return list<array<string, mixed>>
     */
    protected function unlisted(Collection $counts, array $known): array
    {
        return $counts
            ->reject(fn ($row): bool => in_array($row->name, $known, true))
            ->map(fn ($row): array => [
                'name' => $row->name,
                'items' => (int) $row->items,
                'quantity' => (int) $row->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * The product lines, with what each currently holds.
     *
     * @return list<array<string, mixed>>
     */
    protected function productLines(): array
    {
        $counts = $this->countsBy('product_line');

        return array_map(fn (string $line): array => [
            'name' => $line,
            'items' => (int) ($counts->get($line)->items ?? 0),
            'quantity' => (int) ($counts->get($line)->quantity ?? 0),
        ], self::PRODUCT_LINES);
    }
}

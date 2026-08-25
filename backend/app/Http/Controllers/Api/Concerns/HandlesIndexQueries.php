<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared behaviour for every list endpoint so that the frontend data table
 * talks to all of them in exactly the same way.
 *
 * Supported query parameters: page, per_page, sort_by, sort_dir, search.
 */
trait HandlesIndexQueries
{
    /**
     * @param  array<int, string>  $searchable
     */
    protected function applySearch(Builder $query, Request $request, array $searchable): Builder
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '' || $searchable === []) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($searchable, $term) {
            foreach ($searchable as $column) {
                $inner->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @param  array<int, string>  $sortable
     */
    protected function applySort(Builder $query, Request $request, array $sortable, string $default = 'id', string $defaultDir = 'desc'): Builder
    {
        $column = (string) $request->query('sort_by', $default);
        $direction = strtolower((string) $request->query('sort_dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        if (! in_array($column, $sortable, true)) {
            $column = $default;
        }

        return $query->orderBy($query->getModel()->getTable().'.'.$column, $direction);
    }

    protected function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 200));

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Applies a date range on the given column using `date_from` / `date_to`.
     */
    protected function applyDateRange(Builder $query, Request $request, string $column, string $fromKey = 'date_from', string $toKey = 'date_to'): Builder
    {
        if ($from = $request->query($fromKey)) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to = $request->query($toKey)) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    /**
     * Applies simple equality filters when the request carries a value.
     *
     * @param  array<string, string>  $map  request key => column
     */
    protected function applyEquals(Builder $query, Request $request, array $map): Builder
    {
        foreach ($map as $key => $column) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * The pagination envelope every list endpoint returns as `meta`.
     *
     * @return array<string, mixed>
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            "current_page" => $paginator->currentPage(),
            "per_page" => $paginator->perPage(),
            "total" => $paginator->total(),
            "last_page" => $paginator->lastPage(),
            "from" => $paginator->firstItem(),
            "to" => $paginator->lastItem(),
        ];
    }
}

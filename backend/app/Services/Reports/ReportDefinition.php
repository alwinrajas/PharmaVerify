<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * One report, described rather than coded.
 *
 * Every report in the application is the same three things: a query, a set of
 * columns, and a list of filters the user may apply. Describing them this way
 * means the list screen, the Excel export and the PDF export are all driven by
 * a single engine instead of nine hand-written implementations.
 */
class ReportDefinition
{
    /**
     * @param  string  $key  URL key, e.g. `variance`
     * @param  string  $title  Title shown on screen and printed on exports
     * @param  string  $description  One line explaining what the report answers
     * @param  string  $permission  Permission required to run it
     * @param  array<int, array<string, mixed>>  $columns  key, label, type, width
     * @param  array<int, string>  $filters  Filter keys the report understands
     * @param  callable(Request): Builder  $query  Builds the underlying query
     * @param  null|callable(Builder, Request): array<string, mixed>  $summary  Optional headline figures
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $permission,
        public readonly array $columns,
        public readonly array $filters,
        public readonly mixed $query,
        public readonly mixed $summary = null,
        public readonly string $defaultSort = 'id',
        public readonly string $defaultSortDir = 'desc',
    ) {}

    /**
     * @return array<int, string>
     */
    public function columnKeys(): array
    {
        return array_column($this->columns, 'key');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'default_sort' => $this->defaultSort,
            'default_sort_dir' => $this->defaultSortDir,
        ];
    }
}

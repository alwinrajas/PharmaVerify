<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Runs a report definition: applies the user's filters and sorting, turns the
 * result into flat rows, and hands those rows to the screen or an export.
 */
class ReportService
{
    public function __construct(private readonly ReportRegistry $registry) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(User $user): array
    {
        return collect($this->registry->all())
            ->filter(fn (ReportDefinition $definition) => $user->can($definition->permission))
            ->map(fn (ReportDefinition $definition) => $definition->toArray())
            ->values()
            ->all();
    }

    /**
     * The paginated result used by the report screen.
     *
     * @return array{definition: ReportDefinition, rows: array<int, array<string, mixed>>, paginator: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function run(string $key, Request $request): array
    {
        $definition = $this->registry->find($key);

        $this->authorise($definition, $request->user());

        $query = $this->buildQuery($definition, $request);

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));
        $paginator = $query->paginate($perPage)->withQueryString();

        return [
            'definition' => $definition,
            'rows' => array_map(fn ($record) => $this->mapRow($definition, $record), $paginator->items()),
            'paginator' => $paginator,
            'summary' => $this->summary($definition, $request),
        ];
    }

    /**
     * Every matching row, used when exporting. Capped so that an unfiltered
     * export cannot exhaust memory.
     *
     * @return array{definition: ReportDefinition, rows: array<int, array<string, mixed>>, summary: array<string, mixed>, truncated: bool}
     */
    public function collect(string $key, Request $request, int $limit = 20000): array
    {
        $definition = $this->registry->find($key);

        $this->authorise($definition, $request->user(), export: true);

        $query = $this->buildQuery($definition, $request);

        $total = (clone $query)->getQuery()->getCountForPagination();
        $records = $query->limit($limit)->get();

        return [
            'definition' => $definition,
            'rows' => $records->map(fn ($record) => $this->mapRow($definition, $record))->all(),
            'summary' => $this->summary($definition, $request),
            'truncated' => $total > $limit,
        ];
    }

    private function authorise(ReportDefinition $definition, ?User $user, bool $export = false): void
    {
        if (! $user || ! $user->can($definition->permission)) {
            abort(403, 'You do not have permission to run this report.');
        }

        if ($export && ! $user->can(\App\Support\Permissions::REPORTS_EXPORT)) {
            abort(403, 'You do not have permission to export reports.');
        }
    }

    private function buildQuery(ReportDefinition $definition, Request $request): Builder
    {
        /** @var Builder $query */
        $query = ($definition->query)($request);

        $sortBy = (string) $request->query('sort_by', $definition->defaultSort);
        $sortDir = strtolower((string) $request->query('sort_dir', $definition->defaultSortDir)) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, $definition->columnKeys(), true) && $sortBy !== $definition->defaultSort) {
            $sortBy = $definition->defaultSort;
        }

        return $query->orderBy($sortBy, $sortDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ReportDefinition $definition, Request $request): array
    {
        if (! $definition->summary) {
            return [];
        }

        try {
            /** @var Builder $query */
            $query = ($definition->query)($request);

            return ($definition->summary)($query, $request);
        } catch (BusinessRuleException $e) {
            throw $e;
        }
    }

    /**
     * Flattens one record into the columns the definition declares.
     *
     * @return array<string, mixed>
     */
    private function mapRow(ReportDefinition $definition, mixed $record): array
    {
        $row = [];

        foreach ($definition->columns as $column) {
            $row[$column['key']] = $this->value($record, $column['key'], $column['type'] ?? 'text');
        }

        return $row;
    }

    private function value(mixed $record, string $key, string $type): mixed
    {
        $raw = match ($key) {
            // Derived columns that no table carries directly.
            'stock_value' => (float) ($record->system_qty ?? 0) * (float) ($record->price ?? 0),
            'adjusted_by' => $record->adjusted_by_name ?? null,
            'logged_at' => $record->created_at ?? null,
            'user_name' => $record->user_name ?? $record->causer?->name ?? 'System',
            'subject' => $this->describeSubject($record),
            'detail' => $this->describeProperties($record),
            default => $record->{$key} ?? null,
        };

        return match ($type) {
            'decimal' => $raw === null ? null : round((float) $raw, 3),
            'money' => $raw === null ? null : round((float) $raw, 2),
            'number' => $raw === null ? null : (int) $raw,
            'date' => $raw instanceof \DateTimeInterface ? Carbon::instance($raw)->toDateString() : ($raw ? (string) $raw : null),
            'datetime' => $raw instanceof \DateTimeInterface ? Carbon::instance($raw)->format('Y-m-d H:i') : ($raw ? (string) $raw : null),
            default => $raw === null ? null : (string) $raw,
        };
    }

    private function describeSubject(mixed $record): ?string
    {
        if (! isset($record->subject_type)) {
            return null;
        }

        $type = class_basename((string) $record->subject_type);

        return $record->subject_id ? $type.' #'.$record->subject_id : $type;
    }

    private function describeProperties(mixed $record): ?string
    {
        $properties = $record->properties ?? null;

        if (! $properties) {
            return null;
        }

        $array = is_array($properties) ? $properties : $properties->toArray();

        if ($array === []) {
            return null;
        }

        $parts = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }

            $parts[] = ucwords(str_replace('_', ' ', (string) $key)).': '.$value;
        }

        return mb_substr(implode(' | ', $parts), 0, 500);
    }
}

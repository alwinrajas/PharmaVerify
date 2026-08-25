import { Box, Chip, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatDateTime } from '@/utils/format'

interface ActivityEntry {
  id: number
  log_name: string
  description: string
  user: string
  subject_type: string | null
  subject_id: number | null
  properties: Record<string, unknown> | null
  created_at: string | null
}

/**
 * The audit trail. Every significant action — a corrected count, a posted
 * adjustment, a replaced stock file — lands here with who did it and when.
 */
export function ActivityLogPage() {
  const table = useTableQuery({ sortBy: 'id', sortDir: 'desc' })

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['activity-log', table.params],
    queryFn: async () => get<ActivityEntry[]>('/activity-log', table.params),
  })

  const logNames = ((data?.meta as unknown as { log_names?: string[] } | undefined)?.log_names ?? []).filter(Boolean)

  const columns: DataTableColumn<ActivityEntry>[] = [
    {
      key: 'created_at',
      label: 'When',
      width: 180,
      render: (row) => formatDateTime(row.created_at),
    },
    {
      key: 'user',
      label: 'User',
      width: 175,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {row.user}
        </Typography>
      ),
    },
    {
      key: 'log_name',
      label: 'Module',
      width: 145,
      render: (row) => (
        <Chip
          size="small"
          label={(row.log_name ?? 'system').replace(/_/g, ' ')}
          sx={{ bgcolor: 'rgba(15,93,76,0.07)', color: 'primary.main' }}
        />
      ),
    },
    {
      key: 'description',
      label: 'Action',
      render: (row) => (
        <Box>
          <Typography variant="body2">{row.description}</Typography>
          {row.subject_type ? (
            <Typography variant="caption">
              {row.subject_type}
              {row.subject_id ? ` #${row.subject_id}` : ''}
            </Typography>
          ) : null}
        </Box>
      ),
    },
    {
      key: 'properties',
      label: 'Detail',
      hideBelow: 'lg',
      render: (row) => (
        <Typography
          variant="caption"
          sx={{
            display: 'block',
            maxWidth: 420,
            whiteSpace: 'nowrap',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            fontFamily: 'ui-monospace, monospace',
          }}
        >
          {describe(row.properties)}
        </Typography>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Activity Log"
        description="Who changed what, and when. Corrected counts record their old and new value."
        crumbs={[{ label: 'Administration' }, { label: 'Activity Log' }]}
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(row) => row.id}
        loading={isFetching}
        error={isError ? apiErrorMessage(error) : null}
        onRetry={() => void refetch()}
        total={data?.meta?.total ?? 0}
        page={table.page}
        perPage={table.perPage}
        onPageChange={table.setPage}
        onPerPageChange={table.setPerPage}
        dense
        emptyTitle="No activity recorded"
        emptyDescription="Actions taken in the application will be listed here."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search action…" width={260} />
            <SelectFilter
              label="Module"
              value={table.filters.log_name ?? ''}
              onChange={(value) => table.setFilter('log_name', value)}
              options={logNames.map((name) => ({ value: name, label: name.replace(/_/g, ' ') }))}
              width={180}
            />
            <DateFilter
              label="From"
              value={table.filters.date_from ?? ''}
              onChange={(value) => table.setFilter('date_from', value)}
            />
            <DateFilter
              label="To"
              value={table.filters.date_to ?? ''}
              onChange={(value) => table.setFilter('date_to', value)}
            />
          </FilterBar>
        }
      />
    </Box>
  )
}

function describe(properties: Record<string, unknown> | null): string {
  if (!properties) return '—'

  const entries = Object.entries(properties)

  if (entries.length === 0) return '—'

  return entries
    .map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`)
    .join(' · ')
}

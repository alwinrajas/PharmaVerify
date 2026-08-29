import { Box, Chip, CircularProgress, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useSnackbar } from 'notistack'
import { useNavigate } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatDate, formatDateTime, formatNumber } from '@/utils/format'
import type { Audit } from '@/types'
import { neutral, semantic } from '@/theme'

/**
 * Where the count came from.
 *
 * Worth showing because the two routes mean different things operationally: HHT
 * is the live path, Excel means somebody carried a file across and the direct
 * submission either was not used or did not work.
 */
function SourceChip({ source }: { source?: string }) {
  const isExcel = source === 'excel'
  return (
    <Chip
      size="small"
      label={isExcel ? 'Excel' : 'HHT'}
      sx={{
        fontWeight: 600,
        fontSize: '0.6875rem',
        bgcolor: isExcel ? neutral[100] : semantic.info.bg,
        color: isExcel ? 'text.secondary' : semantic.info.fg,
      }}
    />
  )
}

export function AuditsPage() {
  const navigate = useNavigate()
  const table = useTableQuery({ sortBy: 'submitted_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const { enqueueSnackbar } = useSnackbar()

  // What the office had on the previous poll. A ref, not state: comparing
  // against it must not itself cause a render.
  const seenRefs = useRef<Set<string> | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['audits', table.params],
    queryFn: async () => get<Audit[]>('/audits', table.params),
    // Handhelds submit while nobody is touching this screen, so it has to come
    // and look. Polling rather than sockets: the project has no broadcasting to
    // reuse, and one small request every 15s costs less than the infrastructure
    // to avoid it. Only this screen and the audit detail poll.
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
  })

  // Says out loud when a handheld's count lands, naming the terminal it came
  // from. Without this the row simply appears in a table nobody was watching,
  // and the operator at the counter has no idea whether their submission
  // arrived — which is the moment they reach for the spreadsheet instead.
  useEffect(() => {
    const rows = data?.data
    if (!rows) return

    const current = new Set(rows.map((row) => row.audit_ref).filter(Boolean) as string[])

    // The first load is the baseline, not an arrival: announcing every audit
    // already on the page would be noise the moment someone opens the screen.
    if (seenRefs.current === null) {
      seenRefs.current = current
      return
    }

    const previous = seenRefs.current
    const arrived = rows.filter((row) => row.audit_ref && !previous.has(row.audit_ref))
    seenRefs.current = current

    arrived
      // Only direct submissions are announced. An Excel import is something a
      // person in this building just did on purpose; they do not need telling.
      .filter((row) => row.source !== 'excel')
      .forEach((row) => {
        enqueueSnackbar(
          `Received ${row.audit_ref} from ${row.device_code ?? 'a device'} · ${row.shop_code ?? ''}`.trim(),
          { variant: 'success' },
        )
      })
  }, [data, enqueueSnackbar])

  const columns: DataTableColumn<Audit>[] = [
    {
      key: 'audit_number',
      label: 'Audit',
      sortable: true,
      width: 210,
      render: (audit) => (
        <Box>
          {/* The handheld's reference where there is one, derived otherwise —
              never two formats side by side in one column. */}
          <Typography
            variant="body2"
            sx={{ fontWeight: 700, color: 'primary.main', fontFamily: 'ui-monospace, monospace', fontSize: '0.8125rem' }}
          >
            {audit.audit_ref}
          </Typography>
          <Typography variant="caption">
            {audit.shop_code} · {audit.device_code}
          </Typography>
        </Box>
      ),
    },
    {
      key: 'shop_name',
      label: 'Shop',
      hideBelow: 'md',
      render: (audit) => (
        <Typography variant="body2" noWrap sx={{ maxWidth: 260 }}>
          {audit.shop_name}
        </Typography>
      ),
    },
    {
      key: 'audit_date',
      label: 'Audit Date',
      sortable: true,
      width: 125,
      render: (audit) => formatDate(audit.audit_date),
    },
    {
      key: 'submitted_at',
      label: 'Submitted',
      sortable: true,
      width: 175,
      render: (audit) => (
        <Box>
          <Typography variant="body2">{formatDateTime(audit.submitted_at)}</Typography>
          <Typography variant="caption">{audit.hht_user ?? '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'item_count',
      label: 'Items',
      sortable: true,
      align: 'right',
      width: 85,
      render: (audit) => formatNumber(audit.item_count),
    },
    {
      key: 'variance_count',
      label: 'Variance',
      sortable: true,
      align: 'right',
      width: 105,
      render: (audit) =>
        audit.variance_count > 0 ? (
          <Chip
            size="small"
            label={formatNumber(audit.variance_count)}
            sx={{ bgcolor: semantic.warning.bg, color: semantic.warning.fg }}
          />
        ) : (
          <Typography variant="body2" color="text.secondary">
            0
          </Typography>
        ),
    },
    {
      key: 'source',
      label: 'Source',
      hideBelow: 'lg',
      width: 105,
      render: (audit) => <SourceChip source={audit.source} />,
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 145,
      render: (audit) => <StatusBadge status={audit.status} />,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Stock Audit"
        description="Completed counts received from the handheld devices. Open an audit to compare system stock with what was found on the shelf."
        actions={
          // Shows the page is watching rather than merely sitting there, so a
          // quiet screen reads as "nothing has arrived" instead of "this is
          // broken".
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary' }}>
            {isFetching ? <CircularProgress size={14} thickness={5} /> : null}
            <Typography variant="caption">
              {isFetching ? 'Checking for new submissions\u2026' : 'Listening for handheld submissions'}
            </Typography>
          </Box>
        }
        crumbs={[{ label: 'Stock Verification' }, { label: 'Stock Audit' }]}
      />

      <DataTable
        focusable
        focusTitle="Stock Audits"
        density="compact"
        columnToggle
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(audit) => audit.id}
        loading={isFetching}
        error={isError ? apiErrorMessage(error) : null}
        onRetry={() => void refetch()}
        total={data?.meta?.total ?? 0}
        page={table.page}
        perPage={table.perPage}
        onPageChange={table.setPage}
        onPerPageChange={table.setPerPage}
        sortBy={table.sortBy}
        sortDir={table.sortDir}
        onSortChange={table.setSort}
        onRowClick={(audit) => navigate(`/audits/${audit.id}`)}
        emptyTitle="No audits found"
        emptyDescription="Audits appear here once a device submits a completed count."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search by counted by…" />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilters({ shop_id: value, device_id: '' })}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={240}
            />
            <SelectFilter
              label="Device"
              value={table.filters.device_id ?? ''}
              onChange={(value) => table.setFilter('device_id', value)}
              options={(devices ?? []).map((device) => ({ value: device.id, label: device.label }))}
              width={140}
              disabled={!table.filters.shop_id}
            />
            <SelectFilter
              label="Status"
              value={table.filters.status ?? ''}
              onChange={(value) => table.setFilter('status', value)}
              options={[
                { value: 'submitted', label: 'Submitted' },
                { value: 'in_verification', label: 'In verification' },
                { value: 'verified', label: 'Verified' },
                { value: 'adjusted', label: 'Adjusted' },
                { value: 'closed', label: 'Closed' },
              ]}
              width={165}
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

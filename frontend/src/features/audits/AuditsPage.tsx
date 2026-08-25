import { Box, Chip, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
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

export function AuditsPage() {
  const navigate = useNavigate()
  const table = useTableQuery({ sortBy: 'submitted_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['audits', table.params],
    queryFn: async () => get<Audit[]>('/audits', table.params),
  })

  const columns: DataTableColumn<Audit>[] = [
    {
      key: 'audit_number',
      label: 'Audit',
      sortable: true,
      width: 190,
      render: (audit) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
            Audit {audit.audit_number}
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
            sx={{ bgcolor: '#FBF0DE', color: '#B26A00' }}
          />
        ) : (
          <Typography variant="body2" color="text.secondary">
            0
          </Typography>
        ),
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
        crumbs={[{ label: 'Stock Verification' }, { label: 'Stock Audit' }]}
      />

      <DataTable
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

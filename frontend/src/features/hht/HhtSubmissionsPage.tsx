import { Alert, Box, Button, Stack, Typography } from '@mui/material'
import PhonelinkSetupRoundedIcon from '@mui/icons-material/PhonelinkSetupRounded'
import OpenInNewRoundedIcon from '@mui/icons-material/OpenInNewRounded'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import { useQuery } from '@tanstack/react-query'
import { Link as RouterLink, useNavigate } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatDate, formatDateTime, formatNumber } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { HhtSubmission } from '@/types'

export function HhtSubmissionsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const table = useTableQuery({ sortBy: 'received_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['hht-submissions', table.params],
    queryFn: async () => get<HhtSubmission[]>('/hht/submissions', table.params),
  })

  const columns: DataTableColumn<HhtSubmission>[] = [
    {
      key: 'received_at',
      label: 'Received',
      sortable: true,
      width: 175,
      render: (row) => (
        <Box>
          <Typography variant="body2">{formatDateTime(row.received_at)}</Typography>
          <Typography variant="caption">{row.hht_user ?? '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'shop_code',
      label: 'Shop',
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.shop_code}
          </Typography>
          <Typography variant="caption">{row.shop_name}</Typography>
        </Box>
      ),
    },
    {
      key: 'device_code',
      label: 'Device',
      width: 110,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {row.device_code}
        </Typography>
      ),
    },
    {
      key: 'audit_number',
      label: 'Audit No.',
      sortable: true,
      align: 'right',
      width: 100,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
          {row.audit_number}
        </Typography>
      ),
    },
    {
      key: 'audit_date',
      label: 'Audit Date',
      width: 125,
      hideBelow: 'md',
      render: (row) => formatDate(row.audit_date),
    },
    {
      key: 'item_count',
      label: 'Items',
      sortable: true,
      align: 'right',
      width: 85,
      render: (row) => formatNumber(row.item_count),
    },
    {
      key: 'submission_uid',
      label: 'Submission ID',
      width: 210,
      hideBelow: 'lg',
      render: (row) => (
        <Typography variant="caption" sx={{ fontFamily: 'ui-monospace, monospace' }}>
          {row.submission_uid}
        </Typography>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 165,
      render: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'audit_id',
      label: '',
      align: 'right',
      width: 100,
      render: (row) =>
        row.audit_id ? (
          <Button
            size="small"
            endIcon={<OpenInNewRoundedIcon fontSize="small" />}
            onClick={(event) => {
              event.stopPropagation()
              navigate(`/audits/${row.audit_id}`)
            }}
            sx={{ minHeight: 28 }}
          >
            Audit
          </Button>
        ) : null,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="HHT Submissions"
        description="Completed counts received from the handheld devices. Each submission arrives once, after the count is finished on the device."
        crumbs={[{ label: 'Stock Verification' }, { label: 'HHT Submissions' }]}
        actions={
          <Stack direction="row" spacing={1.5}>
            {can(PERMISSIONS.hhtImport) ? (
              <Button
                component={RouterLink}
                to="/hht/import"
                variant="outlined"
                startIcon={<UploadFileRoundedIcon />}
              >
                Import HHT Export
              </Button>
            ) : null}
            <Button
              component={RouterLink}
              to="/hht/simulator"
              variant="contained"
              startIcon={<PhonelinkSetupRoundedIcon />}
            >
              HHT Simulator
            </Button>
          </Stack>
        }
      />

      <Alert severity="info" sx={{ mb: 2.5 }}>
        Scans stay on the device while counting — there is no continuous synchronisation. A repeated submission
        caused by a dropped connection is recognised and ignored rather than creating a second audit.
      </Alert>

      <DataTable
        focusable
        focusTitle="HHT Submissions"
        density="compact"
        columnToggle
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
        sortBy={table.sortBy}
        sortDir={table.sortDir}
        onSortChange={table.setSort}
        emptyTitle="No submissions received"
        emptyDescription="Use the HHT Simulator to send a completed count, or wait for a device to submit."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search submission or user…" />
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
                { value: 'accepted', label: 'Accepted' },
                { value: 'duplicate_ignored', label: 'Duplicate ignored' },
                { value: 'rejected', label: 'Rejected' },
              ]}
              width={185}
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

      <Stack sx={{ mt: 2 }}>
        <Typography variant="caption">
          Audit numbers repeat across devices. A submission is only ever identified by shop, device and audit number
          together.
        </Typography>
      </Stack>
    </Box>
  )
}

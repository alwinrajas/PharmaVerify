import { Alert, Box, Button, IconButton, Stack, TextField, Tooltip, Typography } from '@mui/material'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
import OpenInNewRoundedIcon from '@mui/icons-material/OpenInNewRounded'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { VarianceValue } from '@/components/VarianceValue'
import { AdjustDialog } from '@/features/adjustments/AdjustDialog'
import { useAuth } from '@/features/auth/AuthContext'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatQuantity } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { AuditLine } from '@/types'
import { VerifyLineDialog } from './VerifyLineDialog'

/**
 * The verification worklist: every counted line the user can see, with the
 * system quantity beside the physical quantity and the resulting variance.
 */
export function VerificationPage() {
  const navigate = useNavigate()
  const { can } = useAuth()
  const table = useTableQuery({ sortBy: 'id', sortDir: 'asc', filters: { verification_status: 'pending' } })

  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const [editingLine, setEditingLine] = useState<AuditLine | null>(null)
  const [adjustLines, setAdjustLines] = useState<AuditLine[]>([])

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['verification', table.params],
    queryFn: async () => get<AuditLine[]>('/verification', table.params),
  })

  const columns: DataTableColumn<AuditLine>[] = [
    {
      key: 'shop_code',
      label: 'Audit',
      width: 175,
      render: (line) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {line.shop_code} · {line.device_code}
          </Typography>
          <Typography variant="caption">Audit {line.audit_number}</Typography>
        </Box>
      ),
    },
    {
      key: 'product_code',
      label: 'Product',
      sortable: true,
      render: (line) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {line.description ?? '—'}
          </Typography>
          <Typography variant="caption">
            {[line.product_code, line.batch && `Batch ${line.batch}`].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    {
      key: 'system_qty',
      label: 'System',
      sortable: true,
      align: 'right',
      width: 95,
      render: (line) => formatQuantity(line.system_qty),
    },
    {
      key: 'physical_qty',
      label: 'Physical',
      sortable: true,
      align: 'right',
      width: 95,
      render: (line) => (
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {formatQuantity(line.physical_qty)}
        </Typography>
      ),
    },
    {
      key: 'variance_qty',
      label: 'Variance',
      sortable: true,
      align: 'right',
      width: 105,
      render: (line) => <VarianceValue value={line.variance_qty} />,
    },
    {
      key: 'verification_status',
      label: 'Verification',
      sortable: true,
      width: 125,
      render: (line) => <StatusBadge status={line.verification_status} />,
    },
    {
      key: 'adjustment_status',
      label: 'Adjustment',
      width: 130,
      hideBelow: 'lg',
      render: (line) => <StatusBadge status={line.adjustment_status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 130,
      render: (line) => (
        <Stack direction="row" spacing={0.25} justifyContent="flex-end">
          {can(PERMISSIONS.verificationEdit) ? (
            <Tooltip title="Verify or correct">
              <IconButton size="small" onClick={() => setEditingLine(line)}>
                <EditRoundedIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          ) : null}

          {can(PERMISSIONS.adjustmentsCreate) && !line.is_unknown_item ? (
            <Tooltip title={line.adjustment_status === 'adjusted' ? 'Already adjusted' : 'Post adjustment'}>
              <span>
                <IconButton
                  size="small"
                  disabled={line.adjustment_status === 'adjusted' || Number(line.variance_qty) === 0}
                  onClick={() => setAdjustLines([line])}
                >
                  <TuneRoundedIcon fontSize="small" />
                </IconButton>
              </span>
            </Tooltip>
          ) : null}

          <Tooltip title="Open audit">
            <IconButton size="small" onClick={() => navigate(`/audits/${line.audit_id}`)}>
              <OpenInNewRoundedIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        </Stack>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Stock Verification"
        description="Compare what the system holds with what was counted on the shelf, and correct the count where it is wrong."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Verification' }]}
      />

      <Alert severity="info" sx={{ mb: 2.5 }}>
        <strong>Variance = Physical Quantity − System Quantity.</strong> Editing a completed audit is permitted, and
        every change is recorded with the user, time, old value and new value.
      </Alert>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(line) => line.id}
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
        emptyTitle="Nothing to verify"
        emptyDescription="Every counted line matching your filters has been verified."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search product or batch…" />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilters({ shop_id: value, device_id: '' })}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={230}
            />
            <SelectFilter
              label="Device"
              value={table.filters.device_id ?? ''}
              onChange={(value) => table.setFilter('device_id', value)}
              options={(devices ?? []).map((device) => ({ value: device.id, label: device.label }))}
              width={135}
              disabled={!table.filters.shop_id}
            />
            <TextField
              label="Audit No."
              size="small"
              type="number"
              value={table.filters.audit_number ?? ''}
              onChange={(event) => table.setFilter('audit_number', event.target.value)}
              sx={{ width: 115 }}
            />
            <SelectFilter
              label="Verification"
              value={table.filters.verification_status ?? ''}
              onChange={(value) => table.setFilter('verification_status', value)}
              options={[
                { value: 'pending', label: 'Pending' },
                { value: 'verified', label: 'Verified' },
              ]}
              width={150}
            />
            <SelectFilter
              label="Variance"
              value={table.filters.variance ?? ''}
              onChange={(value) => table.setFilter('variance', value)}
              options={[
                { value: 'negative', label: 'Short (negative)' },
                { value: 'positive', label: 'Excess (positive)' },
                { value: 'zero', label: 'Matched (zero)' },
                { value: 'non_zero', label: 'Any variance' },
              ]}
              width={175}
            />
          </FilterBar>
        }
      />

      <VerifyLineDialog line={editingLine} open={Boolean(editingLine)} onClose={() => setEditingLine(null)} />

      <AdjustDialog lines={adjustLines} open={adjustLines.length > 0} onClose={() => setAdjustLines([])} />

      <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}>
        <Button size="small" onClick={() => navigate('/variance')}>
          Go to variance →
        </Button>
      </Stack>
    </Box>
  )
}

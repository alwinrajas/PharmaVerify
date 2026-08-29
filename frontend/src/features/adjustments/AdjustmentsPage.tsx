import { Alert, Box, Button, Stack, Typography } from '@mui/material'
import ArrowRightAltRoundedIcon from '@mui/icons-material/ArrowRightAltRounded'
import DownloadRoundedIcon from '@mui/icons-material/DownloadRounded'
import CompareArrowsRoundedIcon from '@mui/icons-material/CompareArrowsRounded'
import { useQuery } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { Link as RouterLink } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { VarianceValue } from '@/components/VarianceValue'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, download, get } from '@/services/apiClient'
import { formatDateTime, formatQuantity } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { StockAdjustment } from '@/types'

/**
 * The history of adjustments that have already been posted.
 *
 * Adjustments are made from the Variance screen or from an audit; nothing here
 * waits for approval, because the business does not have an approval step.
 */
export function AdjustmentsPage() {
  const { can } = useAuth()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'adjusted_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()
  const [exporting, setExporting] = useState(false)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['adjustments', table.params],
    queryFn: async () => get<StockAdjustment[]>('/adjustments', table.params),
  })

  async function handleExport() {
    setExporting(true)

    try {
      await download(
        '/reports/adjustment',
        { ...table.params, format: 'xlsx', page: undefined, per_page: undefined },
        'stock-adjustment-report.xlsx',
      )
    } catch (caught) {
      enqueueSnackbar(apiErrorMessage(caught, 'The report could not be exported.'), { variant: 'error' })
    } finally {
      setExporting(false)
    }
  }

  const columns: DataTableColumn<StockAdjustment>[] = [
    {
      key: 'adjusted_at',
      label: 'Adjusted',
      sortable: true,
      width: 180,
      render: (row) => (
        <Box>
          <Typography variant="body2">{formatDateTime(row.adjusted_at)}</Typography>
          <Typography variant="caption">{row.adjusted_by ?? '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'shop_code',
      label: 'Audit',
      width: 165,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.shop_code}
            {row.device_code ? ` · ${row.device_code}` : ''}
          </Typography>
          <Typography variant="caption">{row.audit_number ? `Audit ${row.audit_number}` : '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'product_code',
      label: 'Product',
      sortable: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.description ?? '—'}
          </Typography>
          <Typography variant="caption">
            {[row.product_code, row.batch && `Batch ${row.batch}`].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    {
      key: 'change',
      label: 'Quantity Change',
      align: 'right',
      width: 190,
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center" justifyContent="flex-end">
          <Typography variant="body2" color="text.secondary">
            {formatQuantity(row.old_system_qty)}
          </Typography>
          <ArrowRightAltRoundedIcon fontSize="small" sx={{ color: 'text.secondary' }} />
          <Typography variant="body2" sx={{ fontWeight: 700 }}>
            {formatQuantity(row.new_system_qty)}
          </Typography>
        </Stack>
      ),
    },
    {
      key: 'variance_qty',
      label: 'Variance',
      sortable: true,
      align: 'right',
      width: 105,
      render: (row) => <VarianceValue value={row.variance_qty} />,
    },
    {
      key: 'reason',
      label: 'Reason',
      hideBelow: 'lg',
      render: (row) => (
        <Typography variant="body2" noWrap sx={{ maxWidth: 260, color: 'text.secondary' }}>
          {row.reason ?? '—'}
        </Typography>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Stock Adjustment"
        description="Adjustments already applied to system stock, with the quantity before and after each correction."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Stock Adjustment' }]}
        actions={
          <>
            <Button component={RouterLink} to="/variance" variant="contained" startIcon={<CompareArrowsRoundedIcon />}>
              Adjust from Variance
            </Button>
            {can(PERMISSIONS.reportsExport) ? (
              <Button
                variant="outlined"
                startIcon={<DownloadRoundedIcon />}
                onClick={() => void handleExport()}
                disabled={exporting}
              >
                {exporting ? 'Preparing…' : 'Export Excel'}
              </Button>
            ) : null}
          </>
        }
      />

      <Alert severity="info" sx={{ mb: 2.5 }}>
        Adjustments take effect the moment they are saved — there is no approval step. This screen is the record of
        what was changed, by whom and when.
      </Alert>

      <DataTable
        focusable
        focusTitle="Stock Adjustments"
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
        emptyTitle="No adjustments posted"
        emptyDescription="Open the Variance screen to post an adjustment against a counted line."
        emptyAction={
          <Button component={RouterLink} to="/variance" variant="contained" size="small">
            Open variance
          </Button>
        }
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search product, batch, reason…" width={280} />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={240}
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

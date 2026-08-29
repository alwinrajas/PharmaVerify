import { Box, Button, Checkbox, TextField, Typography } from '@mui/material'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
import DownloadRoundedIcon from '@mui/icons-material/DownloadRounded'
import PictureAsPdfRoundedIcon from '@mui/icons-material/PictureAsPdfRounded'
import { useQuery } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { VarianceValue } from '@/components/VarianceValue'
import { AdjustDialog } from '@/features/adjustments/AdjustDialog'
import { useAuth } from '@/features/auth/AuthContext'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, download, get } from '@/services/apiClient'
import { formatDate, formatNumber, formatQuantity } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { AuditLine, VarianceSummary } from '@/types'
import TrendingDownRoundedIcon from '@mui/icons-material/TrendingDownRounded'
import TrendingUpRoundedIcon from '@mui/icons-material/TrendingUpRounded'
import DoneAllRoundedIcon from '@mui/icons-material/DoneAllRounded'
import RuleRoundedIcon from '@mui/icons-material/RuleRounded'
import CompareArrowsRoundedIcon from '@mui/icons-material/CompareArrowsRounded'
import PendingActionsRoundedIcon from '@mui/icons-material/PendingActionsRounded'
import { KpiStrip } from '@/components/KpiStrip'

export function VariancePage() {
  const { can } = useAuth()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'variance_qty', sortDir: 'asc', filters: { variance: 'non_zero' } })

  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const [selected, setSelected] = useState<number[]>([])
  const [adjustLines, setAdjustLines] = useState<AuditLine[]>([])
  const [exporting, setExporting] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['variance', table.params],
    queryFn: async () => get<AuditLine[]>('/variance', table.params),
  })

  const summary = (data?.meta as unknown as { summary?: VarianceSummary } | undefined)?.summary
  const rows = data?.data ?? []
  const selectedLines = rows.filter(
    (line) => selected.includes(line.id) && !line.is_unknown_item && line.adjustment_status !== 'adjusted',
  )

  async function handleExport(format: 'xlsx' | 'pdf') {
    setExporting(format)

    try {
      await download(
        '/reports/variance',
        { ...table.params, format, per_page: undefined, page: undefined },
        `variance-report.${format}`,
      )
    } catch (caught) {
      enqueueSnackbar(apiErrorMessage(caught, 'The report could not be exported.'), { variant: 'error' })
    } finally {
      setExporting(null)
    }
  }

  const columns: DataTableColumn<AuditLine>[] = [
    {
      key: 'select',
      label: '',
      width: 48,
      render: (line) => (
        <Checkbox
          size="small"
          checked={selected.includes(line.id)}
          disabled={line.is_unknown_item || line.adjustment_status === 'adjusted'}
          onChange={() =>
            setSelected((current) =>
              current.includes(line.id) ? current.filter((entry) => entry !== line.id) : [...current, line.id],
            )
          }
        />
      ),
    },
    {
      key: 'shop_code',
      label: 'Audit',
      width: 170,
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
            {[line.product_code, line.barcode].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    { key: 'batch', label: 'Batch', sortable: true, width: 105 },
    {
      key: 'expiry_date',
      label: 'Expiry',
      sortable: true,
      width: 120,
      hideBelow: 'lg',
      render: (line) => formatDate(line.expiry_date),
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
      // Counted alongside the whole units, never folded into them.
      key: 'loose_qty',
      label: 'Loose',
      sortable: true,
      align: 'right',
      width: 90,
      render: (line) => (Number(line.loose_qty) === 0 ? '—' : formatQuantity(line.loose_qty)),
    },
    {
      key: 'variance_qty',
      label: 'Variance',
      sortable: true,
      align: 'right',
      width: 110,
      render: (line) => <VarianceValue value={line.variance_qty} />,
    },
    {
      key: 'adjustment_status',
      label: 'Adjustment',
      width: 135,
      render: (line) => <StatusBadge status={line.adjustment_status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 100,
      render: (line) =>
        can(PERMISSIONS.adjustmentsCreate) && !line.is_unknown_item && line.adjustment_status !== 'adjusted' ? (
          <Button size="small" startIcon={<TuneRoundedIcon fontSize="small" />} onClick={() => setAdjustLines([line])}>
            Adjust
          </Button>
        ) : null,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Variance"
        description="Where the physical count and the system stock disagree, across every audit you can see."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Variance' }]}
        actions={
          can(PERMISSIONS.reportsExport) ? (
            <>
              <Button
                variant="outlined"
                startIcon={<DownloadRoundedIcon />}
                onClick={() => void handleExport('xlsx')}
                disabled={exporting !== null}
              >
                {exporting === 'xlsx' ? 'Preparing…' : 'Excel'}
              </Button>
              <Button
                variant="outlined"
                startIcon={<PictureAsPdfRoundedIcon />}
                onClick={() => void handleExport('pdf')}
                disabled={exporting !== null}
              >
                {exporting === 'pdf' ? 'Preparing…' : 'PDF'}
              </Button>
            </>
          ) : null
        }
      />

      {summary ? (
        <KpiStrip
          items={[
            { label: 'Lines in scope', value: formatNumber(summary.total_lines), icon: RuleRoundedIcon },
            {
              label: 'Short lines',
              value: formatNumber(summary.short_count),
              hint: `${formatQuantity(summary.short_quantity)} units`,
              icon: TrendingDownRoundedIcon,
              tone: 'short',
            },
            {
              // Excess is a discrepancy, not a success — see the variance
              // semantics in the theme.
              label: 'Excess lines',
              value: formatNumber(summary.excess_count),
              hint: `${formatQuantity(summary.excess_quantity)} units`,
              icon: TrendingUpRoundedIcon,
              tone: 'excess',
            },
            {
              label: 'Matched lines',
              value: formatNumber(summary.matched_count),
              icon: DoneAllRoundedIcon,
              tone: 'matched',
            },
            {
              // System - (Physical + Loose): a positive net is a net shortage.
              label: 'Net variance',
              value: formatQuantity(summary.net_variance),
              icon: CompareArrowsRoundedIcon,
              tone: summary.net_variance > 0 ? 'short' : summary.net_variance < 0 ? 'excess' : 'matched',
            },
            {
              label: 'Awaiting adjustment',
              value: formatNumber(summary.pending_adjustment),
              icon: PendingActionsRoundedIcon,
              tone: summary.pending_adjustment > 0 ? 'warning' : 'default',
            },
          ]}
        />
      ) : null}

      <DataTable
        focusable
        focusTitle="Variance"
        density="compact"
        columnToggle
        freezeFirstColumn
        columns={columns}
        rows={rows}
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
        emptyTitle="No variance found"
        emptyDescription="Every counted line matching your filters agrees with the system stock."
        toolbar={
          <FilterBar
            hasFilters={table.hasFilters}
            onClear={table.clearFilters}
            actions={
              selectedLines.length > 0 && can(PERMISSIONS.adjustmentsCreate) ? (
                <Button
                  variant="contained"
                  size="small"
                  startIcon={<TuneRoundedIcon />}
                  onClick={() => setAdjustLines(selectedLines)}
                >
                  Adjust {selectedLines.length} selected
                </Button>
              ) : null
            }
          >
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search product, barcode, batch…" width={280} />
            <SelectFilter
              label="Variance"
              value={table.filters.variance ?? ''}
              onChange={(value) => table.setFilter('variance', value)}
              allLabel="Any variance"
              options={[
                { value: 'short', label: 'Short' },
                { value: 'excess', label: 'Excess' },
                { value: 'zero', label: 'Matched' },
                { value: 'all', label: 'All lines' },
              ]}
              width={175}
            />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilters({ shop_id: value, device_id: '' })}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={225}
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

      <AdjustDialog
        lines={adjustLines}
        open={adjustLines.length > 0}
        onClose={() => setAdjustLines([])}
        onPosted={() => setSelected([])}
      />
    </Box>
  )
}

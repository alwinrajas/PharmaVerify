import {
  Box,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import AssessmentRoundedIcon from '@mui/icons-material/AssessmentRounded'
import DownloadRoundedIcon from '@mui/icons-material/DownloadRounded'
import PictureAsPdfRoundedIcon from '@mui/icons-material/PictureAsPdfRounded'
import ArrowBackRoundedIcon from '@mui/icons-material/ArrowBackRounded'
import { useQuery } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { LoadingState } from '@/components/states'
import { useAuth } from '@/features/auth/AuthContext'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, download, get } from '@/services/apiClient'
import { formatByType } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { ReportDescriptor, ReportRow } from '@/types'

/**
 * One screen for all nine reports.
 *
 * The server describes each report — its columns, its filters and its default
 * sort — so this page renders any of them, and the Excel and PDF exports come
 * from exactly the same description.
 */
export function ReportsPage() {
  const { reportKey } = useParams<{ reportKey?: string }>()
  const navigate = useNavigate()

  const catalogue = useQuery({
    queryKey: ['reports-catalogue'],
    queryFn: async () => (await get<ReportDescriptor[]>('/reports')).data,
    staleTime: 10 * 60 * 1000,
  })

  if (catalogue.isLoading) return <LoadingState label="Loading reports…" height={340} />

  const reports = catalogue.data ?? []

  if (!reportKey) {
    return (
      <Box>
        <PageHeader
          title="Reports"
          description="Every report supports search, filters, sorting, and export to Excel or PDF."
          crumbs={[{ label: 'Output' }, { label: 'Reports' }]}
        />

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' } }}>
          {reports.map((report) => (
            <Card key={report.key}>
              <CardActionArea onClick={() => navigate(`/reports/${report.key}`)} sx={{ height: '100%' }}>
                <CardContent sx={{ p: 2.5 }}>
                  <Box
                    sx={{
                      width: 38,
                      height: 38,
                      borderRadius: 2,
                      display: 'grid',
                      placeItems: 'center',
                      bgcolor: 'rgba(15,93,76,0.08)',
                      color: 'primary.main',
                      mb: 1.5,
                    }}
                  >
                    <AssessmentRoundedIcon fontSize="small" />
                  </Box>

                  <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
                    {report.title}
                  </Typography>
                  <Typography variant="body2" color="text.secondary" sx={{ minHeight: 40 }}>
                    {report.description}
                  </Typography>

                  <Stack direction="row" spacing={0.75} sx={{ mt: 1.5, flexWrap: 'wrap', gap: 0.75 }}>
                    <Chip size="small" label={`${report.columns.length} columns`} sx={{ bgcolor: '#ECEFEE' }} />
                    <Chip size="small" label="Excel" sx={{ bgcolor: '#E4F3EA', color: '#1B6E3C' }} />
                    <Chip size="small" label="PDF" sx={{ bgcolor: '#FBE7E5', color: '#B3261E' }} />
                  </Stack>
                </CardContent>
              </CardActionArea>
            </Card>
          ))}
        </Box>
      </Box>
    )
  }

  const descriptor = reports.find((report) => report.key === reportKey)

  if (!descriptor) {
    return (
      <Box>
        <PageHeader title="Report not found" crumbs={[{ label: 'Output' }, { label: 'Reports', to: '/reports' }]} />
        <Button variant="outlined" onClick={() => navigate('/reports')}>
          Back to reports
        </Button>
      </Box>
    )
  }

  return <ReportViewer key={descriptor.key} descriptor={descriptor} />
}

function ReportViewer({ descriptor }: { descriptor: ReportDescriptor }) {
  const navigate = useNavigate()
  const { can } = useAuth()
  const { enqueueSnackbar } = useSnackbar()

  const table = useTableQuery({ sortBy: descriptor.default_sort, sortDir: descriptor.default_sort_dir })
  const { data: shops } = useShopOptions()
  const { data: devices } = useDeviceOptions(table.filters.shop_id || null)

  const [exporting, setExporting] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['report', descriptor.key, table.params],
    queryFn: async () => get<ReportRow[]>(`/reports/${descriptor.key}`, table.params),
  })

  const summary = (data?.meta as unknown as { summary?: Record<string, number> } | undefined)?.summary

  async function handleExport(format: 'xlsx' | 'pdf') {
    setExporting(format)

    try {
      await download(
        `/reports/${descriptor.key}`,
        { ...table.params, format, page: undefined, per_page: undefined },
        `${descriptor.key}.${format}`,
      )
    } catch (caught) {
      enqueueSnackbar(apiErrorMessage(caught, 'The report could not be exported.'), { variant: 'error' })
    } finally {
      setExporting(null)
    }
  }

  const columns: DataTableColumn<ReportRow>[] = descriptor.columns.map((column) => ({
    key: column.key,
    label: column.label,
    sortable: true,
    align: ['decimal', 'money', 'number'].includes(column.type) ? 'right' : 'left',
    render: (row) => {
      const value = row[column.key]
      const isVariance = column.key === 'variance_qty' || column.key === 'net_variance'
      const amount = Number(value ?? 0)

      return (
        <Typography
          variant="body2"
          component="span"
          sx={
            isVariance && amount !== 0
              ? { color: amount < 0 ? 'error.main' : 'success.main', fontWeight: 700 }
              : undefined
          }
        >
          {isVariance && amount > 0 ? '+' : ''}
          {formatByType(value, column.type)}
        </Typography>
      )
    },
  }))

  const supports = (filter: string) => descriptor.filters.includes(filter)

  return (
    <Box>
      <PageHeader
        title={descriptor.title}
        description={descriptor.description}
        crumbs={[{ label: 'Output' }, { label: 'Reports', to: '/reports' }, { label: descriptor.title }]}
        actions={
          <>
            <Button variant="outlined" startIcon={<ArrowBackRoundedIcon />} onClick={() => navigate('/reports')}>
              All reports
            </Button>
            {can(PERMISSIONS.reportsExport) ? (
              <>
                <Button
                  variant="outlined"
                  startIcon={<DownloadRoundedIcon />}
                  disabled={exporting !== null}
                  onClick={() => void handleExport('xlsx')}
                >
                  {exporting === 'xlsx' ? 'Preparing…' : 'Excel'}
                </Button>
                <Button
                  variant="contained"
                  startIcon={<PictureAsPdfRoundedIcon />}
                  disabled={exporting !== null}
                  onClick={() => void handleExport('pdf')}
                >
                  {exporting === 'pdf' ? 'Preparing…' : 'PDF'}
                </Button>
              </>
            ) : null}
          </>
        }
      />

      {summary && Object.keys(summary).length > 0 ? (
        <Box
          sx={{
            display: 'grid',
            gap: 2,
            gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: `repeat(${Math.min(Object.keys(summary).length, 4)}, 1fr)` },
            mb: 2.5,
          }}
        >
          {Object.entries(summary).map(([label, value]) => (
            <Card key={label}>
              <CardContent sx={{ p: 1.75, '&:last-child': { pb: 1.75 } }}>
                <Typography variant="caption" sx={{ display: 'block' }}>
                  {label.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())}
                </Typography>
                <Typography
                  sx={{
                    fontSize: '1.25rem',
                    fontWeight: 700,
                    color: label.includes('variance') && Number(value) < 0 ? 'error.main' : 'text.primary',
                  }}
                >
                  {formatByType(value, Number.isInteger(value) ? 'number' : 'decimal')}
                </Typography>
              </CardContent>
            </Card>
          ))}
        </Box>
      ) : null}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(row) => JSON.stringify(row).slice(0, 120)}
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
        dense
        emptyTitle="No records matched"
        emptyDescription="Adjust the filters above and run the report again."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            {supports('search') ? (
              <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search…" width={260} />
            ) : null}

            {supports('shop_id') ? (
              <SelectFilter
                label="Shop"
                value={table.filters.shop_id ?? ''}
                onChange={(value) => table.setFilters({ shop_id: value, device_id: '' })}
                options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
                width={230}
              />
            ) : null}

            {supports('device_id') ? (
              <SelectFilter
                label="Device"
                value={table.filters.device_id ?? ''}
                onChange={(value) => table.setFilter('device_id', value)}
                options={(devices ?? []).map((device) => ({ value: device.id, label: device.label }))}
                width={135}
                disabled={!table.filters.shop_id}
              />
            ) : null}

            {supports('audit_number') ? (
              <TextField
                label="Audit No."
                size="small"
                type="number"
                value={table.filters.audit_number ?? ''}
                onChange={(event) => table.setFilter('audit_number', event.target.value)}
                sx={{ width: 115 }}
              />
            ) : null}

            {supports('variance') ? (
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
            ) : null}

            {supports('date_from') ? (
              <DateFilter
                label="From"
                value={table.filters.date_from ?? ''}
                onChange={(value) => table.setFilter('date_from', value)}
              />
            ) : null}

            {supports('date_to') ? (
              <DateFilter
                label="To"
                value={table.filters.date_to ?? ''}
                onChange={(value) => table.setFilter('date_to', value)}
              />
            ) : null}

            {supports('expiry_from') ? (
              <DateFilter
                label="Expiry from"
                value={table.filters.expiry_from ?? ''}
                onChange={(value) => table.setFilter('expiry_from', value)}
              />
            ) : null}

            {supports('expiry_to') ? (
              <DateFilter
                label="Expiry to"
                value={table.filters.expiry_to ?? ''}
                onChange={(value) => table.setFilter('expiry_to', value)}
              />
            ) : null}
          </FilterBar>
        }
      />
    </Box>
  )
}

import {
  Alert,
  AlertTitle,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  LinearProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import DescriptionRoundedIcon from '@mui/icons-material/DescriptionRounded'
import WarningAmberRoundedIcon from '@mui/icons-material/WarningAmberRounded'
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useRef, useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { ConfirmDialog } from '@/components/dialogs'
import { FilterBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, apiClient, get } from '@/services/apiClient'
import { formatDateTime, formatNumber } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { StockImport, StockImportError } from '@/types'

/**
 * The endpoint accepts two file shapes. A Stock Report covers every branch it
 * names and returns one record per shop plus an overall summary; the older flat
 * file covers one shop and returns a single record. Both are folded into the
 * same shape so the summary panel does not care which arrived.
 */
interface StockReportSummary {
  shops: number
  total_rows: number
  imported: number
  failed: number
  replaced: number
  items_synced: number
  barcodes_matched: number
}

type ImportResponse = {
  message?: string
  data: StockImport | StockImport[]
  meta?: { summary?: StockReportSummary; format?: string }
}

interface ImportOutcome {
  format: 'stock_report' | 'flat'
  fileName: string
  total: number
  imported: number
  failed: number
  replaced: number
  shops: number
  itemsSynced: number
  /** The record errors were recorded against, if any. */
  primary: StockImport | null
}

function toOutcome(response: ImportResponse): ImportOutcome {
  const records = Array.isArray(response.data) ? response.data : [response.data]
  const summary = response.meta?.summary

  if (response.meta?.format === 'stock_report' && summary) {
    return {
      format: 'stock_report',
      fileName: records[0]?.file_name ?? 'Stock report.xlsx',
      total: summary.total_rows,
      imported: summary.imported,
      failed: summary.failed,
      replaced: summary.replaced,
      shops: summary.shops,
      itemsSynced: summary.items_synced,
      primary: records.find((r) => r.failed_records > 0) ?? records[0] ?? null,
    }
  }

  const single = records[0]

  return {
    format: 'flat',
    fileName: single?.file_name ?? '',
    total: single?.total_records ?? 0,
    imported: single?.success_records ?? 0,
    failed: single?.failed_records ?? 0,
    replaced: single?.replaced_records ?? 0,
    shops: 1,
    itemsSynced: 0,
    primary: single ?? null,
  }
}

export function StockImportPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'imported_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()
  const fileInput = useRef<HTMLInputElement>(null)

  const [shopId, setShopId] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [progress, setProgress] = useState(0)
  const [result, setResult] = useState<ImportOutcome | null>(null)
  const [selectedImport, setSelectedImport] = useState<StockImport | null>(null)

  const { data: template } = useQuery({
    queryKey: ['stock-import-template'],
    queryFn: async () =>
      (await get<{ required: string[]; optional: string[]; note: string }>('/stock-imports/template')).data,
    staleTime: Infinity,
  })

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['stock-imports', table.params],
    queryFn: async () => get<StockImport[]>('/stock-imports', table.params),
  })

  const { data: errorRows } = useQuery({
    queryKey: ['stock-import-errors', selectedImport?.id],
    queryFn: async () => get<StockImportError[]>(`/stock-imports/${selectedImport!.id}/errors`, { per_page: 100 }),
    enabled: Boolean(selectedImport?.id),
  })

  const importMutation = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
      if (shopId) payload.append('shop_id', shopId)
      payload.append('file', file as File)

      const response = await apiClient.post<ImportResponse>('/stock-imports', payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event) => {
          if (event.total) setProgress(Math.round((event.loaded / event.total) * 100))
        },
      })

      return response.data
    },
    onSuccess: (response) => {
      const outcome = toOutcome(response)
      setResult(outcome)
      enqueueSnackbar(response.message ?? 'Stock import completed.', {
        variant: outcome.failed > 0 ? 'warning' : 'success',
      })

      void queryClient.invalidateQueries({ queryKey: ['stock-imports'] })
      void queryClient.invalidateQueries({ queryKey: ['item-stocks'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      setFile(null)
      if (fileInput.current) fileInput.current.value = ''
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught, 'The stock import could not be completed.'), { variant: 'error' })
    },
    onSettled: () => {
      setProgress(0)
      setConfirmOpen(false)
    },
  })

  const selectedShop = (shops ?? []).find((shop) => String(shop.id) === shopId)

  const columns: DataTableColumn<StockImport>[] = [
    {
      key: 'imported_at',
      label: 'Imported',
      sortable: true,
      width: 175,
      render: (row) => (
        <Box>
          <Typography variant="body2">{formatDateTime(row.imported_at)}</Typography>
          <Typography variant="caption">{row.imported_by ?? '—'}</Typography>
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
      key: 'file_name',
      label: 'File',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <DescriptionRoundedIcon fontSize="small" sx={{ color: 'text.secondary' }} />
          <Typography variant="body2" noWrap sx={{ maxWidth: 280 }}>
            {row.file_name}
          </Typography>
        </Stack>
      ),
    },
    {
      key: 'total_records',
      label: 'Total',
      sortable: true,
      align: 'right',
      width: 80,
      render: (row) => formatNumber(row.total_records),
    },
    {
      key: 'success_records',
      label: 'Imported',
      align: 'right',
      width: 95,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 600, color: 'success.main' }}>
          {formatNumber(row.success_records)}
        </Typography>
      ),
    },
    {
      key: 'failed_records',
      label: 'Failed',
      align: 'right',
      width: 80,
      render: (row) =>
        row.failed_records > 0 ? (
          <Button size="small" color="error" onClick={() => setSelectedImport(row)} sx={{ minHeight: 26, py: 0 }}>
            {formatNumber(row.failed_records)}
          </Button>
        ) : (
          <Typography variant="body2" color="text.secondary">
            0
          </Typography>
        ),
    },
    {
      key: 'replaced_records',
      label: 'Replaced',
      align: 'right',
      width: 95,
      hideBelow: 'lg',
      render: (row) => formatNumber(row.replaced_records),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 180,
      render: (row) => <StatusBadge status={row.status} />,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Item Stock Import"
        description="Load system stock from the business Stock Report. Importing replaces the stock of every shop the file covers."
        crumbs={[{ label: 'Master' }, { label: 'Item Stock Import' }]}
      />

      {can(PERMISSIONS.stockImport) ? (
        <Card sx={{ mb: 3 }}>
          <CardContent sx={{ p: { xs: 2, sm: 3 } }}>
            <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
              Import stock file
            </Typography>
            <Typography variant="caption" sx={{ display: 'block', mb: 2.5 }}>
              Supported formats: .xls and .xlsx, up to 100 MB. A large Stock Report can take a minute or two to process.
            </Typography>

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '300px 1fr auto' }, alignItems: 'start' }}>
              <TextField
                select
                label="Shop (optional)"
                value={shopId}
                onChange={(event) => setShopId(event.target.value)}
                size="small"
                fullWidth
                slotProps={{ select: { native: true } }}
                helperText="A Stock Report names its own shops. Choose one only to import a single branch."
              >
                <option value="">All shops named in the file</option>
                {(shops ?? []).map((shop) => (
                  <option key={shop.id} value={shop.id}>
                    {shop.label}
                  </option>
                ))}
              </TextField>

              <Box>
                <Button
                  component="label"
                  variant="outlined"
                  startIcon={<UploadFileRoundedIcon />}
                  sx={{ borderStyle: 'dashed', borderColor: 'divider', color: 'text.primary', width: '100%', justifyContent: 'flex-start' }}
                >
                  {file ? file.name : 'Choose an Excel file…'}
                  <input
                    ref={fileInput}
                    hidden
                    type="file"
                    accept=".xls,.xlsx"
                    onChange={(event) => {
                      setFile(event.target.files?.[0] ?? null)
                      setResult(null)
                    }}
                  />
                </Button>

                {file ? (
                  <Typography variant="caption" sx={{ display: 'block', mt: 0.75 }}>
                    {(file.size / 1024).toFixed(0)} KB selected
                  </Typography>
                ) : null}
              </Box>

              <Button
                variant="contained"
                disabled={!file || importMutation.isPending}
                onClick={() => setConfirmOpen(true)}
                sx={{ minWidth: 150 }}
              >
                {importMutation.isPending ? 'Importing…' : 'Import Stock'}
              </Button>
            </Box>

            {importMutation.isPending ? (
              <Box sx={{ mt: 2.5 }}>
                <LinearProgress variant={progress > 0 && progress < 100 ? 'determinate' : 'indeterminate'} value={progress} />
                <Typography variant="caption" sx={{ mt: 0.75, display: 'block' }}>
                  {progress < 100
                    ? `Uploading… ${progress}%`
                    : 'Reading the workbook, validating rows and replacing stock. Large reports take a minute or two — please keep this tab open.'}
                </Typography>
              </Box>
            ) : null}

            {template ? (
              <Box sx={{ mt: 3, pt: 2.5, borderTop: 1, borderColor: 'divider' }}>
                <Typography variant="subtitle2" sx={{ mb: 1 }}>
                  Expected columns
                </Typography>
                <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75 }}>
                  {template.required.map((column) => (
                    <Chip key={column} size="small" label={`${column} *`} color="primary" variant="outlined" />
                  ))}
                  {template.optional.map((column) => (
                    <Chip key={column} size="small" label={column} variant="outlined" sx={{ borderColor: 'divider' }} />
                  ))}
                </Stack>
                <Typography variant="caption" sx={{ display: 'block', mt: 1.25 }}>
                  * Required. Column headings are matched flexibly, so “Item Code” and “Product Code” both work.
                </Typography>
              </Box>
            ) : null}
          </CardContent>
        </Card>
      ) : (
        <Alert severity="info" sx={{ mb: 3 }}>
          You can view import history, but you do not have permission to import stock.
        </Alert>
      )}

      {result ? (
        <Alert
          severity={result.failed > 0 ? 'warning' : 'success'}
          icon={result.failed > 0 ? <WarningAmberRoundedIcon /> : <CheckCircleRoundedIcon />}
          sx={{ mb: 3 }}
          action={
            result.failed > 0 && result.primary ? (
              <Button color="inherit" size="small" onClick={() => setSelectedImport(result.primary)}>
                View errors
              </Button>
            ) : null
          }
        >
          <AlertTitle>
            Import summary — {result.fileName}
            {result.format === 'stock_report' ? ' (Stock Report)' : ''}
          </AlertTitle>
          <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', mt: 0.5 }}>
            <SummaryFigure label="Total rows" value={result.total} />
            <SummaryFigure label="Imported" value={result.imported} tone="success" />
            <SummaryFigure label="Failed" value={result.failed} tone={result.failed ? 'error' : undefined} />
            <SummaryFigure label="Previous records replaced" value={result.replaced} />
            {result.format === 'stock_report' ? (
              <>
                <SummaryFigure label="Shops updated" value={result.shops} />
                <SummaryFigure label="Products synced" value={result.itemsSynced} />
              </>
            ) : null}
          </Stack>
        </Alert>
      ) : null}

      <Typography variant="h4" sx={{ mb: 1.5 }}>
        Import history
      </Typography>

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
        sortBy={table.sortBy}
        sortDir={table.sortDir}
        onSortChange={table.setSort}
        emptyTitle="No imports yet"
        emptyDescription="Once a stock file is imported it will be listed here with its summary."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={260}
            />
            <SelectFilter
              label="Status"
              value={table.filters.status ?? ''}
              onChange={(value) => table.setFilter('status', value)}
              options={[
                { value: 'completed', label: 'Completed' },
                { value: 'completed_with_errors', label: 'Completed with errors' },
                { value: 'failed', label: 'Failed' },
              ]}
              width={210}
            />
          </FilterBar>
        }
      />

      {/* Confirm the replacement — this is destructive for the shop's stock. */}
      <ConfirmDialog
        open={confirmOpen}
        title={selectedShop ? 'Replace stock for this shop?' : 'Replace stock for every shop in the file?'}
        message={
          selectedShop
            ? `Importing this file will remove the stock ${selectedShop.label} currently holds and replace it with the contents of the file. This cannot be undone.`
            : 'Importing will remove the stock currently held by every shop the file covers and replace it with the contents of the file. Shops the file does not mention are left untouched. This cannot be undone.'
        }
        confirmLabel="Import and replace"
        severity="warning"
        busy={importMutation.isPending}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => importMutation.mutate()}
        detail={
          <Alert severity="info" sx={{ py: 0.5 }}>
            If the file cannot be processed, nothing is changed and the existing stock stays exactly as it is.
          </Alert>
        }
      />

      {/* Row-level error report */}
      <ConfirmDialog
        open={Boolean(selectedImport)}
        title={`Validation errors — ${selectedImport?.file_name ?? ''}`}
        message={`${selectedImport?.failed_records ?? 0} row(s) could not be imported. The remaining rows were imported successfully.`}
        confirmLabel="Close"
        cancelLabel=""
        onConfirm={() => setSelectedImport(null)}
        onClose={() => setSelectedImport(null)}
        detail={
          <Box sx={{ maxHeight: 320, overflowY: 'auto' }}>
            <Stack divider={<Divider flexItem />}>
              {(errorRows?.data ?? []).map((row) => (
                <Box key={row.id} sx={{ py: 1 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    Row {row.row_number}
                    {row.column_name ? ` · ${row.column_name}` : ''}
                  </Typography>
                  <Typography variant="caption" sx={{ display: 'block', color: 'error.main' }}>
                    {row.error_message}
                  </Typography>
                  {row.column_value ? (
                    <Typography variant="caption" sx={{ display: 'block' }}>
                      Value: “{row.column_value}”
                    </Typography>
                  ) : null}
                </Box>
              ))}
            </Stack>
          </Box>
        }
      />
    </Box>
  )
}

function SummaryFigure({ label, value, tone }: { label: string; value: number; tone?: 'success' | 'error' }) {
  const colour = tone === 'success' ? 'success.main' : tone === 'error' ? 'error.main' : 'text.primary'

  return (
    <Box>
      <Typography variant="caption" sx={{ display: 'block' }}>
        {label}
      </Typography>
      <Typography sx={{ fontSize: '1.125rem', fontWeight: 700, color: colour }}>{formatNumber(value)}</Typography>
    </Box>
  )
}

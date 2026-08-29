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
  Typography,
} from '@mui/material'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import DescriptionRoundedIcon from '@mui/icons-material/DescriptionRounded'
import WarningAmberRoundedIcon from '@mui/icons-material/WarningAmberRounded'
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useRef, useState } from 'react'

function useLiveProgressTracker({
  isPending,
  progress,
  fileSize,
}: {
  isPending: boolean
  progress: number
  fileSize: number
}) {
  const [elapsedSec, setElapsedSec] = useState(0)
  const [uploadSpeed, setUploadSpeed] = useState<string>('')
  const [uploadEtaSec, setUploadEtaSec] = useState<number | null>(null)

  const startTimeRef = useRef<number | null>(null)
  const uploadEndTimeRef = useRef<number | null>(null)

  useEffect(() => {
    if (!isPending) {
      setElapsedSec(0)
      setUploadSpeed('')
      setUploadEtaSec(null)
      startTimeRef.current = null
      uploadEndTimeRef.current = null
      return
    }

    if (!startTimeRef.current) {
      startTimeRef.current = Date.now()
    }

    const timer = setInterval(() => {
      if (startTimeRef.current) {
        setElapsedSec(Math.floor((Date.now() - startTimeRef.current) / 1000))
      }
    }, 500)

    return () => clearInterval(timer)
  }, [isPending])

  useEffect(() => {
    if (!isPending || !startTimeRef.current) return

    if (progress > 0 && progress < 100 && fileSize > 0) {
      const elapsed = (Date.now() - startTimeRef.current) / 1000
      if (elapsed > 0.1) {
        const loaded = (fileSize * progress) / 100
        const bytesPerSec = loaded / elapsed
        const remaining = fileSize - loaded
        if (bytesPerSec > 0) {
          const eta = Math.ceil(remaining / bytesPerSec)
          setUploadEtaSec(eta)
          if (bytesPerSec > 1024 * 1024) {
            setUploadSpeed(`${(bytesPerSec / (1024 * 1024)).toFixed(1)} MB/s`)
          } else {
            setUploadSpeed(`${(bytesPerSec / 1024).toFixed(0)} KB/s`)
          }
        }
      }
    } else if (progress === 100) {
      if (!uploadEndTimeRef.current) {
        uploadEndTimeRef.current = Date.now()
      }
    }
  }, [isPending, progress, fileSize])

  // Processing estimation for server phase
  const fileMb = fileSize ? fileSize / (1024 * 1024) : 1
  const estimatedServerTotal = Math.max(5, Math.ceil(fileMb * 5.5))

  const serverSec = uploadEndTimeRef.current
    ? Math.floor((Date.now() - uploadEndTimeRef.current) / 1000)
    : Math.max(0, elapsedSec - 2)

  const serverRemaining = Math.max(1, estimatedServerTotal - serverSec)

  const format = (sec: number) => {
    const m = Math.floor(sec / 60)
    const s = sec % 60
    return m > 0 ? `${m}m ${s < 10 ? '0' : ''}${s}s` : `${s}s`
  }

  const isUploading = progress > 0 && progress < 100

  let buttonTimeText = ''
  let progressLineText = ''

  if (isUploading) {
    const etaStr = uploadEtaSec !== null ? `${format(uploadEtaSec)} left` : 'calculating...'
    buttonTimeText = `${progress}% | ${etaStr}`
    progressLineText = `Uploading… ${progress}%${uploadSpeed ? ` (${uploadSpeed})` : ''} • Elapsed: ${format(elapsedSec)} (${etaStr})`
  } else if (progress === 100) {
    const remStr = serverRemaining > 1 ? `~${format(serverRemaining)} left` : 'almost done'
    buttonTimeText = `${format(serverSec)} | ${remStr}`
    progressLineText = `Reading workbook & validating rows… Elapsed: ${format(serverSec)} (${remStr})`
  } else {
    buttonTimeText = `${format(elapsedSec)}`
    progressLineText = `Starting upload… Elapsed: ${format(elapsedSec)}`
  }

  return {
    buttonTimeText,
    progressLineText,
    isUploading,
  }
}
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
  items_created: number
  barcodes_matched: number
  gtin_missing: number
  gtin_duplicates: number
}

/**
 * What a file would do, worked out before it is allowed to do it.
 *
 * Importing replaces a shop's stock outright, so nothing is written until the
 * user has seen which branches the file covers and what each one currently
 * holds.
 */
export interface StockPreviewShop {
  shop_id: number
  shop_code: string | null
  shop_name: string | null
  ax_location_id: string | null
  existing_records: number
  incoming_records: number
  action: 'replace'
}

export interface StockPreview {
  file_name: string
  total_rows: number
  valid_rows: number
  invalid_rows: number
  locations_detected: number
  shops: StockPreviewShop[]
  unmatched_locations: Array<{ ax_location_id: string; rows: number }>
  items_referenced: number
  items_matched: number
  items_unmatched: number
  gtin_missing: number
  gtin_duplicates: Array<{ gtin: string; product_codes: string[] }>
  sample_errors: Array<{ row_number: number; column_name: string; column_value: string | null; error_message: string }>
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
  itemsCreated: number
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
      itemsCreated: summary.items_created,
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
    itemsCreated: 0,
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

  const [file, setFile] = useState<File | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [preview, setPreview] = useState<StockPreview | null>(null)
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

  /**
   * Reads the file and reports what it would do. Writes nothing, so it is safe
   * to run as often as the user likes before committing to a replacement.
   */
  const previewMutation = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
      payload.append('file', file as File)

      const response = await apiClient.post<{ data: StockPreview }>('/stock-imports/preview', payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event) => {
          if (event.total) setProgress(Math.round((event.loaded / event.total) * 100))
        },
      })

      return response.data.data
    },
    onSuccess: (checked) => {
      setPreview(checked)
      setResult(null)
    },
    onError: (caught) => {
      // The older single-sheet file has no preview; it imports directly.
      setPreview(null)
      enqueueSnackbar(apiErrorMessage(caught, 'The file could not be checked.'), { variant: 'error' })
    },
    onSettled: () => setProgress(0),
  })

  const importMutation = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
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
      setPreview(null)
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

  const isProcessing = previewMutation.isPending || importMutation.isPending
  const liveTracker = useLiveProgressTracker({
    isPending: isProcessing,
    progress,
    fileSize: file?.size ?? 0,
  })


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

            {/* No shop picker. The report names its own branches in
                INVENTLOCATIONID and each one is matched to the shop that
                already carries that code, so asking the operator to say it
                again only creates a way to get it wrong. */}
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr auto auto' }, alignItems: 'start' }}>
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
                      setPreview(null)
                    }}
                  />
                </Button>

                {file ? (
                  <Typography variant="caption" sx={{ display: 'block', mt: 0.75 }}>
                    {(file.size / 1024).toFixed(0)} KB selected
                  </Typography>
                ) : null}
              </Box>

              {/* Two steps, deliberately. A multi-sheet Stock Report is checked
                  before replacement. Single-sheet files for a single shop import directly once a shop is selected. */}
              <Button
                variant={preview ? 'outlined' : 'contained'}
                disabled={!file || previewMutation.isPending || importMutation.isPending}
                onClick={() => previewMutation.mutate()}
                sx={{ minWidth: previewMutation.isPending ? 240 : 130 }}
              >
                {previewMutation.isPending
                  ? `Checking… (${liveTracker.buttonTimeText})`
                  : preview
                    ? 'Check again'
                    : 'Check file'}
              </Button>

              <Button
                variant="contained"
                color="error"
                disabled={!file || !preview || preview.valid_rows === 0 || importMutation.isPending}
                onClick={() => setConfirmOpen(true)}
                sx={{ minWidth: importMutation.isPending ? 240 : 170 }}
              >
                {importMutation.isPending
                  ? `Replacing… (${liveTracker.buttonTimeText})`
                  : 'Replace stock'}
              </Button>
            </Box>

            {!preview && file && !previewMutation.isPending ? (
              <Typography variant="caption" sx={{ display: 'block', mt: 1.5 }}>
                Check the file first. Nothing is changed until you confirm the replacement.
              </Typography>
            ) : null}

            {preview ? <ImportPreviewPanel preview={preview} /> : null}

            {previewMutation.isPending ? (
              <Box sx={{ mt: 2.5 }}>
                <LinearProgress variant={progress > 0 && progress < 100 ? 'determinate' : 'indeterminate'} value={progress} />
                <Typography variant="caption" sx={{ mt: 0.75, display: 'block', color: 'text.primary', fontWeight: 500 }}>
                  {liveTracker.progressLineText}
                </Typography>
              </Box>
            ) : null}

            {importMutation.isPending ? (
              <Box sx={{ mt: 2.5 }}>
                <LinearProgress variant={progress > 0 && progress < 100 ? 'determinate' : 'indeterminate'} value={progress} />
                <Typography variant="caption" sx={{ mt: 0.75, display: 'block', color: 'text.primary', fontWeight: 500 }}>
                  {liveTracker.progressLineText}
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
                <SummaryFigure label="Products created" value={result.itemsCreated} />
              </>
            ) : null}
          </Stack>
        </Alert>
      ) : null}

      <Typography variant="h4" sx={{ mb: 1.5 }}>
        Import history
      </Typography>

      <DataTable
        focusable
        focusTitle="Stock Imports"
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

      {/* Confirm the replacement — this is destructive for the shop's stock.
          The shops are named outright rather than described, so nobody
          confirms a replacement without seeing which branches it lands on. */}
      <ConfirmDialog
        open={confirmOpen}
        title={
          preview
            ? `Replace stock for ${preview.shops.length} shop${preview.shops.length === 1 ? '' : 's'}?`
            : 'Replace stock for every shop in the file?'
        }
        message={
          preview
            ? `The stock currently held by ${preview.shops
                .map((shop) => shop.shop_code ?? shop.ax_location_id ?? 'this shop')
                .join(', ')} will be deleted and replaced with ${formatNumber(
                preview.valid_rows,
              )} row(s) from this file. Shops the file does not mention are left untouched. This cannot be undone.`
            : 'Importing will remove the stock currently held by every shop the file covers and replace it with the contents of the file. Shops the file does not mention are left untouched. This cannot be undone.'
        }
        confirmLabel="Replace stock"
        severity="warning"
        busy={importMutation.isPending}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => importMutation.mutate()}
        detail={
          <Stack spacing={1}>
            {preview
              ? preview.shops.map((shop) => (
                  <Box
                    key={shop.shop_id}
                    sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, fontSize: '0.8125rem' }}
                  >
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {shop.shop_code ?? shop.ax_location_id}
                    </Typography>
                    <Typography variant="caption">
                      {formatNumber(shop.existing_records)} existing → {formatNumber(shop.incoming_records)} new
                    </Typography>
                  </Box>
                ))
              : null}
            <Alert severity="info" sx={{ py: 0.5 }}>
              If the file cannot be processed, nothing is changed and the existing stock stays exactly as it is.
            </Alert>
          </Stack>
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

/**
 * What the chosen file would do, shown before it is allowed to do it.
 *
 * The replacement impact is the point of this panel: for every branch the file
 * covers, what that branch holds now and what would take its place. Everything
 * else on it is there to answer "is this the right file?" without importing it
 * to find out.
 */
export function ImportPreviewPanel({ preview }: { preview: StockPreview }) {
  const clean = preview.invalid_rows === 0 && preview.unmatched_locations.length === 0

  return (
    <Box sx={{ mt: 3, pt: 2.5, borderTop: 1, borderColor: 'divider' }}>
      <Stack direction="row" sx={{ alignItems: 'center', gap: 1, mb: 1.5 }}>
        <Typography variant="subtitle2">Checked — nothing has been changed yet</Typography>
        <Chip
          size="small"
          label={clean ? 'No issues found' : `${preview.invalid_rows + preview.unmatched_locations.length} issue(s)`}
          color={clean ? 'success' : 'warning'}
          variant="outlined"
        />
      </Stack>

      <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', rowGap: 1.5, mb: 2.5 }}>
        <SummaryFigure label="Total rows" value={preview.total_rows} />
        <SummaryFigure label="Valid rows" value={preview.valid_rows} tone="success" />
        <SummaryFigure
          label="Invalid rows"
          value={preview.invalid_rows}
          tone={preview.invalid_rows ? 'error' : undefined}
        />
        <SummaryFigure label="Locations detected" value={preview.locations_detected} />
        <SummaryFigure label="Products matched" value={preview.items_matched} />
        <SummaryFigure
          label="Products without a GTIN"
          value={preview.gtin_missing}
          tone={preview.gtin_missing ? 'error' : undefined}
        />
      </Stack>

      <Typography variant="subtitle2" sx={{ mb: 1 }}>
        Replacement impact
      </Typography>

      <Stack divider={<Divider flexItem />} sx={{ mb: preview.unmatched_locations.length ? 2.5 : 0 }}>
        {preview.shops.map((shop) => (
          <Box
            key={shop.shop_id}
            sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 2, py: 1.25 }}
          >
            <Box sx={{ minWidth: 0 }}>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {shop.shop_code ?? shop.ax_location_id}
                {shop.shop_name ? ` · ${shop.shop_name}` : ''}
              </Typography>
              {/* Says which code the row was matched on, so a shop mapped
                  explicitly and one matched on its own code are told apart. */}
              <Typography variant="caption">
                {shop.ax_location_id
                  ? `Warehouse code ${shop.ax_location_id}`
                  : `Matched on shop code ${shop.shop_code ?? '—'}`}
              </Typography>
            </Box>

            <Stack direction="row" spacing={2.5} sx={{ alignItems: 'center', flexShrink: 0 }}>
              <Box sx={{ textAlign: 'right' }}>
                <Typography variant="caption" sx={{ display: 'block' }}>
                  Existing
                </Typography>
                <Typography variant="body2">{formatNumber(shop.existing_records)}</Typography>
              </Box>
              <Box sx={{ textAlign: 'right' }}>
                <Typography variant="caption" sx={{ display: 'block' }}>
                  New
                </Typography>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {formatNumber(shop.incoming_records)}
                </Typography>
              </Box>
              {/* Said plainly, because it is destructive. */}
              <Chip size="small" color="error" variant="outlined" label="Replace" sx={{ fontWeight: 600 }} />
            </Stack>
          </Box>
        ))}
      </Stack>

      {preview.unmatched_locations.length ? (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <AlertTitle>Unmatched locations</AlertTitle>
          These warehouse codes appear in the file but no shop is linked to them. Their rows will not be imported and
          will not be assigned to another shop.
          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, mt: 1 }}>
            {preview.unmatched_locations.map((location) => (
              <Chip
                key={location.ax_location_id}
                size="small"
                color="warning"
                variant="outlined"
                label={`${location.ax_location_id} — ${formatNumber(location.rows)} row(s)`}
              />
            ))}
          </Stack>
        </Alert>
      ) : null}

      {preview.gtin_duplicates.length ? (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <AlertTitle>Shared GTINs</AlertTitle>
          A GTIN that more than one product answers to cannot be resolved to a single stock line when it is scanned.
          <Stack sx={{ mt: 1, gap: 0.5 }}>
            {preview.gtin_duplicates.slice(0, 5).map((duplicate) => (
              <Typography key={duplicate.gtin} variant="caption">
                {duplicate.gtin} → {duplicate.product_codes.join(', ')}
              </Typography>
            ))}
          </Stack>
        </Alert>
      ) : null}

      {preview.sample_errors.length ? (
        <Box>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>
            Why rows were rejected
          </Typography>
          <Stack divider={<Divider flexItem />} sx={{ maxHeight: 220, overflowY: 'auto' }}>
            {preview.sample_errors.map((row) => (
              <Box key={`${row.row_number}-${row.column_name}`} sx={{ py: 0.75 }}>
                <Typography variant="caption" sx={{ display: 'block', fontWeight: 600 }}>
                  Row {row.row_number} · {row.column_name}
                </Typography>
                <Typography variant="caption" sx={{ display: 'block', color: 'error.main' }}>
                  {row.error_message}
                </Typography>
              </Box>
            ))}
          </Stack>
          {preview.invalid_rows > preview.sample_errors.length ? (
            <Typography variant="caption" sx={{ display: 'block', mt: 1 }}>
              Showing the first {preview.sample_errors.length} of {formatNumber(preview.invalid_rows)} rejected rows.
            </Typography>
          ) : null}
        </Box>
      ) : null}
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

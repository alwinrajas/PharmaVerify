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
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded'
import WarningAmberRoundedIcon from '@mui/icons-material/WarningAmberRounded'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ConfirmDialog } from '@/components/dialogs'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { useDeviceOptions } from '@/hooks/useOptions'
import { apiClient, apiErrorMessage } from '@/services/apiClient'
import { formatNumber } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'

/**
 * Reading a handheld's Excel export into PharmaVerify.
 *
 * The device has no network path, so a completed count arrives as a file. The
 * kind — audit or stock take — is decided from the file's own headings, not
 * from anything chosen here, so an audit cannot be filed as a take by mistake.
 *
 * The shape is the one the Stock Import screen already established: choose,
 * check, see the impact, confirm. Checking writes nothing, so it can be run as
 * often as the operator likes before committing.
 */

export interface HhtImportPreview {
  kind: 'audit' | 'stock_take'
  file_name: string
  reference: string
  audit_number?: number
  take_number?: number
  audit_date?: string
  take_date?: string
  shop: { shop_id: number; shop_code: string | null; shop_name: string | null; ax_location_id: string | null }
  device: { device_id: number; device_code: string } | null
  total_rows: number
  valid_rows: number
  invalid_rows: number
  unmatched_locations: string[]
  items_matched: number
  items_unmatched: number
  matched_on: { gtin: number; product_code: number; barcode: number; none: number }
  lines_with_loose: number
  system_qty_disagreements: number
  variance_disagreements: number
  sample_errors: Array<{ row_number: number; column_name: string; column_value: string | null; error_message: string }>
  action: 'create' | 'conflict' | 'duplicate_ignored'
  conflict: {
    audit_id?: number
    audit_ref?: string
    stock_take_session_id?: number
    take_ref?: string
    source: string
  } | null
}

interface ImportOutcome {
  kind: string
  reference: string
  imported: number
  rejected: number
  duplicate: boolean
  message: string
}

export function HhtImportPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const fileInput = useRef<HTMLInputElement>(null)
  const { data: devices } = useDeviceOptions()

  const [file, setFile] = useState<File | null>(null)
  const [deviceId, setDeviceId] = useState('')
  const [preview, setPreview] = useState<HhtImportPreview | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [progress, setProgress] = useState(0)
  const [result, setResult] = useState<ImportOutcome | null>(null)

  function reset() {
    setPreview(null)
    setResult(null)
  }

  const previewMutation = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
      payload.append('file', file as File)
      if (deviceId) payload.append('device_id', deviceId)

      const response = await apiClient.post<{ data: HhtImportPreview }>('/hht/imports/preview', payload, {
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
      setPreview(null)
      enqueueSnackbar(apiErrorMessage(caught, 'The export could not be checked.'), { variant: 'error' })
    },
    onSettled: () => setProgress(0),
  })

  const importMutation = useMutation({
    mutationFn: async () => {
      const payload = new FormData()
      payload.append('file', file as File)
      if (deviceId) payload.append('device_id', deviceId)

      const response = await apiClient.post<{
        message?: string
        meta?: { kind?: string; duplicate?: boolean; summary?: { reference?: string; imported?: number; rejected?: number } }
      }>('/hht/imports', payload)

      return response.data
    },
    onSuccess: (response) => {
      const summary = response.meta?.summary

      setResult({
        kind: response.meta?.kind ?? 'audit',
        reference: summary?.reference ?? '',
        imported: summary?.imported ?? 0,
        rejected: summary?.rejected ?? 0,
        duplicate: Boolean(response.meta?.duplicate),
        message: response.message ?? 'Import completed.',
      })

      enqueueSnackbar(response.message ?? 'Import completed.', {
        variant: response.meta?.duplicate ? 'warning' : 'success',
      })

      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['stock-takes'] })
      void queryClient.invalidateQueries({ queryKey: ['stock-take-sessions'] })
      void queryClient.invalidateQueries({ queryKey: ['variance'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      setFile(null)
      setPreview(null)
      if (fileInput.current) fileInput.current.value = ''
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught, 'The export could not be imported.'), { variant: 'error' }),
    onSettled: () => {
      setProgress(0)
      setConfirmOpen(false)
    },
  })

  const isAudit = preview?.kind === 'audit'
  const needsDevice = isAudit && deviceId === ''
  const blocked = preview?.action === 'conflict'

  if (!can(PERMISSIONS.hhtImport)) {
    return (
      <Box>
        <PageHeader
          title="Import HHT Export"
          description="Read a completed count from a handheld's Excel export."
          crumbs={[{ label: 'Stock Verification' }, { label: 'Import HHT Export' }]}
        />
        <Alert severity="info">You do not have permission to import handheld exports.</Alert>
      </Box>
    )
  }

  return (
    <Box>
      <PageHeader
        title="Import HHT Export"
        description="Read a completed count from a handheld's Excel export. The file decides whether it is an audit or a stock take."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Import HHT Export' }]}
      />

      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ p: { xs: 2, sm: 3 } }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
            Choose the export
          </Typography>
          <Typography variant="caption" sx={{ display: 'block', mb: 2.5 }}>
            Supported formats: .xls and .xlsx, up to 100 MB. The device writes one file per audit or stock take.
          </Typography>

          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 260px auto auto' }, alignItems: 'start' }}>
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
                    reset()
                  }}
                />
              </Button>
              {file ? (
                <Typography variant="caption" sx={{ display: 'block', mt: 0.75 }}>
                  {(file.size / 1024).toFixed(0)} KB selected
                </Typography>
              ) : null}
            </Box>

            <TextField
              select
              label="Handheld device"
              value={deviceId}
              onChange={(event) => setDeviceId(event.target.value)}
              size="small"
              fullWidth
              // The export names the shop but not the device, and an audit is
              // identified by shop + device + number. A stock take is not.
              slotProps={{ select: { native: true }, inputLabel: { shrink: true } }}
              helperText={
                preview && !isAudit
                  ? 'Not needed for a stock take.'
                  : 'Required for an audit — the export does not name a device.'
              }
            >
              <option value="">Select a device…</option>
              {(devices ?? []).map((device) => (
                <option key={device.id} value={device.id}>
                  {device.label}
                </option>
              ))}
            </TextField>

            <Button
              variant={preview ? 'outlined' : 'contained'}
              disabled={!file || previewMutation.isPending || importMutation.isPending}
              onClick={() => previewMutation.mutate()}
              sx={{ minWidth: 130 }}
            >
              {previewMutation.isPending ? 'Checking…' : preview ? 'Check again' : 'Check file'}
            </Button>

            <Button
              variant="contained"
              disabled={
                !file || !preview || preview.valid_rows === 0 || blocked || needsDevice || importMutation.isPending
              }
              onClick={() => setConfirmOpen(true)}
              sx={{ minWidth: 150 }}
            >
              {importMutation.isPending ? 'Importing…' : 'Import count'}
            </Button>
          </Box>

          {!preview && file && !previewMutation.isPending ? (
            <Typography variant="caption" sx={{ display: 'block', mt: 1.5 }}>
              Check the file first. Nothing is recorded until you confirm.
            </Typography>
          ) : null}

          {previewMutation.isPending || importMutation.isPending ? (
            <Box sx={{ mt: 2.5 }}>
              <LinearProgress variant={progress > 0 && progress < 100 ? 'determinate' : 'indeterminate'} value={progress} />
              <Typography variant="caption" sx={{ mt: 0.75, display: 'block' }}>
                {progress < 100
                  ? `Uploading… ${progress}%`
                  : previewMutation.isPending
                    ? 'Reading the export and checking every row. Nothing has been changed.'
                    : 'Recording the count.'}
              </Typography>
            </Box>
          ) : null}

          {preview ? <HhtPreviewPanel preview={preview} needsDevice={needsDevice} /> : null}
        </CardContent>
      </Card>

      {result ? (
        <Alert
          severity={result.duplicate ? 'warning' : result.rejected > 0 ? 'warning' : 'success'}
          icon={result.duplicate || result.rejected > 0 ? <WarningAmberRoundedIcon /> : <CheckCircleRoundedIcon />}
          sx={{ mb: 3 }}
          action={
            !result.duplicate ? (
              <Button
                color="inherit"
                size="small"
                onClick={() => navigate(result.kind === 'audit' ? '/audits' : '/stock-take')}
              >
                {result.kind === 'audit' ? 'View audits' : 'View stock takes'}
              </Button>
            ) : null
          }
        >
          <AlertTitle>{result.reference || 'Import complete'}</AlertTitle>
          {result.message}
        </Alert>
      ) : null}

      <ConfirmDialog
        open={confirmOpen}
        title={isAudit ? 'Record this audit?' : 'Record this stock take?'}
        message={
          preview
            ? `${preview.reference} will be recorded against ${preview.shop.shop_code ?? preview.shop.ax_location_id}${
                isAudit && preview.device ? ` on device ${preview.device.device_code}` : ''
              }, with ${formatNumber(preview.valid_rows)} counted line(s). Existing stock is not changed — posting a variance to stock is a separate step.`
            : ''
        }
        confirmLabel={isAudit ? 'Record audit' : 'Record stock take'}
        severity="info"
        busy={importMutation.isPending}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => importMutation.mutate()}
        detail={
          <Alert severity="info" sx={{ py: 0.5 }}>
            If the file cannot be processed, nothing is recorded. Uploading the same file again is a no-op.
          </Alert>
        }
      />
    </Box>
  )
}

/**
 * What the chosen export would do, shown before it is allowed to do it.
 */
export function HhtPreviewPanel({ preview, needsDevice }: { preview: HhtImportPreview; needsDevice?: boolean }) {
  const isAudit = preview.kind === 'audit'
  const clean = preview.invalid_rows === 0 && preview.unmatched_locations.length === 0

  return (
    <Box sx={{ mt: 3, pt: 2.5, borderTop: 1, borderColor: 'divider' }}>
      <Stack direction="row" sx={{ alignItems: 'center', gap: 1, mb: 1.5, flexWrap: 'wrap' }}>
        <Typography variant="subtitle2">Checked — nothing has been recorded yet</Typography>
        <Chip size="small" label={isAudit ? 'Stock Audit' : 'Stock Take'} color="primary" variant="outlined" />
        <Chip
          size="small"
          label={clean ? 'No issues found' : `${preview.invalid_rows + preview.unmatched_locations.length} issue(s)`}
          color={clean ? 'success' : 'warning'}
          variant="outlined"
        />
      </Stack>

      <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', rowGap: 1.5, mb: 2.5 }}>
        <Figure label="Reference" text={preview.reference} />
        <Figure label="Shop" text={preview.shop.shop_code ?? preview.shop.ax_location_id ?? '—'} />
        <Figure label="Total rows" value={preview.total_rows} />
        <Figure label="Valid rows" value={preview.valid_rows} tone="success" />
        <Figure label="Invalid rows" value={preview.invalid_rows} tone={preview.invalid_rows ? 'error' : undefined} />
        <Figure label="Products matched" value={preview.items_matched} />
        <Figure
          label="Unmatched products"
          value={preview.items_unmatched}
          tone={preview.items_unmatched ? 'error' : undefined}
        />
        <Figure label="Lines with loose stock" value={preview.lines_with_loose} />
      </Stack>

      <Typography variant="subtitle2" sx={{ mb: 1 }}>
        How each product was identified
      </Typography>
      <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, mb: 2.5 }}>
        <Chip size="small" variant="outlined" color="primary" label={`GTIN — ${formatNumber(preview.matched_on.gtin)}`} />
        <Chip size="small" variant="outlined" label={`Item code — ${formatNumber(preview.matched_on.product_code)}`} />
        <Chip size="small" variant="outlined" label={`7-digit barcode — ${formatNumber(preview.matched_on.barcode)}`} />
        <Chip
          size="small"
          variant="outlined"
          color={preview.matched_on.none ? 'warning' : 'default'}
          label={`No match — ${formatNumber(preview.matched_on.none)}`}
        />
      </Stack>

      {needsDevice ? (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <AlertTitle>Choose the handheld</AlertTitle>
          The export names the shop but not the device, and an audit is identified by its shop, its device and its
          number. Select the handheld this count was taken on before importing.
        </Alert>
      ) : null}

      {preview.action === 'duplicate_ignored' ? (
        <Alert severity="info" sx={{ mb: 2 }}>
          <AlertTitle>Already imported</AlertTitle>
          {preview.reference} has already been recorded from this exact file. Importing again changes nothing.
        </Alert>
      ) : null}

      {preview.action === 'conflict' ? (
        <Alert severity="error" sx={{ mb: 2 }}>
          <AlertTitle>Already recorded, with different contents</AlertTitle>
          {preview.reference} exists already (recorded from {preview.conflict?.source === 'excel' ? 'an import' : 'the handheld endpoint'})
          and this file does not match it. Replacing a recorded count is a separate decision, so this import is
          blocked.
        </Alert>
      ) : null}

      {preview.unmatched_locations.length ? (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <AlertTitle>Unmatched locations</AlertTitle>
          These warehouse codes appear in the file but no shop is linked to them. Their rows will not be imported and
          will not be assigned to another shop.
          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, mt: 1 }}>
            {preview.unmatched_locations.map((code) => (
              <Chip key={code} size="small" color="warning" variant="outlined" label={code} />
            ))}
          </Stack>
        </Alert>
      ) : null}

      {isAudit && preview.system_qty_disagreements > 0 ? (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <AlertTitle>System quantity differs from the handheld's copy</AlertTitle>
          {formatNumber(preview.system_qty_disagreements)} line(s) carry a system quantity that disagrees with what
          PharmaVerify holds. The handheld works from its own copy of the ERP data, taken at its own moment.
          PharmaVerify's figure is used for the variance; the file's is kept alongside it for reconciliation.
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

function Figure({
  label,
  value,
  text,
  tone,
}: {
  label: string
  value?: number
  text?: string
  tone?: 'success' | 'error'
}) {
  const colour = tone === 'success' ? 'success.main' : tone === 'error' ? 'error.main' : 'text.primary'

  return (
    <Box>
      <Typography variant="caption" sx={{ display: 'block' }}>
        {label}
      </Typography>
      <Typography
        sx={{
          fontSize: text ? '0.9375rem' : '1.125rem',
          fontWeight: 700,
          color: colour,
          fontFamily: text ? 'ui-monospace, monospace' : undefined,
          pt: text ? 0.35 : 0,
        }}
      >
        {text ?? formatNumber(value ?? 0)}
      </Typography>
    </Box>
  )
}

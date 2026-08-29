import {
  Alert,
  AlertTitle,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  IconButton,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import SendRoundedIcon from '@mui/icons-material/SendRounded'
import ReplayRoundedIcon from '@mui/icons-material/ReplayRounded'
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import QrCodeScannerRoundedIcon from '@mui/icons-material/QrCodeScannerRounded'
import OpenInNewRoundedIcon from '@mui/icons-material/OpenInNewRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import dayjs from 'dayjs'
import { useSnackbar } from 'notistack'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { EmptyState } from '@/components/states'
import { useDeviceOptions, useShopOptions } from '@/hooks/useOptions'
import { apiErrorMessage, get, post } from '@/services/apiClient'
import { formatQuantity } from '@/utils/format'
import type { ItemStock } from '@/types'
import { neutral, semantic } from '@/theme'

interface ScanLine {
  key: string
  barcode: string | null
  /** The code printed on the carton, and what a handheld actually scans. */
  gtin: string | null
  product_code: string | null
  description: string
  batch: string
  expiry: string | null
  uom: string
  shelf_location: string | null
  system_qty: number
  physical_quantity: number
}

interface SubmissionResult {
  submission_id: number
  audit_id: number
  audit_number: number
  status: string
  item_count: number
}

/**
 * Stands in for the Android handheld so the complete flow can be shown without
 * a physical device.
 *
 * It behaves the way the device does: the operator picks a shop, a device and
 * an audit number, builds up a count locally, and only when they press
 * Share / Submit does anything reach the server — in one call.
 */
export function HhtSimulatorPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()

  const { data: shops } = useShopOptions()
  const [shopId, setShopId] = useState('')
  const { data: devices } = useDeviceOptions(shopId || null)

  const [deviceId, setDeviceId] = useState('')
  const [auditNumber, setAuditNumber] = useState('1')
  const [auditDate, setAuditDate] = useState(dayjs().format('YYYY-MM-DD'))
  const [countedBy, setCountedBy] = useState('')
  const [submissionUid, setSubmissionUid] = useState('')

  const [lines, setLines] = useState<ScanLine[]>([])
  const [lastResult, setLastResult] = useState<SubmissionResult | null>(null)

  const [scanCode, setScanCode] = useState('')
  const [scanQty, setScanQty] = useState('')
  const [scanNote, setScanNote] = useState<string | null>(null)
  const [unknownBarcode, setUnknownBarcode] = useState('')
  const [unknownDescription, setUnknownDescription] = useState('')
  const [unknownQty, setUnknownQty] = useState('')

  const { data: stock } = useQuery({
    queryKey: ['simulator-stock', shopId],
    queryFn: async () => (await get<ItemStock[]>('/item-stocks', { shop_id: shopId, per_page: 200 })).data,
    enabled: Boolean(shopId),
  })

  const selectedShop = (shops ?? []).find((shop) => String(shop.id) === shopId)
  const selectedDevice = (devices ?? []).find((device) => String(device.id) === deviceId)

  const generatedUid = useMemo(() => {
    if (submissionUid.trim() !== '') return submissionUid.trim()
    if (!selectedShop || !selectedDevice) return ''

    return `SUB-${selectedShop.code}-${selectedDevice.code}-A${auditNumber}`
  }, [submissionUid, selectedShop, selectedDevice, auditNumber])

  const submitMutation = useMutation({
    mutationFn: async () =>
      post<SubmissionResult>('/hht/submissions', {
        submission_uid: generatedUid,
        shop_id: Number(shopId),
        device_id: Number(deviceId),
        audit_number: Number(auditNumber),
        audit_date: auditDate,
        hht_user: countedBy || undefined,
        app_version: '1.4.2',
        items: lines.map((line) => ({
          gtin: line.gtin,
          barcode: line.barcode,
          product_code: line.product_code,
          description: line.description,
          physical_quantity: line.physical_quantity,
          batch: line.batch || undefined,
          expiry: line.expiry || undefined,
          uom: line.uom,
          shelf_location: line.shelf_location || undefined,
        })),
      }),
    onSuccess: (response) => {
      setLastResult(response.data)
      enqueueSnackbar(response.message ?? 'Submission sent.', {
        variant: response.data.status === 'duplicate_ignored' ? 'warning' : 'success',
      })

      void queryClient.invalidateQueries({ queryKey: ['hht-submissions'] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  /** Loads the shop's stock as a starting count, the way a shelf sweep would. */
  function loadShelf(sample: number) {
    const source = (stock ?? []).slice(0, sample)

    setLines(
      source.map((row) => ({
        key: `stock-${row.id}`,
        barcode: row.barcode,
        gtin: row.gtin,
        product_code: row.product_code,
        description: row.description,
        batch: row.batch,
        expiry: row.expiry_date,
        uom: row.uom,
        shelf_location: row.shelf_location,
        system_qty: Number(row.system_qty),
        physical_quantity: Number(row.system_qty),
      })),
    )
    setLastResult(null)
  }

  /** Introduces a realistic mix of short, excess and matching counts. */
  function scatterVariance() {
    setLines((current) =>
      current.map((line, index) => {
        const delta = [0, -5, 3, 0, -2, 6][index % 6]

        return { ...line, physical_quantity: Math.max(0, line.system_qty + delta) }
      }),
    )
  }

  /**
   * A scan, the way a handheld makes one.
   *
   * The GTIN alone is entered; the item, its batch and its expiry come back
   * from the shop's stock rather than being keyed in. Where the shop holds the
   * product in more than one batch the code cannot decide between them, so the
   * scan is reported as ambiguous instead of one being chosen silently.
   */
  const scanMutation = useMutation({
    mutationFn: async () =>
      (
        await get<ItemStock[]>('/item-stocks/lookup', {
          code: scanCode.trim(),
          shop_id: shopId,
        })
      ),
    onSuccess: (response) => {
      const matches = response.data ?? []

      if (matches.length === 0) {
        setScanNote(`Nothing in this shop answers to ${scanCode.trim()}.`)

        return
      }

      if (matches.length > 1) {
        setScanNote(
          `${scanCode.trim()} is held in ${matches.length} batches. Batch and expiry cannot be filled in automatically — pick the line from the shelf list instead.`,
        )

        return
      }

      const row = matches[0]
      const quantity = scanQty === '' ? Number(row.system_qty) : Number(scanQty)

      setLines((current) => [
        ...current,
        {
          key: `scan-${row.id}-${current.length}`,
          barcode: row.barcode,
          gtin: row.gtin,
          product_code: row.product_code,
          description: row.description,
          batch: row.batch,
          expiry: row.expiry_date,
          uom: row.uom,
          shelf_location: row.shelf_location,
          system_qty: Number(row.system_qty),
          physical_quantity: quantity,
        },
      ])

      setScanNote(
        `${row.product_code} · batch ${row.batch || '—'}${row.expiry_date ? ` · expires ${row.expiry_date}` : ''}`,
      )
      setScanCode('')
      setScanQty('')
    },
    onError: (caught) => setScanNote(apiErrorMessage(caught, 'The code could not be looked up.')),
  })

  function addUnknownItem() {
    if (unknownDescription.trim() === '' || unknownQty === '') return

    setLines((current) => [
      ...current,
      {
        key: `unknown-${Date.now()}`,
        barcode: unknownBarcode || null,
        gtin: null,
        product_code: null,
        description: unknownDescription,
        batch: '',
        expiry: null,
        uom: 'EA',
        shelf_location: null,
        system_qty: 0,
        physical_quantity: Number(unknownQty),
      },
    ])

    setUnknownBarcode('')
    setUnknownDescription('')
    setUnknownQty('')
  }

  const readyToSubmit = shopId !== '' && deviceId !== '' && auditNumber !== '' && lines.length > 0

  return (
    <Box>
      <PageHeader
        title="HHT Simulator"
        description="Sends a completed count to the same endpoint the Android handhelds use, so the flow can be demonstrated without a device."
        crumbs={[{ label: 'Stock Verification' }, { label: 'HHT Submissions', to: '/hht' }, { label: 'Simulator' }]}
      />

      <Alert severity="info" sx={{ mb: 3 }}>
        <AlertTitle>How the real device behaves</AlertTitle>
        Every scan is held in the device's own storage while counting. Nothing reaches the server until the operator
        presses Share / Submit, and then the whole audit is sent in a single call.
      </Alert>

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: '380px 1fr' }, alignItems: 'start' }}>
        {/* Submission context */}
        <Stack spacing={2.5}>
          <Card>
            <CardContent sx={{ p: 2.5 }}>
              <Typography variant="subtitle1" sx={{ mb: 2 }}>
                Submission context
              </Typography>

              <Stack spacing={2}>
                <TextField
                  select
                  label="Shop"
                  size="small"
                  value={shopId}
                  onChange={(event) => {
                    setShopId(event.target.value)
                    setDeviceId('')
                    setLines([])
                    setLastResult(null)
                  }}
                  slotProps={{ select: { native: true } }}
                  fullWidth
                >
                  <option value="">Select a shop…</option>
                  {(shops ?? []).map((shop) => (
                    <option key={shop.id} value={shop.id}>
                      {shop.label}
                    </option>
                  ))}
                </TextField>

                <TextField
                  select
                  label="Device"
                  size="small"
                  value={deviceId}
                  onChange={(event) => setDeviceId(event.target.value)}
                  disabled={!shopId}
                  slotProps={{ select: { native: true } }}
                  fullWidth
                >
                  <option value="">Select a device…</option>
                  {(devices ?? []).map((device) => (
                    <option key={device.id} value={device.id}>
                      {device.label}
                    </option>
                  ))}
                </TextField>

                <Stack direction="row" spacing={2}>
                  <TextField
                    label="Audit Number"
                    type="number"
                    size="small"
                    value={auditNumber}
                    onChange={(event) => setAuditNumber(event.target.value)}
                    slotProps={{ htmlInput: { min: 1 } }}
                    fullWidth
                  />
                  <TextField
                    label="Audit Date"
                    type="date"
                    size="small"
                    value={auditDate}
                    onChange={(event) => setAuditDate(event.target.value)}
                    slotProps={{ inputLabel: { shrink: true } }}
                    fullWidth
                  />
                </Stack>

                <TextField
                  label="Counted By"
                  size="small"
                  value={countedBy}
                  onChange={(event) => setCountedBy(event.target.value)}
                  placeholder="Name of the person counting"
                  fullWidth
                />

                <TextField
                  label="Submission ID"
                  size="small"
                  value={submissionUid}
                  onChange={(event) => setSubmissionUid(event.target.value)}
                  placeholder={generatedUid || 'Generated automatically'}
                  helperText="Re-sending the same ID is treated as a retry, not a new audit."
                  fullWidth
                />
              </Stack>

              {selectedShop && selectedDevice ? (
                <Box sx={{ mt: 2.5, p: 1.75, borderRadius: 2, bgcolor: 'rgba(15,93,76,0.05)' }}>
                  <Typography variant="caption" sx={{ display: 'block', mb: 0.5 }}>
                    Submission identity
                  </Typography>
                  <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
                    {selectedShop.code} · {selectedDevice.code} · Audit {auditNumber}
                  </Typography>
                </Box>
              ) : null}
            </CardContent>
          </Card>

          <Card>
            <CardContent sx={{ p: 2.5 }}>
              <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
                Build the count
              </Typography>
              <Typography variant="caption" sx={{ display: 'block', mb: 2 }}>
                Load the shop's shelf, then adjust the physical quantities.
              </Typography>

              <Stack spacing={1.25}>
                <Button
                  variant="outlined"
                  size="small"
                  disabled={!shopId || !stock?.length}
                  startIcon={<QrCodeScannerRoundedIcon />}
                  onClick={() => loadShelf(8)}
                >
                  Load 8 products
                </Button>
                <Button
                  variant="outlined"
                  size="small"
                  disabled={!shopId || !stock?.length}
                  startIcon={<QrCodeScannerRoundedIcon />}
                  onClick={() => loadShelf(stock?.length ?? 0)}
                >
                  Load full shelf ({stock?.length ?? 0})
                </Button>
                <Button
                  variant="outlined"
                  size="small"
                  disabled={lines.length === 0}
                  startIcon={<ReplayRoundedIcon />}
                  onClick={scatterVariance}
                >
                  Apply a realistic variance spread
                </Button>
              </Stack>

              <Divider sx={{ my: 2.5 }} />

              <Typography variant="subtitle2" sx={{ mb: 0.5 }}>
                Scan a GTIN
              </Typography>
              <Typography variant="caption" sx={{ display: 'block', mb: 1.5 }}>
                The code printed on the carton. The item, its batch and its expiry are looked up from the shop's stock.
              </Typography>

              <Stack direction="row" spacing={1.5}>
                <TextField
                  label="GTIN"
                  size="small"
                  value={scanCode}
                  onChange={(event) => {
                    setScanCode(event.target.value)
                    setScanNote(null)
                  }}
                  onKeyDown={(event) => {
                    // A handheld ends every scan with Enter.
                    if (event.key === 'Enter' && scanCode.trim() !== '' && shopId !== '') {
                      event.preventDefault()
                      scanMutation.mutate()
                    }
                  }}
                  placeholder="08840149636445"
                  fullWidth
                />
                <TextField
                  label="Qty"
                  type="number"
                  size="small"
                  value={scanQty}
                  onChange={(event) => setScanQty(event.target.value)}
                  slotProps={{ htmlInput: { min: 0 } }}
                  sx={{ width: 110, flexShrink: 0 }}
                />
                <Button
                  variant="outlined"
                  size="small"
                  startIcon={<QrCodeScannerRoundedIcon />}
                  onClick={() => scanMutation.mutate()}
                  disabled={scanCode.trim() === '' || shopId === '' || scanMutation.isPending}
                  sx={{ flexShrink: 0 }}
                >
                  {scanMutation.isPending ? 'Looking up…' : 'Scan'}
                </Button>
              </Stack>

              {scanNote ? (
                <Typography variant="caption" sx={{ display: 'block', mt: 1 }}>
                  {scanNote}
                </Typography>
              ) : null}

              <Divider sx={{ my: 2.5 }} />

              <Typography variant="subtitle2" sx={{ mb: 1.5 }}>
                Add an item not in the stock file
              </Typography>
              <Stack spacing={1.5}>
                <TextField
                  label="Barcode"
                  size="small"
                  value={unknownBarcode}
                  onChange={(event) => setUnknownBarcode(event.target.value)}
                  fullWidth
                />
                <TextField
                  label="Product Description"
                  size="small"
                  value={unknownDescription}
                  onChange={(event) => setUnknownDescription(event.target.value)}
                  fullWidth
                />
                <Stack direction="row" spacing={1.5}>
                  <TextField
                    label="Physical Qty"
                    type="number"
                    size="small"
                    value={unknownQty}
                    onChange={(event) => setUnknownQty(event.target.value)}
                    slotProps={{ htmlInput: { min: 0 } }}
                    fullWidth
                  />
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={<AddRoundedIcon />}
                    onClick={addUnknownItem}
                    disabled={unknownDescription.trim() === '' || unknownQty === ''}
                    sx={{ flexShrink: 0 }}
                  >
                    Add
                  </Button>
                </Stack>
              </Stack>
              <Typography variant="caption" sx={{ display: 'block', mt: 1.25 }}>
                These appear on the audit as unknown items and are the ones Stock Take exists for.
              </Typography>
            </CardContent>
          </Card>
        </Stack>

        {/* Count */}
        <Card>
          <CardContent sx={{ p: 0 }}>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              sx={{ p: 2.5, borderBottom: 1, borderColor: 'divider' }}
            >
              <Box>
                <Typography variant="subtitle1">Counted items</Typography>
                <Typography variant="caption">
                  {lines.length} line{lines.length === 1 ? '' : 's'} held on the device
                </Typography>
              </Box>

              <Stack direction="row" spacing={1}>
                <Button size="small" color="inherit" disabled={lines.length === 0} onClick={() => setLines([])}>
                  Clear
                </Button>
                <Button
                  variant="contained"
                  startIcon={<SendRoundedIcon />}
                  disabled={!readyToSubmit || submitMutation.isPending}
                  onClick={() => submitMutation.mutate()}
                >
                  {submitMutation.isPending ? 'Sending…' : 'Share / Submit'}
                </Button>
              </Stack>
            </Stack>

            {lastResult ? (
              <Alert
                severity={lastResult.status === 'duplicate_ignored' ? 'warning' : 'success'}
                sx={{ m: 2.5, mb: 0 }}
                action={
                  <Button
                    color="inherit"
                    size="small"
                    endIcon={<OpenInNewRoundedIcon fontSize="small" />}
                    onClick={() => navigate(`/audits/${lastResult.audit_id}`)}
                  >
                    Open audit
                  </Button>
                }
              >
                <AlertTitle>
                  {lastResult.status === 'duplicate_ignored'
                    ? 'Duplicate submission ignored'
                    : `Audit ${lastResult.audit_number} created`}
                </AlertTitle>
                {lastResult.status === 'duplicate_ignored'
                  ? 'This submission had already been received. The existing audit was returned and no duplicate was created.'
                  : `${lastResult.item_count} counted line(s) were stored against the audit.`}
              </Alert>
            ) : null}

            {lines.length === 0 ? (
              <EmptyState
                title="Nothing counted yet"
                description="Choose a shop and load its shelf to build a count."
                icon={<QrCodeScannerRoundedIcon />}
              />
            ) : (
              <Box sx={{ maxHeight: '62vh', overflowY: 'auto' }}>
                <Stack divider={<Divider flexItem />}>
                  {lines.map((line, index) => {
                    const variance = line.physical_quantity - line.system_qty

                    return (
                      <Stack
                        key={line.key}
                        direction="row"
                        spacing={2}
                        alignItems="center"
                        sx={{ px: 2.5, py: 1.5 }}
                      >
                        <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                          <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                            {line.description}
                          </Typography>
                          <Typography variant="caption" noWrap sx={{ display: 'block' }}>
                            {[line.product_code ?? 'Unknown product', line.barcode, line.batch && `Batch ${line.batch}`]
                              .filter(Boolean)
                              .join(' · ')}
                          </Typography>
                        </Box>

                        <Box sx={{ textAlign: 'right', width: 78, flexShrink: 0 }}>
                          <Typography variant="caption" sx={{ display: 'block' }}>
                            System
                          </Typography>
                          <Typography variant="body2">{formatQuantity(line.system_qty)}</Typography>
                        </Box>

                        <TextField
                          size="small"
                          type="number"
                          label="Physical"
                          value={line.physical_quantity}
                          onChange={(event) => {
                            const value = Math.max(0, Number(event.target.value))
                            setLines((current) =>
                              current.map((entry, entryIndex) =>
                                entryIndex === index ? { ...entry, physical_quantity: value } : entry,
                              ),
                            )
                          }}
                          slotProps={{ htmlInput: { min: 0, style: { textAlign: 'right' } } }}
                          sx={{ width: 108, flexShrink: 0 }}
                        />

                        <Chip
                          size="small"
                          label={variance === 0 ? 'Match' : `${variance > 0 ? '+' : ''}${formatQuantity(variance)}`}
                          sx={{
                            width: 76,
                            flexShrink: 0,
                            color: variance === 0 ? 'text.secondary' : variance > 0 ? semantic.success.fg : semantic.error.fg,
                            bgcolor: variance === 0 ? neutral[100] : variance > 0 ? semantic.success.bg : semantic.error.bg,
                          }}
                        />

                        <Tooltip title="Remove line">
                          <IconButton
                            size="small"
                            aria-label="Remove this line from the submission"
                            onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                          >
                            <DeleteOutlineRoundedIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    )
                  })}
                </Stack>
              </Box>
            )}
          </CardContent>
        </Card>
      </Box>
    </Box>
  )
}

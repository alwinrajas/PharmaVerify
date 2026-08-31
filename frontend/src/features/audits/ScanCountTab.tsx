import { Alert, AlertTitle, Box, Button, Card, CardContent, CircularProgress, Stack, TextField, Typography } from '@mui/material'
import DoneAllRoundedIcon from '@mui/icons-material/DoneAllRounded'
import QrCodeScannerRoundedIcon from '@mui/icons-material/QrCodeScannerRounded'
import SearchRoundedIcon from '@mui/icons-material/SearchRounded'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BatchPicker } from './BatchPicker'
import { CountPanel } from './CountPanel'
import { CountedLinesTable } from './CountedLinesTable'
import { ConfirmDialog } from '@/components/dialogs'
import { EmptyState, ErrorState } from '@/components/states'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useShopOptions } from '@/hooks/useOptions'
import { apiErrorMessage, destroy, get, post } from '@/services/apiClient'
import { formatNumber } from '@/utils/format'
import type { Audit, AuditLine, AuditLookupMatch, AuditLookupResult } from '@/types'

/** A small label-over-value pair, for the facts on the audit strip. */
function Stat({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" sx={{ display: 'block', mb: 0.25 }}>
        {label}
      </Typography>
      <Typography
        variant="body2"
        noWrap
        sx={{ fontWeight: strong ? 700 : 600, color: strong ? 'primary.main' : 'text.primary' }}
      >
        {value}
      </Typography>
    </Box>
  )
}

/**
 * Counting a shop live from the browser.
 *
 * Mirrors the handheld's own shape — pick a shop, open an audit, scan a
 * product, confirm a quantity, repeat — but every step is one request instead
 * of something accumulated on a device and sent in a single batch at the end.
 * That is also why the audit itself is kept as plain state populated from
 * each mutation's response rather than a query: there is no GET for "the
 * count in progress", only the sequence of POSTs that build it.
 */
export function ScanCountTab() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()

  const {
    data: shops,
    isLoading: shopsLoading,
    isError: shopsIsError,
    error: shopsError,
    refetch: refetchShops,
  } = useShopOptions()

  const [shopId, setShopId] = useState('')
  const [audit, setAudit] = useState<Audit | null>(null)
  const [resumedNotice, setResumedNotice] = useState<string | null>(null)
  const [completedSummary, setCompletedSummary] = useState<{
    audit_ref: string
    item_count: number
    variance_count: number
  } | null>(null)

  const [scanValue, setScanValue] = useState('')
  const [matches, setMatches] = useState<AuditLookupMatch[]>([])
  const [selectedMatch, setSelectedMatch] = useState<AuditLookupMatch | null>(null)
  const [lookupFeedback, setLookupFeedback] = useState<{ severity: 'warning' | 'error'; message: string } | null>(
    null,
  )

  const [physicalQty, setPhysicalQty] = useState('')
  const [looseQty, setLooseQty] = useState('')
  const [countError, setCountError] = useState<string | null>(null)
  const [saveNotice, setSaveNotice] = useState<{ heading: string; detail: string } | null>(null)

  const [deleteTarget, setDeleteTarget] = useState<AuditLine | null>(null)
  const [completeConfirmOpen, setCompleteConfirmOpen] = useState(false)

  // Bumped whenever the scanner should reclaim focus. A plain ref-focus call
  // from inside a success handler can land a tick too early, while the field
  // is still disabled from the render that is about to be replaced; routing
  // it through an effect guarantees the focus happens after that render
  // commits, once the field is actually focusable.
  const [focusToken, setFocusToken] = useState(0)
  const scannerRef = useRef<HTMLInputElement>(null)
  // Set immediately before a count is posted, so the success handler can
  // report "Updated" without depending on when the lines query happens to
  // have refetched.
  const pendingWasUpdateRef = useRef(false)

  useEffect(() => {
    if (focusToken === 0) return
    scannerRef.current?.focus()
  }, [focusToken])

  function bumpScannerFocus() {
    setFocusToken((token) => token + 1)
  }

  const selectedShop = (shops ?? []).find((shop) => String(shop.id) === shopId)

  const linesQuery = useQuery({
    queryKey: ['workspace-audit-lines', audit?.id],
    queryFn: async () => get<AuditLine[]>(`/audits/${audit?.id}/lines`, { per_page: 200, sort_by: 'id', sort_dir: 'desc' }),
    enabled: Boolean(audit?.id),
  })
  const countedLines = linesQuery.data?.data ?? []

  function resetScanState() {
    setScanValue('')
    setMatches([])
    setSelectedMatch(null)
    setLookupFeedback(null)
    setPhysicalQty('')
    setLooseQty('')
    setCountError(null)
    setSaveNotice(null)
  }

  function resetWorkspace() {
    setShopId('')
    setAudit(null)
    setResumedNotice(null)
    setDeleteTarget(null)
    setCompleteConfirmOpen(false)
    resetScanState()
  }

  function handleShopChange(value: string) {
    setShopId(value)
    setAudit(null)
    setResumedNotice(null)
    setCompletedSummary(null)
    resetScanState()
  }

  function openCountPanel(match: AuditLookupMatch) {
    setMatches([])
    setSelectedMatch(match)
    setPhysicalQty('')
    setLooseQty('')
    setCountError(null)
  }

  function cancelCountPanel() {
    setSelectedMatch(null)
    setPhysicalQty('')
    setLooseQty('')
    setCountError(null)
    bumpScannerFocus()
  }

  const startMutation = useMutation({
    mutationFn: async () => post<Audit>('/audits/start', { shop_id: Number(shopId) }),
    onSuccess: (response) => {
      const opened = response.data
      const resumed = opened.item_count > 0

      setAudit(opened)
      resetScanState()
      setResumedNotice(
        resumed
          ? `Resumed audit ${opened.audit_ref} with ${opened.item_count} item${opened.item_count === 1 ? '' : 's'} already counted.`
          : null,
      )
      enqueueSnackbar(response.message ?? `Audit ${opened.audit_ref} is open for counting.`, {
        variant: resumed ? 'info' : 'success',
      })

      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      bumpScannerFocus()
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  const lookupMutation = useMutation({
    mutationFn: async (code: string) => get<AuditLookupResult>('/audits/lookup', { shop_id: shopId, code }),
    onSuccess: (response) => {
      const found = response.data.matches ?? []

      if (!response.data.found || found.length === 0) {
        setMatches([])
        setSelectedMatch(null)
        setLookupFeedback({ severity: 'warning', message: response.message ?? 'That code was not found.' })
        bumpScannerFocus()
        return
      }

      setLookupFeedback(null)

      if (found.length === 1) {
        openCountPanel(found[0])
      } else {
        setMatches(found)
        setSelectedMatch(null)
      }
    },
    onError: (caught) => {
      setMatches([])
      setSelectedMatch(null)
      setLookupFeedback({ severity: 'error', message: apiErrorMessage(caught) })
      bumpScannerFocus()
    },
  })

  const countMutation = useMutation({
    mutationFn: async (vars: { item_stock_id: number; physical_qty: number; loose_qty: number }) =>
      post<AuditLine>(`/audits/${audit?.id}/count`, vars),
    onSuccess: (response) => {
      // meta.audit is the refreshed running total; the running totals shown
      // on the strip must come from it rather than being derived here.
      const refreshedAudit = (response.meta as unknown as { audit?: Audit } | undefined)?.audit
      if (refreshedAudit) setAudit(refreshedAudit)

      const label = [response.data.product_code, response.data.description].filter(Boolean).join(' — ')
      setSaveNotice({
        heading: pendingWasUpdateRef.current ? '✓ Updated' : '✓ Counted',
        detail: label,
      })

      setSelectedMatch(null)
      setMatches([])
      setPhysicalQty('')
      setLooseQty('')
      setCountError(null)

      void queryClient.invalidateQueries({ queryKey: ['workspace-audit-lines', audit?.id] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      bumpScannerFocus()
    },
    onError: (caught) => setCountError(apiErrorMessage(caught)),
  })

  const deleteMutation = useMutation({
    mutationFn: async (line: AuditLine) => destroy<{ audit: Audit }>(`/audits/${audit?.id}/count/${line.id}`),
    onSuccess: (response) => {
      setAudit(response.data.audit)
      setDeleteTarget(null)
      enqueueSnackbar(response.message ?? 'The counted line was removed.', { variant: 'success' })

      void queryClient.invalidateQueries({ queryKey: ['workspace-audit-lines', audit?.id] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      bumpScannerFocus()
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' })
      setDeleteTarget(null)
    },
  })

  const completeMutation = useMutation({
    mutationFn: async () => post<Audit>(`/audits/${audit?.id}/complete`),
    onSuccess: (response) => {
      const completed = response.data

      enqueueSnackbar(response.message ?? `Audit ${completed.audit_ref} is complete.`, { variant: 'success' })
      setCompleteConfirmOpen(false)
      resetWorkspace()
      setCompletedSummary({
        audit_ref: completed.audit_ref,
        item_count: completed.item_count,
        variance_count: completed.variance_count,
      })

      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' })
      setCompleteConfirmOpen(false)
    },
  })

  function submitScan() {
    if (!audit) return

    const code = scanValue.trim()
    if (code === '') return

    // Cleared immediately, not once the response lands: a handheld scanner is
    // a keyboard that types fast and does not wait, so the field has to be
    // empty and ready for the next scan before this one's answer arrives.
    setScanValue('')
    setLookupFeedback(null)
    setSaveNotice(null)
    lookupMutation.mutate(code)
  }

  function saveCount() {
    if (!selectedMatch || !audit) return

    pendingWasUpdateRef.current = countedLines.some((line) => line.item_stock_id === selectedMatch.item_stock_id)
    setCountError(null)

    countMutation.mutate({
      item_stock_id: selectedMatch.item_stock_id,
      physical_qty: Number(physicalQty || 0),
      loose_qty: Number(looseQty || 0),
    })
  }

  return (
    <Box>
      <PageHeader
        title="Stock Audit"
        description="Count a shop's shelf directly from the browser — scan or type a barcode, confirm the quantity, and it is saved immediately."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Stock Audit' }]}
      />

      {completedSummary ? (
        <Alert
          severity="success"
          sx={{ mb: 2.5 }}
          action={
            <Button color="inherit" size="small" onClick={() => navigate('/stock-adjustment')}>
              Go to Stock Adjustment
            </Button>
          }
        >
          <AlertTitle>Audit {completedSummary.audit_ref} completed</AlertTitle>
          {formatNumber(completedSummary.item_count)} item{completedSummary.item_count === 1 ? '' : 's'} counted,{' '}
          {formatNumber(completedSummary.variance_count)} with a variance. Choose a shop below to start a new count.
        </Alert>
      ) : null}

      {shopsIsError ? (
        <ErrorState message={apiErrorMessage(shopsError)} onRetry={() => void refetchShops()} />
      ) : !shopsLoading && (shops ?? []).length === 0 ? (
        <EmptyState
          icon={<StorefrontRoundedIcon />}
          title="No shops available"
          description="Ask an administrator to assign you to a shop before you can start counting."
        />
      ) : (
        <Stack spacing={2.5}>
          <Card>
            <CardContent sx={{ p: 2.5 }}>
              <TextField
                select
                label="Shop"
                size="small"
                value={shopId}
                onChange={(event) => handleShopChange(event.target.value)}
                disabled={shopsLoading || Boolean(audit)}
                helperText={audit ? 'Locked while an audit is open — complete it to choose another shop.' : ' '}
                slotProps={{ select: { native: true } }}
                sx={{ maxWidth: 420 }}
                fullWidth
              >
                <option value="">{shopsLoading ? 'Loading shops…' : 'Select a shop…'}</option>
                {(shops ?? []).map((shop) => (
                  <option key={shop.id} value={shop.id}>
                    {shop.label}
                  </option>
                ))}
              </TextField>
            </CardContent>
          </Card>

          {shopId === '' ? (
            <EmptyState
              icon={<StorefrontRoundedIcon />}
              title="Choose a shop to begin"
              description="Counting starts by choosing the shop you want to count. Pick one above, then start a new audit."
            />
          ) : !audit ? (
            <Card>
              <CardContent sx={{ p: 3, textAlign: 'center' }}>
                <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
                  Ready to count {selectedShop?.label ?? ''}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2.5 }}>
                  Starting opens a new audit for this shop, or resumes one already in progress with nothing lost.
                </Typography>
                <Button
                  variant="contained"
                  size="large"
                  startIcon={
                    startMutation.isPending ? <CircularProgress size={16} color="inherit" /> : <QrCodeScannerRoundedIcon />
                  }
                  onClick={() => startMutation.mutate()}
                  disabled={startMutation.isPending}
                >
                  {startMutation.isPending ? 'Starting…' : 'Start New Audit'}
                </Button>
              </CardContent>
            </Card>
          ) : (
            <>
              <Card>
                <CardContent sx={{ p: 2.5 }}>
                  <Box
                    sx={{
                      display: 'grid',
                      gap: 2.5,
                      gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', md: 'repeat(6, 1fr)' },
                      alignItems: 'center',
                    }}
                  >
                    <Stat label="Audit Reference" value={audit.audit_ref} strong />
                    <Stat label="Shop" value={`${audit.shop_code} — ${audit.shop_name}`} />
                    <Stat label="Source" value={(audit.source ?? 'system').toUpperCase()} />
                    <Box>
                      <Typography variant="caption" sx={{ display: 'block', mb: 0.5 }}>
                        Status
                      </Typography>
                      <StatusBadge status={audit.status} />
                    </Box>
                    <Stat label="Items counted" value={formatNumber(audit.item_count)} />
                    <Stat label="Variance" value={formatNumber(audit.variance_count)} />
                  </Box>
                </CardContent>
              </Card>

              {resumedNotice ? <Alert severity="info">{resumedNotice}</Alert> : null}

              <Card>
                <CardContent sx={{ p: 2.5 }}>
                  <Box
                    sx={{
                      display: 'flex',
                      flexDirection: { xs: 'column', sm: 'row' },
                      gap: 1.5,
                      alignItems: { xs: 'stretch', sm: 'flex-end' },
                    }}
                  >
                    <TextField
                      inputRef={scannerRef}
                      autoFocus
                      label="Scan or type a barcode"
                      placeholder="Scan a barcode, or type a product code and press Enter"
                      value={scanValue}
                      onChange={(event) => setScanValue(event.target.value)}
                      onKeyDown={(event) => {
                        // USB/handheld scanners type the code then send Enter;
                        // this submits whatever is in the field right away,
                        // with no debounce on that path.
                        if (event.key === 'Enter' && scanValue.trim() !== '' && !lookupMutation.isPending) {
                          event.preventDefault()
                          submitScan()
                        }
                      }}
                      disabled={lookupMutation.isPending}
                      helperText="Press Enter after scanning, or type a code and use Look up."
                      slotProps={{ htmlInput: { autoComplete: 'off' } }}
                      sx={{ flexGrow: 1, minWidth: 240 }}
                    />
                    <Button
                      variant="outlined"
                      onClick={submitScan}
                      startIcon={lookupMutation.isPending ? <CircularProgress size={15} /> : <SearchRoundedIcon />}
                      disabled={scanValue.trim() === '' || lookupMutation.isPending}
                      sx={{ flexShrink: 0 }}
                    >
                      {lookupMutation.isPending ? 'Looking up…' : 'Look up'}
                    </Button>
                  </Box>

                  {lookupFeedback ? (
                    <Alert severity={lookupFeedback.severity} sx={{ mt: 2 }}>
                      {lookupFeedback.message}
                    </Alert>
                  ) : null}

                  {saveNotice ? (
                    <Alert severity="success" sx={{ mt: 2 }}>
                      <strong>{saveNotice.heading}</strong>
                      {saveNotice.detail ? ` ${saveNotice.detail}` : null}
                    </Alert>
                  ) : null}
                </CardContent>
              </Card>

              {matches.length > 1 ? <BatchPicker matches={matches} onSelect={openCountPanel} /> : null}

              {selectedMatch ? (
                <CountPanel
                  key={selectedMatch.item_stock_id}
                  match={selectedMatch}
                  physicalQty={physicalQty}
                  looseQty={looseQty}
                  onPhysicalChange={setPhysicalQty}
                  onLooseChange={setLooseQty}
                  onSave={saveCount}
                  onCancel={cancelCountPanel}
                  busy={countMutation.isPending}
                  error={countError}
                />
              ) : null}

              <Box>
                <Typography variant="subtitle1" sx={{ mb: 1.25 }}>
                  Counted so far
                </Typography>
                <CountedLinesTable
                  lines={countedLines}
                  loading={linesQuery.isFetching}
                  error={linesQuery.isError ? apiErrorMessage(linesQuery.error) : null}
                  onRetry={() => void linesQuery.refetch()}
                  onRemove={(line) => setDeleteTarget(line)}
                  removingId={deleteMutation.isPending ? (deleteTarget?.id ?? null) : null}
                />
              </Box>

              <Card>
                <CardContent sx={{ p: 2.5, display: 'flex', justifyContent: 'flex-end' }}>
                  <Button
                    variant="contained"
                    startIcon={<DoneAllRoundedIcon />}
                    onClick={() => setCompleteConfirmOpen(true)}
                    disabled={completeMutation.isPending}
                  >
                    Complete audit
                  </Button>
                </CardContent>
              </Card>
            </>
          )}
        </Stack>
      )}

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Remove this counted line?"
        message={`${deleteTarget?.description ?? deleteTarget?.product_code ?? 'This line'} will be removed from the audit. The counted quantity is discarded and would need to be scanned again.`}
        confirmLabel="Remove"
        severity="error"
        busy={deleteMutation.isPending}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget)}
      />

      <ConfirmDialog
        open={completeConfirmOpen}
        title="Complete this audit?"
        message={`${formatNumber(audit?.item_count ?? 0)} item(s) counted, ${formatNumber(audit?.variance_count ?? 0)} with a variance, will be finalised. The audit moves to Submitted and can then be adjusted.`}
        confirmLabel="Yes, complete audit"
        busy={completeMutation.isPending}
        onClose={() => setCompleteConfirmOpen(false)}
        onConfirm={() => completeMutation.mutate()}
      />
    </Box>
  )
}

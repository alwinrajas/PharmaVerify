import { Alert, AlertTitle, Box, Stack, TextField, Typography } from '@mui/material'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useState } from 'react'
import { FormDialog } from '@/components/dialogs'
import { apiErrorMessage, post } from '@/services/apiClient'
import { formatQuantity, formatSignedQuantity } from '@/utils/format'
import type { AuditLine } from '@/types'

/** A sentence fragment unique to the drift refusal thrown by the backend; see StockAdjustmentService. */
const DRIFT_MARKER = 'has changed since this audit was counted'

/** What posting this line will do to system stock, stated before it happens. */
function lineSummary(line: AuditLine) {
  const current = Number(line.system_qty)
  const physicalCount = Number(line.physical_qty) + Number(line.loose_qty ?? 0)
  const adjustment = physicalCount - current

  return { current, physicalCount, adjustment, newStock: physicalCount }
}

/**
 * Posting an adjustment.
 *
 * There is no approval step: saving here changes the shop's system stock
 * immediately, so the dialog says plainly what will happen before it does.
 */
export function AdjustDialog({
  lines,
  open,
  onClose,
  onPosted,
}: {
  lines: AuditLine[]
  open: boolean
  onClose: () => void
  onPosted?: () => void
}) {
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()

  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  // Set once the server has refused because stock moved after the audit was
  // counted. The next submit resends the same request with the drift
  // acknowledged, rather than making the user re-open the dialog.
  const [driftConfirm, setDriftConfirm] = useState(false)

  useEffect(() => {
    if (open) {
      setReason('')
      setError(null)
      setDriftConfirm(false)
    }
  }, [open])

  const single = lines.length === 1 ? lines[0] : null

  const mutation = useMutation({
    mutationFn: async (acknowledgeDrift: boolean) =>
      post<unknown>('/adjustments', {
        ...(single ? { audit_line_id: single.id } : { audit_line_ids: lines.map((line) => line.id) }),
        reason: reason || null,
        ...(acknowledgeDrift ? { acknowledge_drift: true } : {}),
      }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Adjustment posted.', { variant: 'success' })

      void queryClient.invalidateQueries({ queryKey: ['audit-lines'] })
      void queryClient.invalidateQueries({ queryKey: ['verification'] })
      void queryClient.invalidateQueries({ queryKey: ['variance'] })
      void queryClient.invalidateQueries({ queryKey: ['adjustments'] })
      void queryClient.invalidateQueries({ queryKey: ['item-stocks'] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      onPosted?.()
      onClose()
    },
    onError: (caught) => {
      const message = apiErrorMessage(caught)
      setError(message)
      setDriftConfirm(message.includes(DRIFT_MARKER))
    },
  })

  if (lines.length === 0) return null

  return (
    <FormDialog
      open={open}
      title={single ? 'Post stock adjustment' : `Post ${lines.length} stock adjustments`}
      description="The adjustment takes effect as soon as it is saved."
      onClose={onClose}
      onSubmit={() => {
        if (!driftConfirm) setError(null)
        mutation.mutate(driftConfirm)
      }}
      submitLabel={mutation.isPending ? 'Posting…' : driftConfirm ? 'Confirm and post' : 'Post adjustment'}
      submitColor={driftConfirm ? 'warning' : 'primary'}
      busy={mutation.isPending}
      error={error}
      maxWidth="sm"
    >
      <Alert severity="warning" sx={{ mb: 2.5 }}>
        <AlertTitle>This posts immediately</AlertTitle>
        There is no approval workflow. The system stock will be set to the physical quantity that was counted, and the
        change is recorded in the adjustment history.
      </Alert>

      <Box sx={{ maxHeight: 320, overflowY: 'auto', mb: 2.5 }}>
        <Stack spacing={1.25}>
          {lines.map((line) => {
            const summary = lineSummary(line)

            return (
              <Box key={line.id} sx={{ p: 1.75, borderRadius: 2, border: 1, borderColor: 'divider' }}>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {line.description ?? line.product_code}
                </Typography>
                <Typography variant="caption" sx={{ display: 'block', mb: 1.25 }}>
                  {[line.shop_code, line.product_code, line.batch && `Batch ${line.batch}`].filter(Boolean).join(' · ')}
                </Typography>

                <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 1 }}>
                  <Box>
                    <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary' }}>
                      Current stock
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {formatQuantity(summary.current)}
                    </Typography>
                  </Box>
                  <Box>
                    <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary' }}>
                      Physical count
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {formatQuantity(summary.physicalCount)}
                    </Typography>
                  </Box>
                  <Box>
                    <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary' }}>
                      Adjustment
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 700 }}>
                      {formatSignedQuantity(summary.adjustment)}
                    </Typography>
                  </Box>
                  <Box>
                    <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary' }}>
                      New stock
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 700 }}>
                      {formatQuantity(summary.newStock)}
                    </Typography>
                  </Box>
                </Box>
              </Box>
            )
          })}
        </Stack>
      </Box>

      <TextField
        label="Reason"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        size="small"
        fullWidth
        multiline
        rows={2}
        placeholder="e.g. Physical count confirmed by supervisor"
        helperText="Kept with the adjustment history."
      />
    </FormDialog>
  )
}

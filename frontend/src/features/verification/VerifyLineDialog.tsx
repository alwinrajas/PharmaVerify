import { Alert, Box, Stack, TextField, Typography } from '@mui/material'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useState } from 'react'
import { FormDialog } from '@/components/dialogs'
import { VarianceValue } from '@/components/VarianceValue'
import { apiErrorMessage, patch } from '@/services/apiClient'
import { formatQuantity } from '@/utils/format'
import type { AuditLine } from '@/types'

/**
 * Correcting a counted line during verification.
 *
 * Only the fields a verifier is allowed to touch are offered; the variance is
 * recomputed by the server, and every change is written to the audit trail with
 * its old and new value.
 */
export function VerifyLineDialog({
  line,
  open,
  onClose,
  onSaved,
}: {
  line: AuditLine | null
  open: boolean
  onClose: () => void
  onSaved?: () => void
}) {
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()

  const [physicalQty, setPhysicalQty] = useState('')
  const [looseQty, setLooseQty] = useState('')
  const [batch, setBatch] = useState('')
  const [expiry, setExpiry] = useState('')
  const [shelf, setShelf] = useState('')
  const [remarks, setRemarks] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!line) return

    setPhysicalQty(String(line.physical_qty ?? 0))
    setLooseQty(String(line.loose_qty ?? 0))
    setBatch(line.batch ?? '')
    setExpiry(line.expiry_date ?? '')
    setShelf(line.shelf_location ?? '')
    setRemarks(line.remarks ?? '')
    setError(null)
  }, [line])

  const mutation = useMutation({
    mutationFn: async () =>
      patch<AuditLine>(`/verification/lines/${line!.id}`, {
        physical_qty: Number(physicalQty || 0),
        loose_qty: Number(looseQty || 0),
        batch,
        expiry_date: expiry || null,
        shelf_location: shelf || null,
        remarks: remarks || null,
        mark_verified: true,
      }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Verification saved.', { variant: 'success' })

      void queryClient.invalidateQueries({ queryKey: ['audit-lines'] })
      void queryClient.invalidateQueries({ queryKey: ['verification'] })
      void queryClient.invalidateQueries({ queryKey: ['variance'] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })

      onSaved?.()
      onClose()
    },
    onError: (caught) => setError(apiErrorMessage(caught)),
  })

  if (!line) return null

  // System - (Physical + Loose), the same rule the server applies. Shown
  // live so the verifier can see the effect of a correction before saving.
  const projectedVariance =
    Number(line.system_qty) - (Number(physicalQty || 0) + Number(looseQty || 0))

  return (
    <FormDialog
      consequence="Saving records the counted quantities against this line and marks it verified. The variance is recalculated by the server from the quantities you enter."
      open={open}
      title="Verify counted line"
      description={line.description ?? line.product_code ?? 'Counted line'}
      onClose={onClose}
      onSubmit={() => {
        setError(null)
        mutation.mutate()
      }}
      submitLabel="Save and verify"
      busy={mutation.isPending}
      error={error}
    >
      <Box sx={{ mb: 2.5, p: 2, borderRadius: 2, bgcolor: 'rgba(15,93,76,0.045)' }}>
        <Stack direction="row" spacing={3}>
          <Box>
            <Typography variant="caption" sx={{ display: 'block' }}>
              System quantity
            </Typography>
            <Typography variant="h5">{formatQuantity(line.system_qty)}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" sx={{ display: 'block' }}>
              Physical quantity
            </Typography>
            <Typography variant="h5">{formatQuantity(Number(physicalQty || 0))}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" sx={{ display: 'block' }}>
              Loose quantity
            </Typography>
            <Typography variant="h5">{formatQuantity(Number(looseQty || 0))}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" sx={{ display: 'block' }}>
              Variance
            </Typography>
            <Typography variant="h5" component="div">
              <VarianceValue value={projectedVariance} />
            </Typography>
          </Box>
        </Stack>
      </Box>

      {line.is_unknown_item ? (
        <Alert severity="warning" sx={{ mb: 2.5 }}>
          This product is not in the shop's stock file, so it cannot be adjusted. Record it as a Stock Take instead.
        </Alert>
      ) : null}

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField
          label="Physical Quantity"
          type="number"
          value={physicalQty}
          onChange={(event) => setPhysicalQty(event.target.value)}
          size="small"
          fullWidth
          required
          slotProps={{ htmlInput: { min: 0, step: 'any' } }}
        />
        <TextField
          label="Loose Quantity"
          type="number"
          value={looseQty}
          onChange={(event) => setLooseQty(event.target.value)}
          size="small"
          fullWidth
          helperText="Counted outside a full pack, alongside the whole units."
          slotProps={{ htmlInput: { min: 0, step: 'any' } }}
        />
        <TextField
          label="Batch"
          value={batch}
          onChange={(event) => setBatch(event.target.value)}
          size="small"
          fullWidth
        />
        <TextField
          label="Expiry Date"
          type="date"
          value={expiry}
          onChange={(event) => setExpiry(event.target.value)}
          size="small"
          fullWidth
          slotProps={{ inputLabel: { shrink: true } }}
        />
        <TextField
          label="Shelf / Location"
          value={shelf}
          onChange={(event) => setShelf(event.target.value)}
          size="small"
          fullWidth
        />
        <TextField
          label="Remarks"
          value={remarks}
          onChange={(event) => setRemarks(event.target.value)}
          size="small"
          fullWidth
          multiline
          rows={2}
          sx={{ gridColumn: { sm: '1 / -1' } }}
          helperText="Recorded with the change in the audit trail."
        />
      </Box>
    </FormDialog>
  )
}

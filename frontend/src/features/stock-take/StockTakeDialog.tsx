import { Alert, Box, TextField } from '@mui/material'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useState } from 'react'
import { FormDialog } from '@/components/dialogs'
import { useShopOptions } from '@/hooks/useOptions'
import { apiErrorMessage, post } from '@/services/apiClient'
import type { AuditLine, StockTake } from '@/types'

const UOM_OPTIONS = ['EA', 'STRIP', 'BOX', 'BTL', 'PACK', 'VIAL', 'TUBE']

interface StockTakeForm {
  shop_id: string
  audit_id: string
  audit_line_id: string
  barcode: string
  product_code: string
  description: string
  physical_qty: string
  loose_qty: string
  uom: string
  batch: string
  expiry_date: string
  shelf_location: string
  remarks: string
}

const emptyForm: StockTakeForm = {
  shop_id: '',
  audit_id: '',
  audit_line_id: '',
  barcode: '',
  product_code: '',
  description: '',
  physical_qty: '',
  loose_qty: '',
  uom: 'EA',
  batch: '',
  expiry_date: '',
  shelf_location: '',
  remarks: '',
}

/**
 * Records stock found on the shelf that the shop's stock file does not carry.
 * It never creates an item master record — that decision stays with the business.
 */
export function StockTakeDialog({
  open,
  onClose,
  fromLine,
  sessionId = null,
  defaultShopId,
  onSaved,
}: {
  open: boolean
  onClose: () => void
  fromLine?: AuditLine | null
  /** The open cycle, when the shop has one. A take may also stand alone. */
  sessionId?: number | null
  defaultShopId?: string
  onSaved?: () => void
}) {
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const { data: shops } = useShopOptions()

  const [form, setForm] = useState<StockTakeForm>(emptyForm)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return

    if (fromLine) {
      setForm({
        ...emptyForm,
        shop_id: String(fromLine.shop_id),
        audit_id: fromLine.audit_id ? String(fromLine.audit_id) : '',
        audit_line_id: String(fromLine.id),
        barcode: fromLine.barcode ?? '',
        product_code: fromLine.product_code ?? '',
        description: fromLine.description ?? '',
        physical_qty: String(fromLine.physical_qty ?? ''),
        loose_qty: String(fromLine.loose_qty ?? 0),
        uom: fromLine.uom ?? 'EA',
        batch: fromLine.batch ?? '',
        expiry_date: fromLine.expiry_date ?? '',
        shelf_location: fromLine.shelf_location ?? '',
        remarks: '',
      })
    } else {
      setForm({ ...emptyForm, shop_id: defaultShopId ?? '' })
    }

    setError(null)
  }, [open, fromLine, defaultShopId])

  const mutation = useMutation({
    mutationFn: async () =>
      post<StockTake>('/stock-takes', {
        shop_id: Number(form.shop_id),
        audit_id: form.audit_id ? Number(form.audit_id) : null,
        audit_line_id: form.audit_line_id ? Number(form.audit_line_id) : null,
        barcode: form.barcode || null,
        product_code: form.product_code || null,
        description: form.description,
        physical_qty: Number(form.physical_qty || 0),
        stock_take_session_id: sessionId,
        loose_qty: Number(form.loose_qty || 0),
        uom: form.uom,
        batch: form.batch || null,
        expiry_date: form.expiry_date || null,
        shelf_location: form.shelf_location || null,
        remarks: form.remarks || null,
      }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Stock take recorded.', { variant: 'success' })

      void queryClient.invalidateQueries({ queryKey: ['stock-takes'] })
      void queryClient.invalidateQueries({ queryKey: ['stock-take-candidates'] })
      void queryClient.invalidateQueries({ queryKey: ['audit-lines'] })

      onSaved?.()
      onClose()
    },
    onError: (caught) => setError(apiErrorMessage(caught)),
  })

  return (
    <FormDialog
      open={open}
      title="Record Stock Take"
      description="Physical stock found on the shelf that the shop's stock information does not contain."
      onClose={onClose}
      onSubmit={() => {
        setError(null)
        mutation.mutate()
      }}
      submitLabel="Record stock take"
      busy={mutation.isPending}
      error={error}
      maxWidth="md"
      submitDisabled={form.shop_id === '' || form.description.trim() === '' || form.physical_qty === ''}
    >
      <Alert severity="info" sx={{ mb: 2.5 }}>
        Recording a stock take does <strong>not</strong> create a new item in the item master. The entry is kept
        separately for the business to review.
      </Alert>

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
        <TextField
          select
          label="Shop"
          value={form.shop_id}
          onChange={(event) => setForm({ ...form, shop_id: event.target.value })}
          size="small"
          fullWidth
          required
          disabled={Boolean(fromLine)}
          sx={{ gridColumn: { sm: '1 / -1' } }}
          slotProps={{ select: { native: true } }}
        >
          <option value="">Select a shop…</option>
          {(shops ?? []).map((shop) => (
            <option key={shop.id} value={shop.id}>
              {shop.label}
            </option>
          ))}
        </TextField>

        <TextField
          label="Barcode"
          value={form.barcode}
          onChange={(event) => setForm({ ...form, barcode: event.target.value })}
          size="small"
          fullWidth
        />
        <TextField
          label="Product Code"
          value={form.product_code}
          onChange={(event) => setForm({ ...form, product_code: event.target.value })}
          size="small"
          fullWidth
        />

        <TextField
          label="Product Description"
          value={form.description}
          onChange={(event) => setForm({ ...form, description: event.target.value })}
          size="small"
          fullWidth
          required
          sx={{ gridColumn: { sm: '1 / -1' } }}
        />

        <TextField
          label="Physical Quantity"
          type="number"
          value={form.physical_qty}
          onChange={(event) => setForm({ ...form, physical_qty: event.target.value })}
          size="small"
          fullWidth
          required
          slotProps={{ htmlInput: { min: 0, step: 'any' } }}
        />
        <TextField
          label="Loose Quantity"
          type="number"
          value={form.loose_qty}
          onChange={(event) => setForm({ ...form, loose_qty: event.target.value })}
          size="small"
          fullWidth
          helperText="Counted outside a full pack, alongside the whole units."
          slotProps={{ htmlInput: { min: 0, step: 'any' } }}
        />
        <TextField
          select
          label="Unit of Measure"
          value={form.uom}
          onChange={(event) => setForm({ ...form, uom: event.target.value })}
          size="small"
          fullWidth
          slotProps={{ select: { native: true } }}
        >
          {UOM_OPTIONS.map((uom) => (
            <option key={uom} value={uom}>
              {uom}
            </option>
          ))}
        </TextField>

        <TextField
          label="Batch"
          value={form.batch}
          onChange={(event) => setForm({ ...form, batch: event.target.value })}
          size="small"
          fullWidth
        />
        <TextField
          label="Expiry Date"
          type="date"
          value={form.expiry_date}
          onChange={(event) => setForm({ ...form, expiry_date: event.target.value })}
          size="small"
          fullWidth
          slotProps={{ inputLabel: { shrink: true } }}
        />

        <TextField
          label="Shelf / Location"
          value={form.shelf_location}
          onChange={(event) => setForm({ ...form, shelf_location: event.target.value })}
          size="small"
          fullWidth
        />
        <TextField
          label="Remarks"
          value={form.remarks}
          onChange={(event) => setForm({ ...form, remarks: event.target.value })}
          size="small"
          fullWidth
        />
      </Box>
    </FormDialog>
  )
}

import { Alert, Box, Button, Card, CardContent, CircularProgress, Stack, TextField, Typography } from '@mui/material'
import { VarianceWord } from './VarianceWord'
import { formatDate, formatQuantity } from '@/utils/format'
import type { AuditLookupMatch } from '@/types'

/** A small label-over-value pair, for the product facts around the count. */
function Fact({ label, value }: { label: string; value: string }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" sx={{ display: 'block' }}>
        {label}
      </Typography>
      <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
        {value}
      </Typography>
    </Box>
  )
}

/**
 * The product currently being counted: what it is, what the system holds for
 * it, and the two quantities the operator supplies.
 *
 * The variance readout recomputes on every keystroke using the same rule the
 * server applies (System - (Physical + Loose)), so the operator sees the
 * effect of what they typed before committing to it.
 */
export function CountPanel({
  match,
  physicalQty,
  looseQty,
  onPhysicalChange,
  onLooseChange,
  onSave,
  onCancel,
  busy,
  error,
}: {
  match: AuditLookupMatch
  physicalQty: string
  looseQty: string
  onPhysicalChange: (value: string) => void
  onLooseChange: (value: string) => void
  onSave: () => void
  onCancel: () => void
  busy: boolean
  error: string | null
}) {
  const variance = Number(match.system_qty) - (Number(physicalQty || 0) + Number(looseQty || 0))
  const canSave = physicalQty.trim() !== '' && Number(physicalQty) >= 0 && !busy

  return (
    <Card>
      <CardContent sx={{ p: 2.5 }}>
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          justifyContent="space-between"
          alignItems={{ xs: 'flex-start', sm: 'center' }}
          spacing={2}
          sx={{ mb: 2.5 }}
        >
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="h5" noWrap>
              {match.description}
            </Typography>
            <Typography variant="body2" color="text.secondary" noWrap>
              {[match.product_code, match.barcode].filter(Boolean).join(' · ') || '—'}
            </Typography>
          </Box>

          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, auto)', gap: 2.5, flexShrink: 0 }}>
            <Fact label="Batch" value={match.batch || '—'} />
            <Fact label="Expiry" value={formatDate(match.expiry_date)} />
            <Fact label="Shelf" value={match.shelf_location ?? '—'} />
            <Fact label="UOM" value={match.uom} />
          </Box>
        </Stack>

        <Box sx={{ p: 2, mb: 2.5, borderRadius: 2, bgcolor: 'rgba(15,93,76,0.045)', textAlign: 'center' }}>
          <Typography variant="caption">System quantity</Typography>
          <Typography variant="h3">{formatQuantity(match.system_qty)}</Typography>
        </Box>

        {error ? (
          <Alert severity="error" sx={{ mb: 2.5 }}>
            {error}
          </Alert>
        ) : null}

        <Box>
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr' } }}>
            <TextField
              autoFocus
              label="Physical Quantity"
              type="number"
              value={physicalQty}
              onChange={(event) => onPhysicalChange(event.target.value)}
              onKeyDown={(event) => {
                // A handheld scanner ends every entry with Enter; this field
                // is filled in by hand instead, but the same key should save.
                if (event.key === 'Enter' && canSave) {
                  event.preventDefault()
                  onSave()
                }
              }}
              fullWidth
              required
              slotProps={{ htmlInput: { inputMode: 'decimal', min: 0, step: 'any' } }}
              sx={{
                '& input': { fontSize: '2rem', fontWeight: 700, textAlign: 'center', py: 1.5 },
                '& input::-webkit-outer-spin-button, & input::-webkit-inner-spin-button': {
                  WebkitAppearance: 'none',
                  margin: 0,
                },
              }}
            />
            <TextField
              label="Loose Quantity"
              type="number"
              value={looseQty}
              onChange={(event) => onLooseChange(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && canSave) {
                  event.preventDefault()
                  onSave()
                }
              }}
              fullWidth
              helperText="Outside a full pack"
              slotProps={{ htmlInput: { inputMode: 'decimal', min: 0, step: 'any' } }}
            />
          </Box>

          <Stack
            direction={{ xs: 'column', sm: 'row' }}
            justifyContent="space-between"
            alignItems={{ xs: 'stretch', sm: 'center' }}
            spacing={2}
            sx={{ mt: 2.5 }}
          >
            <Box>
              <Typography variant="caption" sx={{ display: 'block' }}>
                Variance
              </Typography>
              <Typography variant="h5" component="div">
                <VarianceWord value={variance} />
              </Typography>
            </Box>

            <Stack direction="row" spacing={1.5} justifyContent="flex-end">
              <Button color="inherit" onClick={onCancel} disabled={busy}>
                Cancel
              </Button>
              <Button
                variant="contained"
                onClick={onSave}
                disabled={!canSave}
                startIcon={busy ? <CircularProgress size={15} color="inherit" /> : undefined}
              >
                {busy ? 'Saving…' : 'Save count'}
              </Button>
            </Stack>
          </Stack>
        </Box>
      </CardContent>
    </Card>
  )
}

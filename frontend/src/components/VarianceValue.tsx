import { Box, Typography } from '@mui/material'
import { formatQuantity } from '@/utils/format'

/**
 * A variance figure, coloured by direction: short in red, excess in green,
 * a match in muted grey. Used everywhere a variance is shown.
 */
export function VarianceValue({ value, showSign = true }: { value: number | null | undefined; showSign?: boolean }) {
  const amount = Number(value ?? 0)

  const colour = amount < 0 ? 'error.main' : amount > 0 ? 'success.main' : 'text.secondary'
  const prefix = showSign && amount > 0 ? '+' : ''

  return (
    <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5 }}>
      <Typography component="span" variant="body2" sx={{ color: colour, fontWeight: amount === 0 ? 400 : 700 }}>
        {prefix}
        {formatQuantity(amount)}
      </Typography>
    </Box>
  )
}

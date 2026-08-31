import { Box } from '@mui/material'
import { variance as varianceColour } from '@/theme'
import { formatQuantity } from '@/utils/format'

/**
 * Variance stated in words, for the counting workflow.
 *
 * `VarianceValue` (used on the review tables downstream of this screen) shows
 * a bare signed figure and leans on colour to carry the direction — fine for a
 * table someone is already reading closely. Here the reader is mid-scan with
 * their hands on a device, not studying a column, so the direction has to be
 * legible on its own: "Short 5" rather than a colour and a number that could
 * be read either way.
 *
 * Same convention as everywhere else: System - (Physical + Loose), so a
 * positive figure is a shortage and a negative one an excess. Colour is kept
 * as a secondary cue, never the only one.
 */
export function VarianceWord({ value }: { value: number | string | null | undefined }) {
  const amount = Number(value ?? 0)

  if (amount > 0) {
    return (
      <Box component="span" sx={{ color: varianceColour.short, fontWeight: 700 }}>
        Short {formatQuantity(amount)}
      </Box>
    )
  }

  if (amount < 0) {
    return (
      <Box component="span" sx={{ color: varianceColour.excess, fontWeight: 700 }}>
        Excess {formatQuantity(Math.abs(amount))}
      </Box>
    )
  }

  return (
    <Box component="span" sx={{ color: varianceColour.matched, fontWeight: 500 }}>
      Match
    </Box>
  )
}

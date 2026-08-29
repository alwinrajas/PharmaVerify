import { Box } from '@mui/material'
import { variance as varianceColour } from '@/theme'
import { formatQuantity } from '@/utils/format'

/**
 * A variance figure, coloured by direction.
 *
 * Variance is `System - (Physical + Loose)`, so a **positive** figure is a
 * shortage and a negative one an excess. Short is red and excess is blue —
 * both are discrepancies that need someone to look at them. Excess is
 * deliberately not green: stock on a shelf that the system does not know about
 * is not good news, and colouring it as success tells the reader to move on. A
 * match is neutral, and recedes.
 *
 * No sign is prefixed. Under this convention "+5" would mean five units
 * *missing*, which reads as the opposite of what it is; the colour and the
 * column heading carry the direction instead, and the bare figure cannot
 * mislead. A negative value still shows its own minus sign.
 *
 * Figures use tabular numerals so a column of them lines up.
 */
export function VarianceValue({ value }: { value: number | null | undefined }) {
  const amount = Number(value ?? 0)

  const colour =
    amount > 0 ? varianceColour.short : amount < 0 ? varianceColour.excess : varianceColour.matched

  return (
    <Box
      component="span"
      sx={{
        color: colour,
        fontSize: '0.8125rem',
        fontWeight: amount === 0 ? 400 : 600,
        fontVariantNumeric: 'tabular-nums',
        fontFeatureSettings: '"tnum" 1',
      }}
    >
      {formatQuantity(amount)}
    </Box>
  )
}

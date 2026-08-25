import { Chip, type ChipProps } from '@mui/material'
import { statusPalette } from '@/theme'

/**
 * One chip for every status in the application, so that "verified" looks the
 * same on the audit list, the verification screen and the reports.
 */
export function StatusBadge({
  status,
  size = 'small',
  sx,
}: {
  status: string | null | undefined
  size?: ChipProps['size']
  sx?: ChipProps['sx']
}) {
  if (!status) return null

  const entry = statusPalette[status]

  const label = entry?.label ?? humanise(status)
  const color = entry?.color ?? '#5F6B6A'
  const bg = entry?.bg ?? '#ECEFEE'

  return <Chip size={size} label={label} sx={{ color, bgcolor: bg, ...sx }} />
}

function humanise(value: string): string {
  return value
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

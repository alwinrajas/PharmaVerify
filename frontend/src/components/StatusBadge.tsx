import { Box, type SxProps, type Theme } from '@mui/material'
import { neutral, statusPalette } from '@/theme'

/**
 * One badge for every status in the application, so that "verified" looks the
 * same on the audit list, the verification screen and the reports.
 *
 * A leading dot carries the colour and the tint stays quiet, because a busy
 * screen shows twenty of these at once and heavy fills turn a working table
 * into a colour chart.
 */
export function StatusBadge({
  status,
  size = 'small',
  sx,
}: {
  status: string | null | undefined
  size?: 'small' | 'medium'
  sx?: SxProps<Theme>
}) {
  if (!status) return null

  const entry = statusPalette[status]

  const label = entry?.label ?? humanise(status)
  const color = entry?.color ?? neutral[600]
  const bg = entry?.bg ?? neutral[100]

  return (
    <Box
      component="span"
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 0.65,
        color,
        bgcolor: bg,
        borderRadius: 1,
        fontSize: size === 'small' ? '0.75rem' : '0.8125rem',
        fontWeight: 500,
        lineHeight: 1.6,
        paddingInline: 0.85,
        paddingBlock: 0.15,
        whiteSpace: 'nowrap',
        ...sx,
      }}
    >
      <Box
        component="span"
        sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: 'currentColor', flexShrink: 0 }}
      />
      {label}
    </Box>
  )
}

function humanise(value: string): string {
  return value
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

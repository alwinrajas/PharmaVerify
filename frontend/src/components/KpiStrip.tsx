import { Box, Paper, Typography } from '@mui/material'
import type { ComponentType, ReactNode } from 'react'
import { neutral, semantic, variance as varianceColour } from '@/theme'

export type KpiTone = 'default' | 'success' | 'warning' | 'error' | 'info' | 'short' | 'excess' | 'matched'

export interface KpiItem {
  label: string
  value: ReactNode
  /** A short qualifier under the figure — units, a proportion, a date. */
  hint?: string
  icon?: ComponentType<{ fontSize?: 'small' | 'inherit' | 'medium' | 'large' }>
  tone?: KpiTone
}

/**
 * Resolves a tone to the accent used by the icon and the figure.
 *
 * `short` and `excess` exist so a variance figure carries the same meaning
 * here as it does in a table: both are discrepancies, and excess is never
 * dressed as a success.
 */
function accentFor(tone: KpiTone): string {
  switch (tone) {
    case 'success':
      return semantic.success.fg
    case 'warning':
      return semantic.warning.fg
    case 'error':
      return semantic.error.fg
    case 'info':
      return semantic.info.fg
    case 'short':
      return varianceColour.short
    case 'excess':
      return varianceColour.excess
    case 'matched':
      return varianceColour.matched
    default:
      return neutral[700]
  }
}

/**
 * The one way summary figures are shown.
 *
 * Compact by design: these sit above a table and every pixel they take is a
 * row the operator cannot see. The tint is carried by a small icon plate
 * rather than the whole card, so a row of six does not read as a colour chart.
 */
export function KpiStrip({ items, columns }: { items: KpiItem[]; columns?: number }) {
  if (items.length === 0) return null

  const count = columns ?? Math.min(items.length, 6)

  return (
    <Box
      sx={{
        display: 'grid',
        gap: 1.25,
        mb: 2,
        gridTemplateColumns: {
          xs: 'repeat(2, minmax(0, 1fr))',
          sm: 'repeat(3, minmax(0, 1fr))',
          lg: `repeat(${count}, minmax(0, 1fr))`,
        },
      }}
    >
      {items.map((item) => {
        const accent = accentFor(item.tone ?? 'default')
        const Icon = item.icon

        return (
          <Paper
            key={item.label}
            variant="outlined"
            sx={{
              px: 1.5,
              py: 1.25,
              display: 'flex',
              alignItems: 'center',
              gap: 1.25,
              minWidth: 0,
              borderRadius: 2,
            }}
          >
            {Icon ? (
              <Box
                sx={{
                  width: 34,
                  height: 34,
                  borderRadius: 1.5,
                  display: 'grid',
                  placeItems: 'center',
                  flexShrink: 0,
                  color: accent,
                  // A tint of the accent rather than the accent itself.
                  bgcolor: `color-mix(in srgb, ${accent} 11%, transparent)`,
                }}
              >
                <Icon fontSize="small" />
              </Box>
            ) : null}

            <Box sx={{ minWidth: 0 }}>
              <Typography
                sx={{
                  fontSize: '0.6875rem',
                  fontWeight: 500,
                  letterSpacing: '0.03em',
                  color: 'text.secondary',
                  lineHeight: 1.25,
                  // Labels wrap rather than truncate: "Awaiting adjustment"
                  // does not fit one line in a six-column strip, and a label
                  // clipped to "Awaiting adjustme…" tells the reader nothing.
                  display: '-webkit-box',
                  WebkitLineClamp: 2,
                  WebkitBoxOrient: 'vertical',
                  overflow: 'hidden',
                }}
              >
                {item.label}
              </Typography>

              <Typography
                sx={{
                  fontSize: '1.125rem',
                  fontWeight: 600,
                  lineHeight: 1.25,
                  color: item.tone && item.tone !== 'default' ? accent : 'text.primary',
                  fontVariantNumeric: 'tabular-nums',
                  fontFeatureSettings: '"tnum" 1',
                }}
                noWrap
              >
                {item.value}
              </Typography>

              {item.hint ? (
                <Typography sx={{ fontSize: '0.6875rem', color: 'text.secondary', lineHeight: 1.3 }} noWrap>
                  {item.hint}
                </Typography>
              ) : null}
            </Box>
          </Paper>
        )
      })}
    </Box>
  )
}

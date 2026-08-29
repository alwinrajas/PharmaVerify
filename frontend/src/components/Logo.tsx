import { Box, Stack, Typography } from '@mui/material'
import { brand } from '@/theme'

/**
 * The PharmaVerify mark.
 *
 * A refinement of the original rather than a replacement: the pharmacy cross
 * and the verification check were already the right two ideas. They now sit
 * inside a shield, which is what carries "trust" without adding a third
 * element, and the check reads as a seal on the shield rather than a sticker
 * beside it.
 *
 * Drawn rather than imported so it takes its colours from the surface it is
 * on — light on the dark sidebar, brand green on the login card.
 */
export function LogoMark({ size = 32, tone = 'dark' }: { size?: number; tone?: 'dark' | 'light' }) {
  // `dark` means the mark sits on a dark ground and paints itself light.
  const shield = tone === 'dark' ? '#FFFFFF' : brand[600]
  const cross = tone === 'dark' ? brand[600] : '#FFFFFF'
  const seal = brand.accent

  return (
    <Box
      component="svg"
      viewBox="0 0 40 40"
      role="img"
      aria-label="PharmaVerify"
      sx={{ width: size, height: size, flexShrink: 0, display: 'block' }}
    >
      {/* Shield — trust, and the container the other two elements sit in. */}
      <path
        d="M20 3.2 6.6 8.1v11.4c0 8.2 5.5 14.6 13.4 17.3 7.9-2.7 13.4-9.1 13.4-17.3V8.1L20 3.2Z"
        fill={shield}
      />
      {/* Pharmacy cross, knocked out of the shield. */}
      <path
        d="M17.4 11.4h5.2v5.1h5.1v5.2h-5.1v5.1h-5.2v-5.1h-5.1v-5.2h5.1v-5.1Z"
        fill={cross}
      />
      {/* Verification seal. */}
      <circle cx="30.2" cy="29.4" r="7.2" fill={seal} stroke={tone === 'dark' ? brand[900] : '#FFFFFF'} strokeWidth="2.2" />
      <path
        d="m27 29.5 2.2 2.2 4.2-4.5"
        fill="none"
        stroke={tone === 'dark' ? brand[900] : '#FFFFFF'}
        strokeWidth="2.2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </Box>
  )
}

/**
 * The mark with the wordmark beside it. `STOCK VERIFICATION` is set small and
 * wide so it reads as a descriptor rather than competing with the name.
 */
export function Logo({
  size = 32,
  tone = 'dark',
  showWordmark = true,
}: {
  size?: number
  tone?: 'dark' | 'light'
  showWordmark?: boolean
}) {
  const primary = tone === 'dark' ? '#FFFFFF' : brand[900]
  const secondary = tone === 'dark' ? 'rgba(255,255,255,0.5)' : 'text.secondary'

  return (
    <Stack direction="row" spacing={1.25} alignItems="center" sx={{ minWidth: 0 }}>
      <LogoMark size={size} tone={tone} />

      {showWordmark ? (
        <Box sx={{ minWidth: 0 }}>
          <Typography
            component="span"
            sx={{
              display: 'block',
              color: primary,
              fontWeight: 700,
              fontSize: size >= 34 ? '1.0625rem' : '0.9688rem',
              lineHeight: 1.15,
              letterSpacing: '-0.015em',
              whiteSpace: 'nowrap',
            }}
          >
            Pharma
            <Box component="span" sx={{ color: brand.accent }}>
              Verify
            </Box>
          </Typography>
          <Typography
            component="span"
            sx={{
              display: 'block',
              color: secondary,
              fontSize: '0.625rem',
              fontWeight: 600,
              letterSpacing: '0.11em',
              whiteSpace: 'nowrap',
            }}
          >
            STOCK VERIFICATION
          </Typography>
        </Box>
      ) : null}
    </Stack>
  )
}

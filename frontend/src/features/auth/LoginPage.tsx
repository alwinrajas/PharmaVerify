import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  IconButton,
  InputAdornment,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import VisibilityRoundedIcon from '@mui/icons-material/VisibilityRounded'
import VisibilityOffRoundedIcon from '@mui/icons-material/VisibilityOffRounded'
import LoginRoundedIcon from '@mui/icons-material/LoginRounded'
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined'
import MailOutlineRoundedIcon from '@mui/icons-material/MailOutlineRounded'
import LockOutlinedIcon from '@mui/icons-material/LockOutlined'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import MedicationRoundedIcon from '@mui/icons-material/MedicationRounded'
import Inventory2RoundedIcon from '@mui/icons-material/Inventory2Rounded'
import FactCheckRoundedIcon from '@mui/icons-material/FactCheckRounded'
import AdminPanelSettingsRoundedIcon from '@mui/icons-material/AdminPanelSettingsRounded'
import RuleRoundedIcon from '@mui/icons-material/RuleRounded'
import { useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatNumber } from '@/utils/format'
import { Logo } from '@/components/Logo'
import { useAuth } from './AuthContext'
import { brand, neutral } from '@/theme'

/**
 * The sign-in screen.
 *
 * Two panels. The left one says what the application is for and shows four
 * live totals, which do more than decorate: they are the first evidence a
 * visitor gets that the system is connected and holding real data. The right
 * one does the work.
 *
 * The demo roles fill the form rather than signing in. Someone showing the
 * application to a client should be able to pick a role, be seen to type
 * nothing secret, and press the same button any other user presses.
 */

interface PublicStats {
  total_shops: number
  total_items: number
  stock_records: number
  completed_audits: number
}

const DEMO_ACCOUNTS = [
  {
    role: 'Administrator',
    scope: 'Full Access',
    email: 'admin@pharmaverify.com',
    icon: AdminPanelSettingsRoundedIcon,
  },
  { role: 'Supervisor', scope: 'Verification', email: 'supervisor@pharmaverify.com', icon: RuleRoundedIcon },
  { role: 'Shop User', scope: 'Branch', email: 'annanagar@pharmaverify.com', icon: StorefrontRoundedIcon },
] as const

const DEMO_PASSWORD = 'Pharma@2026'

export function LoginPage() {
  const { signIn, user, loading } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  /**
   * Reachable without a token and deliberately just four totals — no names, no
   * codes. A failure here must never keep anyone from signing in, so the panel
   * simply shows nothing rather than an error.
   */
  const { data: stats } = useQuery({
    queryKey: ['public-stats'],
    queryFn: async () => (await get<PublicStats>('/public/stats')).data,
    retry: false,
    staleTime: 60 * 1000,
  })

  if (!loading && user) {
    return <Navigate to="/" replace />
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setBusy(true)

    try {
      await signIn(email, password)
      navigate('/', { replace: true })
    } catch (caught) {
      setError(apiErrorMessage(caught, 'Sign in failed. Please check your details and try again.'))
    } finally {
      setBusy(false)
    }
  }

  const figures = [
    { label: 'Active Shops', value: stats?.total_shops, icon: StorefrontRoundedIcon },
    { label: 'Tracked Items', value: stats?.total_items, icon: MedicationRoundedIcon },
    { label: 'Stock Records', value: stats?.stock_records, icon: Inventory2RoundedIcon },
    { label: 'Audits Completed', value: stats?.completed_audits, icon: FactCheckRoundedIcon },
  ]

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', md: '1.05fr 1fr' },
        bgcolor: 'background.default',
      }}
    >
      {/* ---------------------------------------------------- brand panel */}
      <Box
        sx={{
          position: 'relative',
          display: { xs: 'none', md: 'flex' },
          flexDirection: 'column',
          justifyContent: 'space-between',
          p: { md: 5, lg: 6 },
          color: '#fff',
          overflow: 'hidden',
          backgroundColor: brand.deep,
          backgroundImage: 'url(/pharma-hero-bg.png)',
          backgroundSize: 'cover',
          backgroundPosition: 'center',
          // The photograph is atmosphere, not information. This keeps the
          // headline legible over whatever part of it happens to be behind.
          '&::before': {
            content: '""',
            position: 'absolute',
            inset: 0,
            background: `linear-gradient(155deg, ${brand.deep}F2 0%, ${brand.deep}E0 45%, ${brand[900]}F5 100%)`,
          },
          '& > *': { position: 'relative', zIndex: 1 },
        }}
      >
        <Box sx={{ mb: 2 }}>
          <Logo size={42} tone="dark" />
        </Box>

        <Box sx={{ maxWidth: 560 }}>
          <Typography
            sx={{
              fontSize: { md: '2.4rem', lg: '2.9rem' },
              fontWeight: 700,
              lineHeight: 1.12,
              letterSpacing: '-0.02em',
              textWrap: 'balance',
            }}
          >
            Every shelf accounted for.{' '}
            <Box component="span" sx={{ color: brand.accent }}>
              Zero room for error.
            </Box>
          </Typography>

          <Typography sx={{ mt: 2, color: 'rgba(255,255,255,0.76)', fontSize: '0.9375rem', lineHeight: 1.65, maxWidth: 520 }}>
            Import branch stock, collect counts from HHT devices, run instant variance analysis, and execute
            adjustments with complete audit compliance.
          </Typography>

          {/* Four totals, live. Evidence the system is connected. */}
          <Box
            sx={{
              mt: 4,
              display: 'grid',
              gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
              gap: 1.75,
              maxWidth: 560,
            }}
          >
            {figures.map((figure) => {
              const Icon = figure.icon

              return (
                <Box
                  key={figure.label}
                  sx={{
                    p: 2,
                    borderRadius: 2.5,
                    border: '1px solid rgba(255,255,255,0.12)',
                    bgcolor: 'rgba(255,255,255,0.055)',
                    backdropFilter: 'blur(6px)',
                  }}
                >
                  <Stack direction="row" spacing={1.25} alignItems="center" sx={{ mb: 1 }}>
                    <Icon sx={{ fontSize: 17, color: brand.accent }} />
                    <Typography sx={{ fontSize: '0.8125rem', color: 'rgba(255,255,255,0.78)', fontWeight: 500 }}>
                      {figure.label}
                    </Typography>
                  </Stack>

                  {figure.value === undefined ? (
                    // Waiting, rather than asserting a zero that may be wrong.
                    <Box sx={{ height: 26, width: 76, borderRadius: 1, bgcolor: 'rgba(255,255,255,0.09)' }} />
                  ) : (
                    <Typography
                      sx={{ fontSize: '1.5rem', fontWeight: 700, lineHeight: 1.1, fontVariantNumeric: 'tabular-nums' }}
                    >
                      {formatNumber(figure.value)}
                    </Typography>
                  )}
                </Box>
              )
            })}
          </Box>
        </Box>

        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ pt: 3 }}>
          <Typography sx={{ fontSize: '0.75rem', color: 'rgba(255,255,255,0.45)' }}>
            © 2026 PharmaVerify • Vertical Lines
          </Typography>
          <Stack direction="row" spacing={0.85} alignItems="center">
            <Box sx={{ width: 7, height: 7, borderRadius: '50%', bgcolor: brand.accent }} />
            <Typography sx={{ fontSize: '0.75rem', color: 'rgba(255,255,255,0.62)' }}>System Online</Typography>
          </Stack>
        </Stack>
      </Box>

      {/* ---------------------------------------------------- sign-in panel */}
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', p: { xs: 3, sm: 5 } }}>
        <Box sx={{ width: '100%', maxWidth: 420 }}>
          <Box sx={{ mb: 3, display: { md: 'none' } }}>
            <Logo size={34} tone="light" />
          </Box>

          <Typography variant="h2">Sign in</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75, mb: 3 }}>
            Access your workspace or choose a demo role.
          </Typography>

          {error ? (
            <Alert severity="error" sx={{ mb: 2.5 }}>
              {error}
            </Alert>
          ) : null}

          {/* Filling the form, never signing in. The presenter is seen to use
              the same button everybody else uses. */}
          <Typography
            sx={{
              fontSize: '0.6875rem',
              letterSpacing: '0.12em',
              textTransform: 'uppercase',
              color: 'text.secondary',
              fontWeight: 600,
              mb: 1.25,
            }}
          >
            Quick demo access
          </Typography>

          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 1, mb: 2.75 }}>
            {DEMO_ACCOUNTS.map((account) => {
              const Icon = account.icon
              const active = email === account.email

              return (
                <Chip
                  key={account.email}
                  onClick={() => {
                    setEmail(account.email)
                    setPassword(DEMO_PASSWORD)
                    setError(null)
                  }}
                  icon={<Icon sx={{ fontSize: 17 }} />}
                  label={
                    <Stack direction="row" spacing={0.75} alignItems="center">
                      <Box component="span" sx={{ fontWeight: 600 }}>
                        {account.role}
                      </Box>
                      <Box
                        component="span"
                        sx={{
                          fontSize: '0.6875rem',
                          px: 0.65,
                          py: 0.15,
                          borderRadius: 1,
                          bgcolor: active ? 'rgba(255,255,255,0.22)' : neutral[100],
                          color: active ? '#fff' : 'text.secondary',
                        }}
                      >
                        {account.scope}
                      </Box>
                    </Stack>
                  }
                  sx={{
                    height: 36,
                    borderRadius: 2,
                    px: 0.5,
                    bgcolor: active ? brand[600] : 'transparent',
                    color: active ? '#fff' : 'text.primary',
                    border: 1,
                    borderColor: active ? brand[600] : 'divider',
                    '& .MuiChip-icon': { color: active ? '#fff' : brand[600] },
                    '&:hover': { bgcolor: active ? brand[700] : neutral[50] },
                  }}
                />
              )
            })}
          </Stack>

          <Box component="form" onSubmit={handleSubmit}>
            <Stack spacing={2.25}>
              <TextField
                label="Email address"
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="name@pharmaverify.com"
                autoComplete="username"
                autoFocus
                required
                fullWidth
                slotProps={{
                  input: {
                    startAdornment: (
                      <InputAdornment position="start">
                        <MailOutlineRoundedIcon sx={{ fontSize: 18, color: neutral[400] }} />
                      </InputAdornment>
                    ),
                  },
                }}
              />

              <TextField
                label="Password"
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
                fullWidth
                slotProps={{
                  input: {
                    startAdornment: (
                      <InputAdornment position="start">
                        <LockOutlinedIcon sx={{ fontSize: 18, color: neutral[400] }} />
                      </InputAdornment>
                    ),
                    endAdornment: (
                      <InputAdornment position="end">
                        <Tooltip title={showPassword ? 'Hide password' : 'Show password'}>
                          <IconButton
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            onClick={() => setShowPassword((current) => !current)}
                            edge="end"
                            size="small"
                          >
                            {showPassword ? (
                              <VisibilityOffRoundedIcon fontSize="small" />
                            ) : (
                              <VisibilityRoundedIcon fontSize="small" />
                            )}
                          </IconButton>
                        </Tooltip>
                      </InputAdornment>
                    ),
                  },
                }}
              />

              <Button
                type="submit"
                variant="contained"
                size="large"
                fullWidth
                disabled={busy}
                startIcon={busy ? <CircularProgress size={18} color="inherit" /> : <LoginRoundedIcon />}
                sx={{
                  minHeight: 48,
                  borderRadius: 2.5,
                  fontSize: '0.9375rem',
                  fontWeight: 700,
                  bgcolor: brand[600],
                  boxShadow: `0 6px 16px ${brand[600]}30`,
                  transition: 'all 0.2s ease',
                  '&:hover': {
                    bgcolor: brand[700],
                    boxShadow: `0 8px 22px ${brand[700]}40`,
                    transform: 'translateY(-1px)',
                  },
                  '&:active': { transform: 'translateY(0)' },
                  // The lift is decoration; anyone who has asked for less
                  // movement gets the colour change on its own.
                  '@media (prefers-reduced-motion: reduce)': {
                    transition: 'none',
                    '&:hover': { transform: 'none' },
                  },
                }}
              >
                {busy ? 'Verifying…' : 'Sign in'}
              </Button>
            </Stack>
          </Box>

          {/* Quiet on purpose — there to reassure somebody typing a password,
              not to be read first. */}
          <Stack
            direction="row"
            spacing={0.75}
            alignItems="center"
            justifyContent="center"
            sx={{ mt: 3.5, pt: 2.5, borderTop: `1px solid ${neutral[100]}` }}
          >
            <ShieldOutlinedIcon sx={{ fontSize: 14, color: neutral[400] }} />
            <Typography sx={{ color: neutral[400], fontSize: '0.6875rem', fontWeight: 500 }}>
              256-bit TLS • SOC 2 Compliant
            </Typography>
          </Stack>
        </Box>
      </Box>
    </Box>
  )
}

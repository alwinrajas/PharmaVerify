import {
  Alert,
  Box,
  Button,
  CircularProgress,
  IconButton,
  InputAdornment,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import VisibilityRoundedIcon from '@mui/icons-material/VisibilityRounded'
import VisibilityOffRoundedIcon from '@mui/icons-material/VisibilityOffRounded'
import LoginRoundedIcon from '@mui/icons-material/LoginRounded'
import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { apiErrorMessage } from '@/services/apiClient'
import { useAuth } from './AuthContext'

export function LoginPage() {
  const { signIn, user, loading } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

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

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', md: '1.05fr 1fr' },
        bgcolor: 'background.default',
      }}
    >
      {/* Brand panel */}
      <Box
        sx={{
          display: { xs: 'none', md: 'flex' },
          flexDirection: 'column',
          justifyContent: 'space-between',
          p: 6,
          color: '#fff',
          background: 'linear-gradient(150deg, #0B3B31 0%, #0F5D4C 58%, #14705B 100%)',
        }}
      >
        <Stack direction="row" spacing={2} alignItems="center">
          <Box component="img" src="/pharmaverify.svg" alt="" sx={{ width: 44, height: 44, borderRadius: 2.5 }} />
          <Box>
            <Typography sx={{ fontWeight: 700, fontSize: '1.25rem' }}>PharmaVerify</Typography>
            <Typography sx={{ color: 'rgba(255,255,255,0.6)', fontSize: '0.75rem', letterSpacing: '0.08em' }}>
              PHARMACY STOCK VERIFICATION
            </Typography>
          </Box>
        </Stack>

        <Box sx={{ maxWidth: 460 }}>
          <Typography sx={{ fontSize: '2rem', fontWeight: 700, lineHeight: 1.25, letterSpacing: '-0.02em' }}>
            Every shelf accounted for.
          </Typography>
          <Typography sx={{ mt: 2, color: 'rgba(255,255,255,0.76)', fontSize: '0.9375rem', lineHeight: 1.7 }}>
            Import system stock, receive completed counts from your HHT devices, review the variance and post the
            adjustment — with a full record of who changed what.
          </Typography>

          <Stack spacing={1.25} sx={{ mt: 4 }}>
            {[
              'Stock imported per shop, replacing the previous file',
              'Counts identified by shop, device and audit number',
              'Adjustments posted immediately, never queued for approval',
              'Reports exported to Excel and PDF, shared to OneDrive on request',
            ].map((line) => (
              <Stack key={line} direction="row" spacing={1.25} alignItems="flex-start">
                <Box
                  sx={{
                    width: 6,
                    height: 6,
                    borderRadius: '50%',
                    bgcolor: '#4FD1A5',
                    mt: '7px',
                    flexShrink: 0,
                  }}
                />
                <Typography sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.875rem' }}>{line}</Typography>
              </Stack>
            ))}
          </Stack>
        </Box>

        <Typography sx={{ color: 'rgba(255,255,255,0.42)', fontSize: '0.75rem' }}>
          © {new Date().getFullYear()} PharmaVerify
        </Typography>
      </Box>

      {/* Sign-in panel */}
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', p: { xs: 3, sm: 6 } }}>
        <Paper
          variant="outlined"
          sx={{ p: { xs: 3, sm: 4.5 }, width: '100%', maxWidth: 424, borderRadius: 3.5 }}
        >
          <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mb: 3, display: { md: 'none' } }}>
            <Box component="img" src="/pharmaverify.svg" alt="" sx={{ width: 36, height: 36, borderRadius: 2 }} />
            <Typography variant="h4">PharmaVerify</Typography>
          </Stack>

          <Typography variant="h2">Sign in</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75, mb: 3 }}>
            Use your PharmaVerify account to continue.
          </Typography>

          {error ? (
            <Alert severity="error" sx={{ mb: 2.5 }}>
              {error}
            </Alert>
          ) : null}

          <Box component="form" onSubmit={handleSubmit}>
            <Stack spacing={2.25}>
              <TextField
                label="Email address"
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="username"
                autoFocus
                required
                fullWidth
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
                    endAdornment: (
                      <InputAdornment position="end">
                        <IconButton
                          onClick={() => setShowPassword((current) => !current)}
                          edge="end"
                          size="small"
                          aria-label={showPassword ? 'Hide password' : 'Show password'}
                        >
                          {showPassword ? (
                            <VisibilityOffRoundedIcon fontSize="small" />
                          ) : (
                            <VisibilityRoundedIcon fontSize="small" />
                          )}
                        </IconButton>
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
                startIcon={busy ? <CircularProgress size={17} color="inherit" /> : <LoginRoundedIcon />}
                sx={{ minHeight: 46 }}
              >
                {busy ? 'Signing in…' : 'Sign in'}
              </Button>
            </Stack>
          </Box>

          <Box sx={{ mt: 3.5, p: 2, borderRadius: 2.5, bgcolor: 'rgba(15,93,76,0.045)', border: 1, borderColor: 'divider' }}>
            <Typography variant="subtitle2" sx={{ mb: 1, color: 'text.primary' }}>
              Demonstration accounts
            </Typography>

            <Stack spacing={0.75}>
              {[
                { role: 'Administrator', email: 'admin@pharmaverify.com' },
                { role: 'Supervisor', email: 'supervisor@pharmaverify.com' },
                { role: 'Shop User', email: 'annanagar@pharmaverify.com' },
              ].map((account) => (
                <Stack
                  key={account.email}
                  direction="row"
                  justifyContent="space-between"
                  alignItems="center"
                  spacing={1}
                >
                  <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                    {account.role}
                  </Typography>
                  <Button
                    size="small"
                    onClick={() => {
                      setEmail(account.email)
                      setPassword('Pharma@2026')
                    }}
                    sx={{ fontSize: '0.75rem', minHeight: 24, py: 0 }}
                  >
                    {account.email}
                  </Button>
                </Stack>
              ))}
            </Stack>

            <Typography variant="caption" sx={{ display: 'block', mt: 1 }}>
              Password for all demonstration accounts: <strong>Pharma@2026</strong>
            </Typography>
          </Box>
        </Paper>
      </Box>
    </Box>
  )
}

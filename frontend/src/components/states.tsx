import { Box, Button, CircularProgress, Typography } from '@mui/material'
import InboxRoundedIcon from '@mui/icons-material/InboxRounded'
import ReportProblemRoundedIcon from '@mui/icons-material/ReportProblemRounded'
import type { ReactNode } from 'react'

/** Shown wherever a list, table or panel has nothing to display. */
export function EmptyState({
  title = 'No records found',
  description = 'Try adjusting your search or filters.',
  action,
  icon,
  compact = false,
}: {
  title?: string
  description?: string
  action?: ReactNode
  icon?: ReactNode
  compact?: boolean
}) {
  return (
    <Box
      sx={{
        py: compact ? 4 : 7,
        px: 3,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        textAlign: 'center',
        gap: 1,
      }}
    >
      <Box
        sx={{
          width: 52,
          height: 52,
          borderRadius: '50%',
          display: 'grid',
          placeItems: 'center',
          bgcolor: 'rgba(15, 93, 76, 0.07)',
          color: 'primary.main',
          mb: 0.5,
        }}
      >
        {icon ?? <InboxRoundedIcon />}
      </Box>

      <Typography variant="subtitle1">{title}</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 420 }}>
        {description}
      </Typography>

      {action ? <Box sx={{ mt: 1.5 }}>{action}</Box> : null}
    </Box>
  )
}

/** Shown when a request failed. Always a readable sentence, never a trace. */
export function ErrorState({
  message = 'Something went wrong while loading this information.',
  onRetry,
  compact = false,
}: {
  message?: string
  onRetry?: () => void
  compact?: boolean
}) {
  return (
    <Box
      sx={{
        py: compact ? 4 : 7,
        px: 3,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        textAlign: 'center',
        gap: 1,
      }}
    >
      <Box
        sx={{
          width: 52,
          height: 52,
          borderRadius: '50%',
          display: 'grid',
          placeItems: 'center',
          bgcolor: 'rgba(179, 38, 30, 0.08)',
          color: 'error.main',
          mb: 0.5,
        }}
      >
        <ReportProblemRoundedIcon />
      </Box>

      <Typography variant="subtitle1">Unable to load</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 460 }}>
        {message}
      </Typography>

      {onRetry ? (
        <Button size="small" variant="outlined" onClick={onRetry} sx={{ mt: 1.5 }}>
          Try again
        </Button>
      ) : null}
    </Box>
  )
}

/** Full-panel loading indicator for first paint. */
export function LoadingState({ label = 'Loading…', height = 220 }: { label?: string; height?: number | string }) {
  return (
    <Box sx={{ height, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1.5 }}>
      <CircularProgress size={28} />
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
    </Box>
  )
}

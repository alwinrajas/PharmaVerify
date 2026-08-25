import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Typography,
} from '@mui/material'
import CloseRoundedIcon from '@mui/icons-material/CloseRounded'
import type { ReactNode } from 'react'

/**
 * The shell every form dialog in the application uses: same header, same
 * footer, same error placement, same busy behaviour.
 */
export function FormDialog({
  open,
  title,
  description,
  onClose,
  onSubmit,
  submitLabel = 'Save',
  cancelLabel = 'Cancel',
  busy = false,
  error = null,
  maxWidth = 'sm',
  children,
  submitColor = 'primary',
  submitDisabled = false,
}: {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  onSubmit: () => void
  submitLabel?: string
  cancelLabel?: string
  busy?: boolean
  error?: string | null
  maxWidth?: 'xs' | 'sm' | 'md' | 'lg'
  children: ReactNode
  submitColor?: 'primary' | 'error' | 'warning'
  submitDisabled?: boolean
}) {
  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth={maxWidth}>
      <DialogTitle sx={{ pr: 6 }}>
        {title}
        {description ? (
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, fontWeight: 400 }}>
            {description}
          </Typography>
        ) : null}

        <IconButton
          onClick={onClose}
          disabled={busy}
          size="small"
          sx={{ position: 'absolute', right: 12, top: 12, color: 'text.secondary' }}
        >
          <CloseRoundedIcon fontSize="small" />
        </IconButton>
      </DialogTitle>

      <DialogContent dividers>
        {error ? (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        ) : null}

        <Box
          component="form"
          onSubmit={(event) => {
            event.preventDefault()
            onSubmit()
          }}
          sx={{ pt: 0.5 }}
        >
          {children}
        </Box>
      </DialogContent>

      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={busy} color="inherit">
          {cancelLabel}
        </Button>
        <Button
          onClick={onSubmit}
          variant="contained"
          color={submitColor}
          disabled={busy || submitDisabled}
          startIcon={busy ? <CircularProgress size={15} color="inherit" /> : undefined}
        >
          {submitLabel}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

/** Confirmation before anything destructive or irreversible. */
export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  onConfirm,
  onClose,
  busy = false,
  severity = 'warning',
  detail,
}: {
  open: boolean
  title: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  onConfirm: () => void
  onClose: () => void
  busy?: boolean
  severity?: 'warning' | 'error' | 'info'
  detail?: ReactNode
}) {
  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{title}</DialogTitle>

      <DialogContent>
        <Typography variant="body2" color="text.secondary">
          {message}
        </Typography>
        {detail ? <Box sx={{ mt: 2 }}>{detail}</Box> : null}
      </DialogContent>

      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={busy} color="inherit">
          {cancelLabel}
        </Button>
        <Button
          onClick={onConfirm}
          variant="contained"
          color={severity === 'error' ? 'error' : 'primary'}
          disabled={busy}
          startIcon={busy ? <CircularProgress size={15} color="inherit" /> : undefined}
        >
          {confirmLabel}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

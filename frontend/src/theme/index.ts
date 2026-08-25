import { alpha, createTheme, type ThemeOptions } from '@mui/material/styles'

/**
 * The PharmaVerify design system.
 *
 * Everything visual is defined once here — colour, type, spacing, radius,
 * elevation and component defaults — so that every screen in the application
 * reads as one product rather than a collection of forms.
 */

export const palette = {
  // A calm clinical green: pharmacy without the cliché of a bright red cross.
  primary: '#0F5D4C',
  primaryLight: '#2E9E7E',
  primaryDark: '#094033',
  accent: '#1B6E9C',

  success: '#1B6E3C',
  warning: '#B26A00',
  error: '#B3261E',
  info: '#1B6E9C',

  ink: '#12201E',
  inkMuted: '#5F6B6A',
  line: '#DCE5E3',
  surface: '#FFFFFF',
  canvas: '#F4F7F6',
  sidebar: '#0B3B31',
  sidebarHover: '#12513F',
} as const

/** Colours used by status chips across every module. */
export const statusPalette: Record<string, { label: string; color: string; bg: string }> = {
  active: { label: 'Active', color: '#1B6E3C', bg: '#E4F3EA' },
  inactive: { label: 'Inactive', color: '#5F6B6A', bg: '#ECEFEE' },

  submitted: { label: 'Submitted', color: '#1B6E9C', bg: '#E3F0F7' },
  in_verification: { label: 'In Verification', color: '#B26A00', bg: '#FBF0DE' },
  verified: { label: 'Verified', color: '#1B6E3C', bg: '#E4F3EA' },
  adjusted: { label: 'Adjusted', color: '#0F5D4C', bg: '#DFEFEA' },
  closed: { label: 'Closed', color: '#5F6B6A', bg: '#ECEFEE' },

  pending: { label: 'Pending', color: '#B26A00', bg: '#FBF0DE' },
  not_adjusted: { label: 'Not Adjusted', color: '#5F6B6A', bg: '#ECEFEE' },
  not_verified: { label: 'Not Verified', color: '#5F6B6A', bg: '#ECEFEE' },
  recorded: { label: 'Recorded', color: '#1B6E9C', bg: '#E3F0F7' },

  completed: { label: 'Completed', color: '#1B6E3C', bg: '#E4F3EA' },
  completed_with_errors: { label: 'Completed with errors', color: '#B26A00', bg: '#FBF0DE' },
  processing: { label: 'Processing', color: '#1B6E9C', bg: '#E3F0F7' },
  failed: { label: 'Failed', color: '#B3261E', bg: '#FBE7E5' },

  accepted: { label: 'Accepted', color: '#1B6E3C', bg: '#E4F3EA' },
  duplicate_ignored: { label: 'Duplicate Ignored', color: '#B26A00', bg: '#FBF0DE' },
  rejected: { label: 'Rejected', color: '#B3261E', bg: '#FBE7E5' },

  not_uploaded: { label: 'Not Uploaded', color: '#5F6B6A', bg: '#ECEFEE' },
  uploading: { label: 'Uploading', color: '#1B6E9C', bg: '#E3F0F7' },
  uploaded: { label: 'Uploaded', color: '#1B6E3C', bg: '#E4F3EA' },

  partially_verified: { label: 'Partially Verified', color: '#B26A00', bg: '#FBF0DE' },
}

const themeOptions: ThemeOptions = {
  palette: {
    mode: 'light',
    primary: { main: palette.primary, light: palette.primaryLight, dark: palette.primaryDark, contrastText: '#fff' },
    secondary: { main: palette.accent, contrastText: '#fff' },
    success: { main: palette.success },
    warning: { main: palette.warning },
    error: { main: palette.error },
    info: { main: palette.info },
    background: { default: palette.canvas, paper: palette.surface },
    text: { primary: palette.ink, secondary: palette.inkMuted },
    divider: palette.line,
  },

  shape: { borderRadius: 10 },

  typography: {
    fontFamily: '"Inter", "Segoe UI", system-ui, -apple-system, sans-serif',
    h1: { fontSize: '1.75rem', fontWeight: 700, letterSpacing: '-0.02em' },
    h2: { fontSize: '1.5rem', fontWeight: 700, letterSpacing: '-0.015em' },
    h3: { fontSize: '1.25rem', fontWeight: 600 },
    h4: { fontSize: '1.125rem', fontWeight: 600 },
    h5: { fontSize: '1rem', fontWeight: 600 },
    h6: { fontSize: '0.9375rem', fontWeight: 600 },
    subtitle1: { fontSize: '0.9375rem', fontWeight: 600 },
    subtitle2: { fontSize: '0.8125rem', fontWeight: 600, color: palette.inkMuted },
    body1: { fontSize: '0.875rem' },
    body2: { fontSize: '0.8125rem' },
    caption: { fontSize: '0.75rem', color: palette.inkMuted },
    button: { textTransform: 'none', fontWeight: 600, letterSpacing: 0 },
  },

  components: {
    MuiCssBaseline: {
      styleOverrides: {
        '*::-webkit-scrollbar': { width: 10, height: 10 },
        '*::-webkit-scrollbar-thumb': { background: '#C3D0CD', borderRadius: 8, border: '2px solid transparent', backgroundClip: 'content-box' },
        '*::-webkit-scrollbar-thumb:hover': { background: '#A7B8B4', backgroundClip: 'content-box' },
      },
    },

    MuiPaper: {
      defaultProps: { elevation: 0 },
      styleOverrides: {
        root: { backgroundImage: 'none' },
        outlined: { borderColor: palette.line },
      },
    },

    MuiCard: {
      defaultProps: { elevation: 0, variant: 'outlined' },
      styleOverrides: {
        root: {
          borderColor: palette.line,
          borderRadius: 12,
          boxShadow: '0 1px 2px rgba(18, 32, 30, 0.04)',
        },
      },
    },

    MuiButton: {
      defaultProps: { disableElevation: true },
      styleOverrides: {
        root: { borderRadius: 9, paddingInline: 16, minHeight: 38 },
        sizeSmall: { minHeight: 32, paddingInline: 12, fontSize: '0.8125rem' },
        containedPrimary: {
          '&:hover': { backgroundColor: palette.primaryDark },
        },
      },
    },

    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          borderRadius: 9,
          backgroundColor: '#fff',
          '& fieldset': { borderColor: palette.line },
          '&:hover fieldset': { borderColor: '#B9C8C5' },
        },
        input: { fontSize: '0.875rem' },
      },
    },

    MuiInputLabel: { styleOverrides: { root: { fontSize: '0.875rem' } } },

    MuiTableCell: {
      styleOverrides: {
        root: {
          borderBottomColor: palette.line,
          fontSize: '0.8125rem',
          paddingTop: 10,
          paddingBottom: 10,
        },
        head: {
          fontWeight: 600,
          fontSize: '0.75rem',
          letterSpacing: '0.04em',
          textTransform: 'uppercase',
          color: palette.inkMuted,
          backgroundColor: '#FAFCFB',
          whiteSpace: 'nowrap',
        },
      },
    },

    MuiTableRow: {
      styleOverrides: {
        root: {
          '&:last-child td': { borderBottom: 0 },
          '&:hover': { backgroundColor: alpha(palette.primary, 0.03) },
        },
      },
    },

    MuiChip: {
      styleOverrides: {
        root: { borderRadius: 7, fontWeight: 600, fontSize: '0.75rem', height: 24 },
      },
    },

    MuiTooltip: {
      styleOverrides: {
        tooltip: { backgroundColor: palette.ink, fontSize: '0.75rem', borderRadius: 7, padding: '6px 10px' },
      },
    },

    MuiDialog: {
      styleOverrides: { paper: { borderRadius: 14 } },
    },

    MuiDialogTitle: {
      styleOverrides: { root: { fontSize: '1.0625rem', fontWeight: 700, paddingBottom: 8 } },
    },

    MuiTab: {
      styleOverrides: { root: { textTransform: 'none', fontWeight: 600, minHeight: 44 } },
    },

    MuiAlert: {
      styleOverrides: { root: { borderRadius: 10, fontSize: '0.8125rem' } },
    },

    MuiLinearProgress: {
      styleOverrides: { root: { borderRadius: 4, height: 6 } },
    },
  },
}

export const theme = createTheme(themeOptions)

import { alpha, createTheme, type ThemeOptions } from '@mui/material/styles'

/**
 * The PharmaVerify design system.
 *
 * Everything visual is defined once here — colour, type, spacing, radius,
 * density and component defaults — so that every screen reads as one product.
 *
 * The guiding idea is an instrument rather than a dashboard. Surfaces are
 * quiet, colour is reserved for meaning, and the typography exists to make
 * numbers comparable at a glance. Depth comes from the neutral ramp below
 * rather than from shadows or gradients.
 */

/**
 * Eleven neutrals, biased very slightly green so they sit with the brand
 * instead of fighting it. This ramp is what creates hierarchy; reach for it
 * before reaching for colour.
 */
export const neutral = {
  0: '#FFFFFF',
  50: '#F6F8F7',
  100: '#EDF1EF',
  200: '#DDE4E1',
  300: '#C2CBC8',
  400: '#97A29E',
  500: '#6B7975',
  600: '#4A5854',
  700: '#2E3D39',
  800: '#1B2926',
  900: '#0E1A17',
} as const

/** A calm clinical green: pharmacy without the cliché of a bright red cross. */
export const brand = {
  /** The mint used for the active nav item and the brand mark on dark ground. */
  accent: '#4FD1A5',
  /** The foot of the sidebar gradient — depth without a visible gradient. */
  deep: '#06251F',
  900: '#07322A',
  700: '#0C4B3E',
  600: '#0F5D4C',
  500: '#16755F',
  100: '#E3EFEB',
} as const

/**
 * Semantic colour. Foreground is dark enough to read on its own tint; the
 * tints are deliberately quiet, because a busy screen carries twenty of them.
 */
export const semantic = {
  success: { fg: '#14663A', bg: '#E8F2EC' },
  warning: { fg: '#8A5A00', bg: '#F7EFE0' },
  error: { fg: '#A32B22', bg: '#F8E9E7' },
  info: { fg: '#1A5F86', bg: '#E6EFF5' },
  neutral: { fg: neutral[600], bg: neutral[100] },
} as const

/**
 * Variance has its own semantics, and they are not the generic success and
 * error pair.
 *
 * Variance is `System - (Physical + Loose)`: a positive figure is a shortage,
 * a negative one an excess.
 *
 * Neither is good news. Excess stock on a shelf is a discrepancy that needs
 * investigating exactly as much as a shortfall does, and colouring it green
 * tells the user to ignore it. So: short is red, excess is blue — a
 * discrepancy, stated without alarm — and a match is neutral, the only
 * genuinely reassuring state and the one that should recede.
 */
export const variance = {
  short: '#A32B22',
  excess: '#1A5F86',
  matched: neutral[600],
} as const

/** Kept for the shape older code expects; every value now comes from the ramps. */
export const palette = {
  primary: brand[600],
  primaryLight: brand[500],
  primaryDark: brand[700],
  accent: semantic.info.fg,

  success: semantic.success.fg,
  warning: semantic.warning.fg,
  error: semantic.error.fg,
  info: semantic.info.fg,

  ink: neutral[900],
  inkMuted: neutral[600],
  line: neutral[200],
  surface: neutral[0],
  canvas: neutral[50],
  sidebar: brand[900],
  sidebarHover: brand[700],
} as const

/**
 * Every status in the application, in one place, so that "verified" looks the
 * same on the audit list, the verification screen and the reports.
 *
 * `tone` drives the dot-and-tint treatment in StatusBadge.
 */
type Tone = keyof typeof semantic

export const statusPalette: Record<string, { label: string; color: string; bg: string; tone: Tone }> = {}

const statusTones: Array<[string, string, Tone]> = [
  ['active', 'Active', 'success'],
  ['inactive', 'Inactive', 'neutral'],

    // The office sees an audit only once the server has accepted and stored
  // it, so "Received" is what this state means from here. The stored value
  // is still 'submitted' — this is the label, not the status.
  ['submitted', 'Received', 'info'],
  ['in_verification', 'In Verification', 'warning'],
  ['verified', 'Verified', 'success'],
  ['adjusted', 'Adjusted', 'success'],
  ['closed', 'Closed', 'neutral'],

  ['pending', 'Pending', 'warning'],
  ['not_adjusted', 'Not Adjusted', 'neutral'],
  ['not_verified', 'Not Verified', 'neutral'],
  ['recorded', 'Recorded', 'info'],

  ['completed', 'Completed', 'success'],
  ['completed_with_errors', 'Completed with errors', 'warning'],
  ['processing', 'Processing', 'info'],
  ['failed', 'Failed', 'error'],

  ['accepted', 'Accepted', 'success'],
  ['duplicate_ignored', 'Duplicate Ignored', 'warning'],
  ['rejected', 'Rejected', 'error'],

  ['not_uploaded', 'Not Uploaded', 'neutral'],
  ['uploading', 'Uploading', 'info'],
  ['uploaded', 'Uploaded', 'success'],

  ['partially_verified', 'Partially Verified', 'warning'],
]

for (const [key, label, tone] of statusTones) {
  statusPalette[key] = { label, color: semantic[tone].fg, bg: semantic[tone].bg, tone }
}

/**
 * Standard column widths, so the same kind of column is not three different
 * widths on three different screens.
 */
export const columnWidth = {
  code: 112,
  date: 148,
  datetime: 168,
  qty: 88,
  money: 104,
  status: 132,
  actions: 116,
} as const

/** One ring, used everywhere, so keyboard focus is never invisible. */
const focusRing = {
  outline: `2px solid ${brand[500]}`,
  outlineOffset: '2px',
}

const themeOptions: ThemeOptions = {
  palette: {
    mode: 'light',
    primary: { main: brand[600], light: brand[500], dark: brand[700], contrastText: '#fff' },
    secondary: { main: semantic.info.fg, contrastText: '#fff' },
    success: { main: semantic.success.fg },
    warning: { main: semantic.warning.fg },
    error: { main: semantic.error.fg },
    info: { main: semantic.info.fg },
    background: { default: neutral[50], paper: neutral[0] },
    text: { primary: neutral[900], secondary: neutral[600], disabled: neutral[400] },
    divider: neutral[200],
    grey: {
      50: neutral[50],
      100: neutral[100],
      200: neutral[200],
      300: neutral[300],
      400: neutral[400],
      500: neutral[500],
      600: neutral[600],
      700: neutral[700],
      800: neutral[800],
      900: neutral[900],
    },
  },

  shape: { borderRadius: 8 },

  typography: {
    fontFamily: '"Inter", "Segoe UI", system-ui, -apple-system, sans-serif',

    // Page titles at 1.5rem/600 rather than 1.75rem/700. Bold 28px reads as a
    // consumer app; the restraint is what makes this feel considered.
    h1: { fontSize: '1.5rem', fontWeight: 600, letterSpacing: '-0.022em', lineHeight: 1.22 },
    h2: { fontSize: '1.25rem', fontWeight: 600, letterSpacing: '-0.018em', lineHeight: 1.28 },
    h3: { fontSize: '1.0625rem', fontWeight: 600, letterSpacing: '-0.01em' },
    h4: { fontSize: '1rem', fontWeight: 600 },
    h5: { fontSize: '0.9375rem', fontWeight: 600 },
    h6: { fontSize: '0.875rem', fontWeight: 600 },
    subtitle1: { fontSize: '0.9375rem', fontWeight: 600 },
    subtitle2: { fontSize: '0.8125rem', fontWeight: 600, color: neutral[600] },
    body1: { fontSize: '0.875rem', lineHeight: 1.5 },
    body2: { fontSize: '0.8125rem', lineHeight: 1.45 },
    caption: { fontSize: '0.75rem', color: neutral[500], lineHeight: 1.4 },
    button: { textTransform: 'none', fontWeight: 600, letterSpacing: 0 },
  },

  components: {
    MuiCssBaseline: {
      styleOverrides: {
        // Keyboard focus is never invisible, on any surface.
        ':focus-visible': focusRing,

        '*::-webkit-scrollbar': { width: 10, height: 10 },
        '*::-webkit-scrollbar-thumb': {
          background: neutral[300],
          borderRadius: 8,
          border: '2px solid transparent',
          backgroundClip: 'content-box',
        },
        '*::-webkit-scrollbar-thumb:hover': { background: neutral[400], backgroundClip: 'content-box' },
      },
    },

    MuiPaper: {
      defaultProps: { elevation: 0 },
      styleOverrides: {
        root: { backgroundImage: 'none' },
        outlined: { borderColor: neutral[200] },
      },
    },

    MuiCard: {
      defaultProps: { elevation: 0, variant: 'outlined' },
      styleOverrides: {
        root: { borderColor: neutral[200], borderRadius: 10, boxShadow: 'none' },
      },
    },

    MuiButton: {
      defaultProps: { disableElevation: true },
      styleOverrides: {
        root: {
          borderRadius: 7,
          paddingInline: 14,
          minHeight: 36,
          '&:focus-visible': focusRing,
        },
        sizeSmall: { minHeight: 30, paddingInline: 11, fontSize: '0.8125rem' },
        containedPrimary: { '&:hover': { backgroundColor: brand[700] } },
        outlined: { borderColor: neutral[300] },
      },
    },

    MuiIconButton: {
      styleOverrides: {
        root: { borderRadius: 7, '&:focus-visible': focusRing },
      },
    },

    // One icon scale: 16 inline and in table rows, 18 in buttons, 20 in
    // navigation. Set through fontSize rather than left to inherit.
    MuiSvgIcon: {
      styleOverrides: {
        fontSizeSmall: { fontSize: 16 },
        fontSizeMedium: { fontSize: 18 },
        fontSizeLarge: { fontSize: 20 },
      },
    },

    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          borderRadius: 7,
          backgroundColor: neutral[0],
          fontSize: '0.875rem',
          '& fieldset': { borderColor: neutral[200] },
          '&:hover fieldset': { borderColor: neutral[300] },
        },
        input: { paddingTop: 9, paddingBottom: 9 },
        inputSizeSmall: { paddingTop: 8, paddingBottom: 8 },
      },
    },

    MuiInputLabel: { styleOverrides: { root: { fontSize: '0.875rem' } } },

    MuiTableCell: {
      styleOverrides: {
        // Comfortable density — master data and low-volume screens.
        root: {
          borderBottomColor: neutral[200],
          fontSize: '0.8125rem',
          lineHeight: 1.35,
          paddingTop: 9,
          paddingBottom: 9,
          paddingLeft: 12,
          paddingRight: 12,
        },

        // Compact density — the data-heavy screens. Padding tightens; the type
        // size does not, because density is not the same thing as shrinking.
        sizeSmall: { paddingTop: 6, paddingBottom: 6, paddingLeft: 10, paddingRight: 10 },

        head: {
          fontWeight: 600,
          fontSize: '0.6875rem',
          letterSpacing: '0.06em',
          textTransform: 'uppercase',
          color: neutral[500],
          backgroundColor: neutral[100],
          borderBottomColor: neutral[300],
          whiteSpace: 'nowrap',
        },

        // Figures in a column must line up. Inter's proportional digits make a
        // column of quantities ragged, which is the single most visible way a
        // data product looks unfinished.
        alignRight: {
          fontVariantNumeric: 'tabular-nums',
          fontFeatureSettings: '"tnum" 1',
        },
      },
    },

    MuiTableRow: {
      styleOverrides: {
        root: {
          '&:last-child td': { borderBottom: 0 },
          '&:hover': { backgroundColor: alpha(brand[600], 0.028) },
        },
      },
    },

    MuiTableSortLabel: {
      styleOverrides: {
        root: {
          '&:focus-visible': focusRing,
          '&.Mui-active': { color: neutral[900] },
        },
      },
    },

    MuiChip: {
      styleOverrides: {
        root: { borderRadius: 6, fontWeight: 500, fontSize: '0.75rem', height: 22 },
        label: { paddingInline: 8 },
      },
    },

    MuiTooltip: {
      styleOverrides: {
        tooltip: {
          backgroundColor: neutral[800],
          fontSize: '0.75rem',
          borderRadius: 6,
          padding: '6px 9px',
        },
      },
    },

    MuiDialog: {
      styleOverrides: { paper: { borderRadius: 12 } },
    },

    MuiDialogTitle: {
      styleOverrides: { root: { fontSize: '1.0625rem', fontWeight: 600, paddingBottom: 6 } },
    },

    MuiTab: {
      styleOverrides: {
        root: { textTransform: 'none', fontWeight: 600, minHeight: 40, '&:focus-visible': focusRing },
      },
    },

    MuiAlert: {
      styleOverrides: {
        root: { borderRadius: 8, fontSize: '0.8125rem' },
        standardInfo: { backgroundColor: semantic.info.bg, color: semantic.info.fg },
        standardSuccess: { backgroundColor: semantic.success.bg, color: semantic.success.fg },
        standardWarning: { backgroundColor: semantic.warning.bg, color: semantic.warning.fg },
        standardError: { backgroundColor: semantic.error.bg, color: semantic.error.fg },
      },
    },

    MuiLinearProgress: {
      styleOverrides: { root: { borderRadius: 3, height: 5 } },
    },

    MuiListItemButton: {
      styleOverrides: { root: { '&:focus-visible': focusRing } },
    },
  },
}

export const theme = createTheme(themeOptions)

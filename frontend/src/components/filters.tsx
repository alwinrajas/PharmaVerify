import {
  Box,
  Button,
  InputAdornment,
  MenuItem,
  Stack,
  TextField,
  type TextFieldProps,
} from '@mui/material'
import SearchRoundedIcon from '@mui/icons-material/SearchRounded'
import FilterAltOffRoundedIcon from '@mui/icons-material/FilterAltOffRounded'
import { useEffect, useState, type ReactNode } from 'react'

/** Search box with a short debounce so typing does not hammer the API. */
export function SearchBar({
  value,
  onChange,
  placeholder = 'Search…',
  width = 260,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  width?: number | string
}) {
  const [draft, setDraft] = useState(value)

  useEffect(() => setDraft(value), [value])

  useEffect(() => {
    if (draft === value) return

    const timer = window.setTimeout(() => onChange(draft), 350)

    return () => window.clearTimeout(timer)
  }, [draft, value, onChange])

  return (
    <TextField
      size="small"
      value={draft}
      onChange={(event) => setDraft(event.target.value)}
      placeholder={placeholder}
      sx={{ width }}
      slotProps={{
        input: {
          startAdornment: (
            <InputAdornment position="start">
              <SearchRoundedIcon fontSize="small" sx={{ color: 'text.secondary' }} />
            </InputAdornment>
          ),
        },
      }}
    />
  )
}

export interface FilterOption {
  value: string | number
  label: string
}

/** A compact dropdown filter with a built-in "all" option. */
export function SelectFilter({
  label,
  value,
  onChange,
  options,
  allLabel = 'All',
  width = 170,
  disabled = false,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  options: FilterOption[]
  allLabel?: string
  width?: number | string
  disabled?: boolean
}) {
  return (
    <TextField
      select
      size="small"
      label={label}
      value={value}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value)}
      sx={{ width }}
    >
      <MenuItem value="">{allLabel}</MenuItem>
      {options.map((option) => (
        <MenuItem key={option.value} value={String(option.value)}>
          {option.label}
        </MenuItem>
      ))}
    </TextField>
  )
}

export function DateFilter({
  label,
  value,
  onChange,
  width = 160,
  ...rest
}: {
  label: string
  value: string
  onChange: (value: string) => void
  width?: number | string
} & Omit<TextFieldProps, 'onChange' | 'value' | 'label'>) {
  return (
    <TextField
      {...rest}
      type="date"
      size="small"
      label={label}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      sx={{ width }}
      slotProps={{ inputLabel: { shrink: true } }}
    />
  )
}

/**
 * The filter strip that sits above a data table: filters on the left, actions
 * on the right, with a Clear button that only appears when something is set.
 */
export function FilterBar({
  children,
  actions,
  onClear,
  hasFilters = false,
}: {
  children: ReactNode
  actions?: ReactNode
  onClear?: () => void
  hasFilters?: boolean
}) {
  return (
    <Stack
      direction={{ xs: 'column', lg: 'row' }}
      spacing={1.5}
      justifyContent="space-between"
      alignItems={{ xs: 'stretch', lg: 'center' }}
    >
      <Stack direction="row" spacing={1.25} sx={{ flexWrap: 'wrap', gap: 1.25, alignItems: 'center' }}>
        {children}

        {hasFilters && onClear ? (
          <Button
            size="small"
            color="inherit"
            onClick={onClear}
            startIcon={<FilterAltOffRoundedIcon fontSize="small" />}
            sx={{ color: 'text.secondary' }}
          >
            Clear
          </Button>
        ) : null}
      </Stack>

      {actions ? (
        <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', justifyContent: { xs: 'flex-start', lg: 'flex-end' } }}>
          {actions}
        </Box>
      ) : null}
    </Stack>
  )
}

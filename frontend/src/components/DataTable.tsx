import {
  Box,
  Button,
  Checkbox,
  CircularProgress,
  ListItemText,
  Menu,
  MenuItem,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  Tooltip,
  Typography,
} from '@mui/material'
import CloseFullscreenRoundedIcon from '@mui/icons-material/CloseFullscreenRounded'
import OpenInFullRoundedIcon from '@mui/icons-material/OpenInFullRounded'
import ViewColumnRoundedIcon from '@mui/icons-material/ViewColumnRounded'
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react'
import { EmptyState, ErrorState } from './states'

export interface DataTableColumn<T> {
  key: string
  label: string
  /** Set when the server can sort by this column. */
  sortable?: boolean
  align?: 'left' | 'right' | 'center'
  width?: number | string
  render?: (row: T) => ReactNode
  /** Hide on narrow screens to keep the table readable. */
  hideBelow?: 'sm' | 'md' | 'lg'
  /** Never offered in the column menu — the column that identifies the row. */
  alwaysVisible?: boolean
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  rows: T[]
  rowKey: (row: T) => string | number

  loading?: boolean
  error?: string | null
  onRetry?: () => void

  /** Server-side pagination. */
  total?: number
  page?: number
  perPage?: number
  onPageChange?: (page: number) => void
  onPerPageChange?: (perPage: number) => void

  sortBy?: string
  sortDir?: 'asc' | 'desc'
  onSortChange?: (column: string, direction: 'asc' | 'desc') => void

  onRowClick?: (row: T) => void
  selectedKey?: string | number | null

  emptyTitle?: string
  emptyDescription?: string
  emptyAction?: ReactNode

  /** Rendered above the table: search, filters, action buttons. */
  toolbar?: ReactNode

  /**
   * `compact` tightens padding for the data-heavy screens. The type size is
   * the same in both — density is not the same thing as shrinking text.
   */
  density?: 'comfortable' | 'compact'
  /** Older name for `density: 'compact'`. */
  dense?: boolean

  /**
   * A row pinned directly beneath the header, for totals. Whoever is reading
   * a variance table needs the aggregate without scrolling to find it.
   */
  summary?: ReactNode

  /** Keeps the identifying column in view while the rest scrolls sideways. */
  freezeFirstColumn?: boolean

  /** Offers a menu for showing columns the viewport has hidden. */
  columnToggle?: boolean

  maxHeight?: number | string

  /**
   * Offers an "Expand table" control that fills the viewport with this table.
   *
   * For genuinely tabular screens. A card list or a summary panel gains
   * nothing from it and would only carry a button that does something odd.
   */
  focusable?: boolean

  /** Names the table in the expanded view's header. Falls back to "Table". */
  focusTitle?: string
}

/**
 * The one table the whole application uses.
 *
 * Search, filters and sorting are driven by the parent through props so the
 * table never owns query state, but every list screen behaves identically:
 * same header treatment, same empty state, same pagination, same loading
 * behaviour.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading = false,
  error = null,
  onRetry,
  total,
  page = 0,
  perPage = 25,
  onPageChange,
  onPerPageChange,
  sortBy,
  sortDir = 'asc',
  onSortChange,
  onRowClick,
  selectedKey = null,
  emptyTitle = 'No records found',
  emptyDescription = 'Try adjusting your search or filters.',
  emptyAction,
  toolbar,
  density,
  dense = false,
  summary,
  freezeFirstColumn = false,
  columnToggle = false,
  maxHeight,
  focusable = false,
  focusTitle,
}: DataTableProps<T>) {
  const compact = density ? density === 'compact' : dense
  const showPagination = typeof total === 'number' && onPageChange !== undefined

  // A column the reader has explicitly asked for beats the breakpoint rule;
  // one they have explicitly dismissed stays away at every width.
  const [override, setOverride] = useState<Record<string, boolean>>({})

  const hideable = useMemo(() => columns.filter((column) => !column.alwaysVisible), [columns])
  const revealed = useMemo(
    () => hideable.filter((column) => override[column.key] === true).length,
    [hideable, override],
  )

  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null)

  function handleSort(column: DataTableColumn<T>) {
    if (!column.sortable || !onSortChange) return

    const nextDirection = sortBy === column.key && sortDir === 'asc' ? 'desc' : 'asc'
    onSortChange(column.key, nextDirection)
  }

  /** Display rules for one column: user choice first, breakpoint second. */
  function visibility(column: DataTableColumn<T>) {
    const choice = override[column.key]

    if (choice === false) return { display: 'none' }
    if (choice === true) return undefined
    if (column.hideBelow) return { display: { xs: 'none', [column.hideBelow]: 'table-cell' } }

    return undefined
  }

  /**
   * Expanded mode.
   *
   * Deliberately a style change on this same element rather than a portal into
   * a Dialog. Moving the subtree would remount it, and a remount here costs
   * everything the operator has set up — filters, sort, page, column choices,
   * open menus — and fires the query again. Elevating in place keeps the React
   * tree byte-identical: the only thing that changes is where the box is
   * painted. That is also why no state had to be lifted or duplicated.
   */
  const [expanded, setExpanded] = useState(false)
  const expandButtonRef = useRef<HTMLButtonElement | null>(null)

  useEffect(() => {
    if (!expanded) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setExpanded(false)
      }
    }

    // Capture phase: a MUI Menu open inside the table would otherwise swallow
    // Escape, and the operator would press it expecting the table to close.
    document.addEventListener('keydown', onKeyDown, true)

    // The page behind must not scroll while a fixed overlay covers it.
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
    }
  }, [expanded])

  // Returning the operator to the control they pressed, rather than to the top
  // of the document.
  useEffect(() => {
    if (!expanded) expandButtonRef.current?.focus({ preventScroll: true })
  }, [expanded])

  function frozen(index: number) {
    if (!freezeFirstColumn || index !== 0) return undefined

    return {
      position: 'sticky' as const,
      left: 0,
      zIndex: 2,
      bgcolor: 'background.paper',
      borderRight: 1,
      borderColor: 'divider',
    }
  }

  return (
    <Paper
      variant="outlined"
      // aria-modal is not claimed: the page behind stays in the accessibility
      // tree and this is a workspace, not a dialog demanding a decision.
      role={expanded ? 'region' : undefined}
      aria-label={expanded ? `${focusTitle ?? 'Table'}, expanded view` : undefined}
      sx={{
        borderRadius: expanded ? 0 : 2,
        overflow: 'hidden',
        ...(expanded
          ? {
              position: 'fixed',
              inset: 0,
              zIndex: (theme) => theme.zIndex.modal,
              display: 'flex',
              flexDirection: 'column',
              bgcolor: 'background.paper',
              border: 0,
              // A short fade so the change of context registers. Skipped for
              // anyone who has asked their system for less motion.
              '@media (prefers-reduced-motion: no-preference)': {
                animation: 'pv-table-focus-in 140ms ease-out',
              },
              '@keyframes pv-table-focus-in': {
                from: { opacity: 0.6 },
                to: { opacity: 1 },
              },
            }
          : {}),
      }}
    >
      {expanded ? (
        <Box
          sx={{
            px: 2,
            py: 1.25,
            borderBottom: 1,
            borderColor: 'divider',
            bgcolor: 'grey.50',
            display: 'flex',
            alignItems: 'center',
            gap: 1.5,
            flexShrink: 0,
          }}
        >
          <Typography variant="subtitle2" sx={{ fontWeight: 700, flexGrow: 1, minWidth: 0 }} noWrap>
            {focusTitle ?? 'Table'}
          </Typography>
          <Typography variant="caption" sx={{ flexShrink: 0, display: { xs: 'none', sm: 'block' } }}>
            Press Esc to close
          </Typography>
        </Box>
      ) : null}

      {toolbar || columnToggle || focusable ? (
        <Box
          sx={{
            px: 2,
            py: 1.5,
            borderBottom: 1,
            borderColor: 'divider',
            bgcolor: 'grey.50',
            display: 'flex',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 1.5,
            flexShrink: 0,
          }}
        >
          <Box sx={{ flexGrow: 1, minWidth: 0 }}>{toolbar}</Box>

          {focusable ? (
            <Tooltip title={expanded ? 'Exit expanded view (Esc)' : 'Expand table to fill the screen'}>
              <Button
                ref={expandButtonRef}
                size="small"
                variant="outlined"
                aria-label={expanded ? 'Exit expanded table view' : 'Expand table'}
                aria-pressed={expanded}
                startIcon={
                  expanded ? (
                    <CloseFullscreenRoundedIcon fontSize="small" />
                  ) : (
                    <OpenInFullRoundedIcon fontSize="small" />
                  )
                }
                onClick={() => setExpanded((current) => !current)}
                sx={{ flexShrink: 0, minHeight: 36 }}
              >
                {expanded ? 'Exit' : 'Expand'}
              </Button>
            </Tooltip>
          ) : null}

          {columnToggle ? (
            <>
              <Button
                size="small"
                variant="outlined"
                startIcon={<ViewColumnRoundedIcon fontSize="small" />}
                onClick={(event) => setMenuAnchor(event.currentTarget)}
                sx={{ flexShrink: 0 }}
              >
                Columns{revealed > 0 ? ` (${revealed})` : ''}
              </Button>

              <Menu anchorEl={menuAnchor} open={Boolean(menuAnchor)} onClose={() => setMenuAnchor(null)}>
                {hideable.map((column) => {
                  const forced = override[column.key]
                  const checked = forced !== false

                  return (
                    <MenuItem
                      key={column.key}
                      dense
                      onClick={() =>
                        setOverride((current) => ({ ...current, [column.key]: !checked }))
                      }
                    >
                      <Checkbox size="small" checked={checked} sx={{ mr: 0.5, p: 0.5 }} />
                      <ListItemText
                        primary={column.label}
                        secondary={column.hideBelow ? 'Hidden on narrow screens' : undefined}
                        slotProps={{
                          primary: { fontSize: '0.8125rem' },
                          secondary: { fontSize: '0.6875rem' },
                        }}
                      />
                    </MenuItem>
                  )
                })}
              </Menu>
            </>
          ) : null}
        </Box>
      ) : null}

      {error ? (
        <ErrorState message={error} onRetry={onRetry} />
      ) : (
        <>
          <TableContainer
            sx={{
              position: 'relative',
              // Expanded, the workspace takes whatever the toolbars leave it
              // rather than a fixed guess, so the rows fill the screen and the
              // horizontal scroll stays inside this box — never on the page.
              ...(expanded
                ? { flex: 1, maxHeight: 'none', minHeight: 0 }
                : { maxHeight: maxHeight ?? 'calc(100vh - 300px)', minHeight: 240 }),
            }}
          >
            {loading ? (
              <Box
                sx={{
                  position: 'absolute',
                  inset: 0,
                  bgcolor: 'rgba(255,255,255,0.66)',
                  zIndex: 4,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <CircularProgress size={26} />
              </Box>
            ) : null}

            <Table stickyHeader size={compact ? 'small' : 'medium'}>
              <TableHead>
                <TableRow>
                  {columns.map((column, index) => (
                    <TableCell
                      key={column.key}
                      align={column.align ?? 'left'}
                      sx={{
                        width: column.width,
                        minWidth: column.width,
                        ...frozen(index),
                        ...(frozen(index) ? { zIndex: 4, bgcolor: 'grey.100' } : {}),
                        ...visibility(column),
                      }}
                    >
                      {column.sortable && onSortChange ? (
                        <TableSortLabel
                          active={sortBy === column.key}
                          direction={sortBy === column.key ? sortDir : 'asc'}
                          onClick={() => handleSort(column)}
                        >
                          {column.label}
                        </TableSortLabel>
                      ) : (
                        column.label
                      )}
                    </TableCell>
                  ))}
                </TableRow>

                {summary ? (
                  <TableRow>
                    <TableCell
                      colSpan={columns.length}
                      sx={{
                        position: 'sticky',
                        top: compact ? 30 : 36,
                        zIndex: 3,
                        bgcolor: 'grey.50',
                        borderBottom: 1,
                        borderColor: 'divider',
                        py: 0.75,
                      }}
                    >
                      {summary}
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableHead>

              <TableBody>
                {rows.length === 0 && !loading ? (
                  <TableRow hover={false}>
                    <TableCell colSpan={columns.length} sx={{ border: 0, p: 0 }}>
                      <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
                    </TableCell>
                  </TableRow>
                ) : (
                  rows.map((row) => {
                    const key = rowKey(row)
                    const selected = selectedKey !== null && selectedKey === key

                    return (
                      <TableRow
                        key={key}
                        hover
                        selected={selected}
                        onClick={onRowClick ? () => onRowClick(row) : undefined}
                        sx={onRowClick ? { cursor: 'pointer' } : undefined}
                      >
                        {columns.map((column, index) => (
                          <TableCell
                            key={column.key}
                            align={column.align ?? 'left'}
                            sx={{ ...frozen(index), ...visibility(column) }}
                          >
                            {column.render ? (
                              column.render(row)
                            ) : (
                              <Typography variant="body2" component="span">
                                {formatCell((row as Record<string, unknown>)[column.key])}
                              </Typography>
                            )}
                          </TableCell>
                        ))}
                      </TableRow>
                    )
                  })
                )}
              </TableBody>
            </Table>
          </TableContainer>

          {showPagination ? (
            <TablePagination
              component="div"
              count={total ?? 0}
              page={page}
              rowsPerPage={perPage}
              onPageChange={(_, next) => onPageChange?.(next)}
              onRowsPerPageChange={(event) => onPerPageChange?.(Number(event.target.value))}
              rowsPerPageOptions={[25, 50, 100, 200]}
              sx={{ borderTop: 1, borderColor: 'divider', minHeight: 46 }}
            />
          ) : null}
        </>
      )}
    </Paper>
  )
}

function formatCell(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}

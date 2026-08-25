import {
  Box,
  CircularProgress,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  Typography,
} from '@mui/material'
import type { ReactNode } from 'react'
import { EmptyState } from './EmptyState'
import { ErrorState } from './ErrorState'

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
  dense?: boolean
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
  dense = false,
}: DataTableProps<T>) {
  const showPagination = typeof total === 'number' && onPageChange !== undefined

  function handleSort(column: DataTableColumn<T>) {
    if (!column.sortable || !onSortChange) return

    const nextDirection = sortBy === column.key && sortDir === 'asc' ? 'desc' : 'asc'
    onSortChange(column.key, nextDirection)
  }

  return (
    <Paper variant="outlined" sx={{ borderRadius: 3, overflow: 'hidden' }}>
      {toolbar ? (
        <Box sx={{ p: 2, borderBottom: 1, borderColor: 'divider', bgcolor: '#FCFDFD' }}>{toolbar}</Box>
      ) : null}

      {error ? (
        <ErrorState message={error} onRetry={onRetry} />
      ) : (
        <>
          <TableContainer sx={{ position: 'relative', maxHeight: '68vh' }}>
            {loading ? (
              <Box
                sx={{
                  position: 'absolute',
                  inset: 0,
                  bgcolor: 'rgba(255,255,255,0.66)',
                  zIndex: 3,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <CircularProgress size={26} />
              </Box>
            ) : null}

            <Table stickyHeader size={dense ? 'small' : 'medium'}>
              <TableHead>
                <TableRow>
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      align={column.align ?? 'left'}
                      sx={{
                        width: column.width,
                        minWidth: column.width,
                        ...(column.hideBelow
                          ? { display: { xs: 'none', [column.hideBelow]: 'table-cell' } }
                          : {}),
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
                        {columns.map((column) => (
                          <TableCell
                            key={column.key}
                            align={column.align ?? 'left'}
                            sx={
                              column.hideBelow
                                ? { display: { xs: 'none', [column.hideBelow]: 'table-cell' } }
                                : undefined
                            }
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
              rowsPerPageOptions={[10, 25, 50, 100]}
              sx={{ borderTop: 1, borderColor: 'divider' }}
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

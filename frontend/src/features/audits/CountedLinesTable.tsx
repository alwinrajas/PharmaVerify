import { Box, CircularProgress, IconButton, Tooltip, Typography } from '@mui/material'
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { VarianceWord } from './VarianceWord'
import { formatQuantity } from '@/utils/format'
import type { AuditLine } from '@/types'

/**
 * What this audit holds so far, newest count first — the running record the
 * operator can check their own work against without leaving the screen.
 */
export function CountedLinesTable({
  lines,
  loading,
  error,
  onRetry,
  onRemove,
  removingId,
}: {
  lines: AuditLine[]
  loading: boolean
  error: string | null
  onRetry: () => void
  onRemove: (line: AuditLine) => void
  removingId: number | null
}) {
  const columns: DataTableColumn<AuditLine>[] = [
    {
      key: 'product_code',
      label: 'Product',
      render: (line) => (
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
            {line.description ?? '—'}
          </Typography>
          <Typography variant="caption" noWrap sx={{ display: 'block' }}>
            {[line.product_code, line.barcode].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    { key: 'batch', label: 'Batch', width: 110, render: (line) => line.batch || '—' },
    {
      key: 'system_qty',
      label: 'System',
      align: 'right',
      width: 90,
      render: (line) => formatQuantity(line.system_qty),
    },
    {
      key: 'physical_qty',
      label: 'Physical',
      align: 'right',
      width: 90,
      render: (line) => (
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {formatQuantity(line.physical_qty)}
        </Typography>
      ),
    },
    {
      key: 'loose_qty',
      label: 'Loose',
      align: 'right',
      width: 85,
      hideBelow: 'sm',
      render: (line) => (Number(line.loose_qty) === 0 ? '—' : formatQuantity(line.loose_qty)),
    },
    {
      key: 'variance_qty',
      label: 'Variance',
      align: 'right',
      width: 135,
      render: (line) => <VarianceWord value={line.variance_qty} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 56,
      alwaysVisible: true,
      render: (line) => (
        <Tooltip title="Remove this counted line">
          <span>
            <IconButton
              size="small"
              aria-label={`Remove ${line.description ?? line.product_code ?? 'this line'} from the count`}
              onClick={() => onRemove(line)}
              disabled={removingId === line.id}
            >
              {removingId === line.id ? <CircularProgress size={16} /> : <DeleteOutlineRoundedIcon fontSize="small" />}
            </IconButton>
          </span>
        </Tooltip>
      ),
    },
  ]

  return (
    <DataTable
      density="compact"
      columns={columns}
      rows={lines}
      rowKey={(line) => line.id}
      loading={loading}
      error={error}
      onRetry={onRetry}
      emptyTitle="Nothing counted yet"
      emptyDescription="Scan or look up a product above to start building this audit."
    />
  )
}

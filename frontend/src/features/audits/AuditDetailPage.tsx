import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  IconButton,
  Stack,
  Tooltip,
  Typography,
} from '@mui/material'
import ArrowBackRoundedIcon from '@mui/icons-material/ArrowBackRounded'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
import PlaylistAddCheckRoundedIcon from '@mui/icons-material/PlaylistAddCheckRounded'
import DoneAllRoundedIcon from '@mui/icons-material/DoneAllRounded'
import CloudUploadRoundedIcon from '@mui/icons-material/CloudUploadRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { ConfirmDialog } from '@/components/dialogs'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { LoadingState } from '@/components/states'
import { VarianceValue } from '@/components/VarianceValue'
import { AdjustDialog } from '@/features/adjustments/AdjustDialog'
import { useAuth } from '@/features/auth/AuthContext'
import { StockTakeDialog } from '@/features/stock-take/StockTakeDialog'
import { VerifyLineDialog } from '@/features/verification/VerifyLineDialog'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get, post } from '@/services/apiClient'
import { formatDate, formatDateTime, formatNumber, formatQuantity } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { Audit, AuditLine } from '@/types'

interface LineSummary {
  total_lines: number
  positive_variance: number
  negative_variance: number
  zero_variance: number
  pending_verification: number
  adjusted: number
  unknown_items: number
  net_variance: number
}

export function AuditDetailPage() {
  const { auditId } = useParams<{ auditId: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const { can } = useAuth()

  const table = useTableQuery({ sortBy: 'product_code', sortDir: 'asc' })

  const [editingLine, setEditingLine] = useState<AuditLine | null>(null)
  const [adjustLines, setAdjustLines] = useState<AuditLine[]>([])
  const [stockTakeLine, setStockTakeLine] = useState<AuditLine | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [verifyAllOpen, setVerifyAllOpen] = useState(false)
  const [generateOpen, setGenerateOpen] = useState(false)

  const auditQuery = useQuery({
    queryKey: ['audit', auditId],
    queryFn: async () => (await get<Audit>(`/audits/${auditId}`)).data,
    enabled: Boolean(auditId),
  })

  const linesQuery = useQuery({
    queryKey: ['audit-lines', auditId, table.params],
    queryFn: async () => get<AuditLine[]>(`/audits/${auditId}/lines`, table.params),
    enabled: Boolean(auditId),
  })

  const verifyAllMutation = useMutation({
    mutationFn: async () => post<Audit>(`/audits/${auditId}/verify`),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Audit verified.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['audit', auditId] })
      void queryClient.invalidateQueries({ queryKey: ['audit-lines', auditId] })
      void queryClient.invalidateQueries({ queryKey: ['audits'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] })
      setVerifyAllOpen(false)
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' })
      setVerifyAllOpen(false)
    },
  })

  const generateOutputMutation = useMutation({
    mutationFn: async () => post<{ id: number }>('/final-outputs', { audit_id: Number(auditId) }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Final output generated.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['final-outputs'] })
      setGenerateOpen(false)
      navigate('/final-output')
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' })
      setGenerateOpen(false)
    },
  })

  const audit = auditQuery.data
  const lines = linesQuery.data?.data ?? []
  const summary = (linesQuery.data?.meta as unknown as { summary?: LineSummary } | undefined)?.summary

  const selectedLines = lines.filter((line) => selected.includes(line.id))
  const adjustableSelected = selectedLines.filter(
    (line) => !line.is_unknown_item && line.adjustment_status !== 'adjusted' && Number(line.variance_qty) !== 0,
  )

  if (auditQuery.isLoading) return <LoadingState label="Loading audit…" height={400} />

  if (!audit) {
    return (
      <Alert severity="error">
        This audit could not be found. It may have been removed.
        <Button size="small" onClick={() => navigate('/audits')} sx={{ ml: 1 }}>
          Back to audits
        </Button>
      </Alert>
    )
  }

  function toggleSelected(id: number) {
    setSelected((current) =>
      current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id],
    )
  }

  const columns: DataTableColumn<AuditLine>[] = [
    {
      key: 'select',
      label: '',
      width: 48,
      render: (line) => (
        <Checkbox
          size="small"
          checked={selected.includes(line.id)}
          onChange={() => toggleSelected(line.id)}
          disabled={line.is_unknown_item || line.adjustment_status === 'adjusted'}
        />
      ),
    },
    {
      key: 'product_code',
      label: 'Product',
      sortable: true,
      render: (line) => (
        <Box>
          <Stack direction="row" spacing={0.75} alignItems="center">
            <Typography variant="body2" sx={{ fontWeight: 600 }}>
              {line.description ?? '—'}
            </Typography>
            {line.is_unknown_item ? (
              <Chip size="small" label="Not in stock file" sx={{ bgcolor: '#FBF0DE', color: '#B26A00' }} />
            ) : null}
          </Stack>
          <Typography variant="caption">
            {[line.product_code, line.barcode].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    { key: 'batch', label: 'Batch', sortable: true, width: 105 },
    {
      key: 'expiry_date',
      label: 'Expiry',
      sortable: true,
      width: 120,
      hideBelow: 'lg',
      render: (line) => formatDate(line.expiry_date),
    },
    { key: 'shelf_location', label: 'Shelf', width: 85, hideBelow: 'lg' },
    {
      key: 'system_qty',
      label: 'System',
      sortable: true,
      align: 'right',
      width: 95,
      render: (line) => formatQuantity(line.system_qty),
    },
    {
      key: 'physical_qty',
      label: 'Physical',
      sortable: true,
      align: 'right',
      width: 95,
      render: (line) => (
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {formatQuantity(line.physical_qty)}
        </Typography>
      ),
    },
    {
      key: 'variance_qty',
      label: 'Variance',
      sortable: true,
      align: 'right',
      width: 105,
      render: (line) => <VarianceValue value={line.variance_qty} />,
    },
    {
      key: 'verification_status',
      label: 'Verification',
      sortable: true,
      width: 125,
      render: (line) => <StatusBadge status={line.verification_status} />,
    },
    {
      key: 'adjustment_status',
      label: 'Adjustment',
      width: 130,
      render: (line) => <StatusBadge status={line.adjustment_status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 120,
      render: (line) => (
        <Stack direction="row" spacing={0.25} justifyContent="flex-end">
          {can(PERMISSIONS.verificationEdit) ? (
            <Tooltip title="Verify or correct this line">
              <IconButton size="small" onClick={() => setEditingLine(line)}>
                <EditRoundedIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          ) : null}

          {can(PERMISSIONS.adjustmentsCreate) && !line.is_unknown_item ? (
            <Tooltip
              title={
                line.adjustment_status === 'adjusted'
                  ? 'Already adjusted'
                  : Number(line.variance_qty) === 0
                    ? 'No variance to adjust'
                    : 'Post adjustment'
              }
            >
              <span>
                <IconButton
                  size="small"
                  disabled={line.adjustment_status === 'adjusted' || Number(line.variance_qty) === 0}
                  onClick={() => setAdjustLines([line])}
                >
                  <TuneRoundedIcon fontSize="small" />
                </IconButton>
              </span>
            </Tooltip>
          ) : null}

          {can(PERMISSIONS.stockTakeCreate) && line.is_unknown_item ? (
            <Tooltip title="Record as stock take">
              <IconButton size="small" onClick={() => setStockTakeLine(line)}>
                <PlaylistAddCheckRoundedIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          ) : null}
        </Stack>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title={`Audit ${audit.audit_number}`}
        description={`${audit.shop_code} — ${audit.shop_name} · Device ${audit.device_code}`}
        crumbs={[
          { label: 'Stock Verification' },
          { label: 'Stock Audit', to: '/audits' },
          { label: `Audit ${audit.audit_number}` },
        ]}
        actions={
          <>
            <Button variant="outlined" startIcon={<ArrowBackRoundedIcon />} onClick={() => navigate('/audits')}>
              Back
            </Button>

            {can(PERMISSIONS.verificationEdit) && (summary?.pending_verification ?? 0) > 0 ? (
              <Button variant="outlined" startIcon={<DoneAllRoundedIcon />} onClick={() => setVerifyAllOpen(true)}>
                Verify all lines
              </Button>
            ) : null}

            {can(PERMISSIONS.finalOutputGenerate) ? (
              <Button variant="contained" startIcon={<CloudUploadRoundedIcon />} onClick={() => setGenerateOpen(true)}>
                Generate Final Output
              </Button>
            ) : null}
          </>
        }
      />

      {/* Audit information */}
      <Card sx={{ mb: 2.5 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Box
            sx={{
              display: 'grid',
              gap: 2.5,
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)', xl: 'repeat(7, 1fr)' },
            }}
          >
            <Detail label="Audit Number" value={String(audit.audit_number)} strong />
            <Detail label="Shop" value={`${audit.shop_code}`} secondary={audit.shop_name} />
            <Detail label="Device" value={audit.device_code ?? '—'} />
            <Detail label="Counted By" value={audit.hht_user ?? '—'} />
            <Detail label="Audit Date" value={formatDate(audit.audit_date)} />
            <Detail label="Submitted" value={formatDateTime(audit.submitted_at)} />
            <Box>
              <Typography variant="caption" sx={{ display: 'block', mb: 0.5 }}>
                Status
              </Typography>
              <StatusBadge status={audit.status} />
              {audit.verified_by ? (
                <Typography variant="caption" sx={{ display: 'block', mt: 0.5 }}>
                  by {audit.verified_by}
                </Typography>
              ) : null}
            </Box>
          </Box>
        </CardContent>
      </Card>

      {/* Line summary */}
      {summary ? (
        <Box
          sx={{
            display: 'grid',
            gap: 2,
            gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' },
            mb: 2.5,
          }}
        >
          <MiniStat label="Lines counted" value={formatNumber(summary.total_lines)} />
          <MiniStat label="Short" value={formatNumber(summary.negative_variance)} tone="error" />
          <MiniStat label="Excess" value={formatNumber(summary.positive_variance)} tone="success" />
          <MiniStat label="Matched" value={formatNumber(summary.zero_variance)} />
          <MiniStat
            label="Pending verification"
            value={formatNumber(summary.pending_verification)}
            tone={summary.pending_verification > 0 ? 'warning' : undefined}
          />
          <MiniStat label="Net variance" value={formatQuantity(summary.net_variance)} tone={summary.net_variance < 0 ? 'error' : summary.net_variance > 0 ? 'success' : undefined} />
        </Box>
      ) : null}

      {summary && summary.unknown_items > 0 ? (
        <Alert severity="warning" sx={{ mb: 2.5 }}>
          {summary.unknown_items} counted item{summary.unknown_items === 1 ? '' : 's'} were not found in this shop's
          stock file. They cannot be adjusted — record them as a Stock Take instead.
        </Alert>
      ) : null}

      <DataTable
        columns={columns}
        rows={lines}
        rowKey={(line) => line.id}
        loading={linesQuery.isFetching}
        error={linesQuery.isError ? apiErrorMessage(linesQuery.error) : null}
        onRetry={() => void linesQuery.refetch()}
        total={linesQuery.data?.meta?.total ?? 0}
        page={table.page}
        perPage={table.perPage}
        onPageChange={table.setPage}
        onPerPageChange={table.setPerPage}
        sortBy={table.sortBy}
        sortDir={table.sortDir}
        onSortChange={table.setSort}
        emptyTitle="No lines match your filters"
        emptyDescription="Clear the filters to see the whole count."
        toolbar={
          <FilterBar
            hasFilters={table.hasFilters}
            onClear={table.clearFilters}
            actions={
              adjustableSelected.length > 0 && can(PERMISSIONS.adjustmentsCreate) ? (
                <Button
                  variant="contained"
                  size="small"
                  startIcon={<TuneRoundedIcon />}
                  onClick={() => setAdjustLines(adjustableSelected)}
                >
                  Adjust {adjustableSelected.length} selected
                </Button>
              ) : null
            }
          >
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search product or batch…" />
            <SelectFilter
              label="Variance"
              value={table.filters.variance ?? ''}
              onChange={(value) => table.setFilter('variance', value)}
              options={[
                { value: 'negative', label: 'Short (negative)' },
                { value: 'positive', label: 'Excess (positive)' },
                { value: 'zero', label: 'Matched (zero)' },
                { value: 'non_zero', label: 'Any variance' },
              ]}
              width={175}
            />
            <SelectFilter
              label="Verification"
              value={table.filters.verification_status ?? ''}
              onChange={(value) => table.setFilter('verification_status', value)}
              options={[
                { value: 'pending', label: 'Pending' },
                { value: 'verified', label: 'Verified' },
              ]}
              width={150}
            />
            <SelectFilter
              label="Adjustment"
              value={table.filters.adjustment_status ?? ''}
              onChange={(value) => table.setFilter('adjustment_status', value)}
              options={[
                { value: 'not_adjusted', label: 'Not adjusted' },
                { value: 'adjusted', label: 'Adjusted' },
              ]}
              width={155}
            />
          </FilterBar>
        }
      />

      <VerifyLineDialog
        line={editingLine}
        open={Boolean(editingLine)}
        onClose={() => setEditingLine(null)}
        onSaved={() => void queryClient.invalidateQueries({ queryKey: ['audit', auditId] })}
      />

      <AdjustDialog
        lines={adjustLines}
        open={adjustLines.length > 0}
        onClose={() => setAdjustLines([])}
        onPosted={() => {
          setSelected([])
          void queryClient.invalidateQueries({ queryKey: ['audit', auditId] })
        }}
      />

      <StockTakeDialog
        open={Boolean(stockTakeLine)}
        fromLine={stockTakeLine}
        onClose={() => setStockTakeLine(null)}
      />

      <ConfirmDialog
        open={verifyAllOpen}
        title="Verify every outstanding line?"
        message={`${summary?.pending_verification ?? 0} line(s) will be marked as verified and the audit status will move to Verified.`}
        confirmLabel="Verify all"
        busy={verifyAllMutation.isPending}
        onClose={() => setVerifyAllOpen(false)}
        onConfirm={() => verifyAllMutation.mutate()}
      />

      <ConfirmDialog
        open={generateOpen}
        title="Generate the final output?"
        message="A file is produced from this audit and stored in the application. It is not uploaded anywhere until you choose to share it."
        confirmLabel="Generate"
        busy={generateOutputMutation.isPending}
        onClose={() => setGenerateOpen(false)}
        onConfirm={() => generateOutputMutation.mutate()}
      />
    </Box>
  )
}

function Detail({
  label,
  value,
  secondary,
  strong = false,
}: {
  label: string
  value: string
  secondary?: string
  strong?: boolean
}) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" sx={{ display: 'block', mb: 0.25 }}>
        {label}
      </Typography>
      <Typography
        variant="body2"
        noWrap
        sx={{ fontWeight: strong ? 700 : 600, color: strong ? 'primary.main' : 'text.primary' }}
      >
        {value}
      </Typography>
      {secondary ? (
        <Typography variant="caption" noWrap sx={{ display: 'block' }}>
          {secondary}
        </Typography>
      ) : null}
    </Box>
  )
}

function MiniStat({
  label,
  value,
  tone,
}: {
  label: string
  value: string
  tone?: 'success' | 'error' | 'warning'
}) {
  const colour =
    tone === 'success' ? 'success.main' : tone === 'error' ? 'error.main' : tone === 'warning' ? 'warning.main' : 'text.primary'

  return (
    <Card>
      <CardContent sx={{ p: 1.75, '&:last-child': { pb: 1.75 } }}>
        <Typography variant="caption" sx={{ display: 'block' }}>
          {label}
        </Typography>
        <Typography sx={{ fontSize: '1.25rem', fontWeight: 700, color: colour }}>{value}</Typography>
      </CardContent>
    </Card>
  )
}

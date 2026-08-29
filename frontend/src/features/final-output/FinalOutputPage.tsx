import {
  Alert,
  AlertTitle,
  Box,
  Button,
  Chip,
  LinearProgress,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import CloudUploadRoundedIcon from '@mui/icons-material/CloudUploadRounded'
import DownloadRoundedIcon from '@mui/icons-material/DownloadRounded'
import ReplayRoundedIcon from '@mui/icons-material/ReplayRounded'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { ConfirmDialog, FormDialog } from '@/components/dialogs'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, download, get, post } from '@/services/apiClient'
import { formatDateTime, formatNumber } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { Audit, FinalOutput } from '@/types'
import { neutral } from '@/theme'

export function FinalOutputPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'generated_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()

  const [generateOpen, setGenerateOpen] = useState(false)
  const [auditId, setAuditId] = useState('')
  const [shareTarget, setShareTarget] = useState<FinalOutput | null>(null)
  const [generateError, setGenerateError] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['final-outputs', table.params],
    queryFn: async () => get<FinalOutput[]>('/final-outputs', table.params),
  })

  const { data: audits } = useQuery({
    queryKey: ['audits-for-output'],
    queryFn: async () => (await get<Audit[]>('/audits', { per_page: 100, sort_by: 'submitted_at', sort_dir: 'desc' })).data,
    enabled: generateOpen,
  })

  const driver = (data?.meta as unknown as { onedrive_driver?: string } | undefined)?.onedrive_driver

  const generateMutation = useMutation({
    mutationFn: async () => post<FinalOutput>('/final-outputs', { audit_id: Number(auditId) }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Final output generated.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['final-outputs'] })
      setGenerateOpen(false)
      setAuditId('')
    },
    onError: (caught) => setGenerateError(apiErrorMessage(caught)),
  })

  const shareMutation = useMutation({
    mutationFn: async (output: FinalOutput) => post<FinalOutput>(`/final-outputs/${output.id}/share-onedrive`),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Uploaded to OneDrive.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['final-outputs'] })
      setShareTarget(null)
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught, 'The upload to OneDrive could not be completed.'), { variant: 'error' })
      void queryClient.invalidateQueries({ queryKey: ['final-outputs'] })
      setShareTarget(null)
    },
  })

  async function handleDownload(output: FinalOutput) {
    try {
      await download(`/final-outputs/${output.id}/download`, {}, output.file_name)
    } catch (caught) {
      enqueueSnackbar(apiErrorMessage(caught, 'The file could not be downloaded.'), { variant: 'error' })
    }
  }

  const columns: DataTableColumn<FinalOutput>[] = [
    {
      key: 'generated_at',
      label: 'Generated',
      sortable: true,
      width: 180,
      render: (row) => (
        <Box>
          <Typography variant="body2">{formatDateTime(row.generated_at)}</Typography>
          <Typography variant="caption">{row.generated_by ?? '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'shop_code',
      label: 'Audit',
      width: 170,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.shop_code}
            {row.device_code ? ` · ${row.device_code}` : ''}
          </Typography>
          <Typography variant="caption">Audit {row.audit_number}</Typography>
        </Box>
      ),
    },
    {
      key: 'file_name',
      label: 'File',
      sortable: true,
      render: (row) => (
        <Typography variant="body2" noWrap sx={{ maxWidth: 330, fontFamily: 'ui-monospace, monospace', fontSize: '0.78rem' }}>
          {row.file_name}
        </Typography>
      ),
    },
    {
      key: 'record_count',
      label: 'Records',
      sortable: true,
      align: 'right',
      width: 95,
      render: (row) => formatNumber(row.record_count),
    },
    {
      key: 'verification_status',
      label: 'Verification',
      width: 155,
      hideBelow: 'lg',
      render: (row) => <StatusBadge status={row.verification_status} />,
    },
    {
      key: 'adjustment_status',
      label: 'Adjustment',
      width: 130,
      hideBelow: 'lg',
      render: (row) => <StatusBadge status={row.adjustment_status} />,
    },
    {
      key: 'onedrive_status',
      label: 'OneDrive',
      sortable: true,
      width: 160,
      render: (row) => (
        <Stack spacing={0.5}>
          <StatusBadge status={row.onedrive_status} />
          {row.upload_attempts > 0 ? (
            <Typography variant="caption">
              {row.upload_attempts} attempt{row.upload_attempts === 1 ? '' : 's'}
            </Typography>
          ) : null}
        </Stack>
      ),
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 230,
      render: (row) => (
        <Stack direction="row" spacing={0.75} justifyContent="flex-end">
          <Tooltip title="Download the file">
            <Button size="small" startIcon={<DownloadRoundedIcon fontSize="small" />} onClick={() => void handleDownload(row)}>
              Download
            </Button>
          </Tooltip>

          {can(PERMISSIONS.oneDriveShare) && row.onedrive_status !== 'uploaded' ? (
            <Button
              size="small"
              variant="contained"
              startIcon={row.onedrive_status === 'failed' ? <ReplayRoundedIcon fontSize="small" /> : <CloudUploadRoundedIcon fontSize="small" />}
              onClick={() => setShareTarget(row)}
            >
              {row.onedrive_status === 'failed' ? 'Retry' : 'Share'}
            </Button>
          ) : null}
        </Stack>
      ),
    },
  ]

  const failedRows = (data?.data ?? []).filter((row) => row.onedrive_status === 'failed')

  return (
    <Box>
      <PageHeader
        title="Final Output"
        description="The file that closes out an audit. Nothing leaves the application until you choose to share it."
        crumbs={[{ label: 'Output' }, { label: 'Final Output' }]}
        actions={
          can(PERMISSIONS.finalOutputGenerate) ? (
            <Button
              variant="contained"
              startIcon={<AddRoundedIcon />}
              onClick={() => {
                setGenerateError(null)
                setGenerateOpen(true)
              }}
            >
              Generate Final Output
            </Button>
          ) : null
        }
      />

      {driver === 'demo' ? (
        <Alert severity="info" sx={{ mb: 2.5 }}>
          <AlertTitle>OneDrive is running in demonstration mode</AlertTitle>
          Uploads are simulated against local storage so the complete flow can be shown. Supplying the Microsoft 365
          application details in the environment file switches this to real Microsoft Graph uploads without any code
          change.
        </Alert>
      ) : null}

      {failedRows.length > 0 ? (
        <Alert severity="error" sx={{ mb: 2.5 }}>
          {failedRows.length} upload{failedRows.length === 1 ? '' : 's'} did not complete. Use Retry on the row to try
          again — the most recent reason is: “{failedRows[0].last_error}”
        </Alert>
      ) : null}

      <DataTable
        focusable
        focusTitle="Final Output"
        density="compact"
        columnToggle
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(row) => row.id}
        loading={isFetching}
        error={isError ? apiErrorMessage(error) : null}
        onRetry={() => void refetch()}
        total={data?.meta?.total ?? 0}
        page={table.page}
        perPage={table.perPage}
        onPageChange={table.setPage}
        onPerPageChange={table.setPerPage}
        sortBy={table.sortBy}
        sortDir={table.sortDir}
        onSortChange={table.setSort}
        emptyTitle="No final output generated"
        emptyDescription="Generate the final output for a verified audit, then share it to OneDrive."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search file name…" />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={240}
            />
            <SelectFilter
              label="OneDrive"
              value={table.filters.onedrive_status ?? ''}
              onChange={(value) => table.setFilter('onedrive_status', value)}
              options={[
                { value: 'not_uploaded', label: 'Not uploaded' },
                { value: 'uploaded', label: 'Uploaded' },
                { value: 'failed', label: 'Failed' },
              ]}
              width={165}
            />
            <DateFilter
              label="From"
              value={table.filters.date_from ?? ''}
              onChange={(value) => table.setFilter('date_from', value)}
            />
            <DateFilter
              label="To"
              value={table.filters.date_to ?? ''}
              onChange={(value) => table.setFilter('date_to', value)}
            />
          </FilterBar>
        }
      />

      <FormDialog
        open={generateOpen}
        title="Generate Final Output"
        description="Produces the closing file for an audit. It is stored in the application and not uploaded anywhere."
        onClose={() => setGenerateOpen(false)}
        onSubmit={() => {
          setGenerateError(null)
          generateMutation.mutate()
        }}
        submitLabel="Generate"
        busy={generateMutation.isPending}
        error={generateError}
        submitDisabled={auditId === ''}
      >
        <TextField
          select
          label="Audit"
          value={auditId}
          onChange={(event) => setAuditId(event.target.value)}
          size="small"
          fullWidth
          required
          slotProps={{ select: { native: true } }}
        >
          <option value="">Select an audit…</option>
          {(audits ?? []).map((audit) => (
            <option key={audit.id} value={audit.id}>
              {audit.shop_code} · {audit.device_code} · Audit {audit.audit_number} ({audit.item_count} items,{' '}
              {audit.status})
            </option>
          ))}
        </TextField>
      </FormDialog>

      <ConfirmDialog
        open={Boolean(shareTarget)}
        title="Share to OneDrive?"
        message={`${shareTarget?.file_name ?? 'This file'} will be uploaded to OneDrive now. This is the only point at which the file leaves the application.`}
        confirmLabel={shareTarget?.onedrive_status === 'failed' ? 'Retry upload' : 'Share to OneDrive'}
        busy={shareMutation.isPending}
        onClose={() => setShareTarget(null)}
        onConfirm={() => shareTarget && shareMutation.mutate(shareTarget)}
        detail={
          shareMutation.isPending ? (
            <Box>
              <LinearProgress />
              <Typography variant="caption" sx={{ mt: 1, display: 'block' }}>
                Preparing and uploading the file…
              </Typography>
            </Box>
          ) : shareTarget?.last_error ? (
            <Alert severity="warning" sx={{ py: 0.5 }}>
              Previous attempt: {shareTarget.last_error}
            </Alert>
          ) : (
            <Stack direction="row" spacing={1}>
              <Chip size="small" label={`Driver: ${driver ?? 'demo'}`} sx={{ bgcolor: neutral[100] }} />
              <Chip size="small" label={`${shareTarget?.record_count ?? 0} records`} sx={{ bgcolor: neutral[100] }} />
            </Stack>
          )
        }
      />
    </Box>
  )
}

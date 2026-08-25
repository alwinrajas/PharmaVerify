import { Alert, Box, Button, IconButton, TextField, Tooltip, Typography } from '@mui/material'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { FormDialog } from '@/components/dialogs'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get, post, put } from '@/services/apiClient'
import { formatDateTime } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { Device } from '@/types'

const emptyForm = {
  shop_id: '',
  device_code: '',
  description: '',
  serial_number: '',
  status: 'active',
}

export function DevicesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'device_code', sortDir: 'asc' })
  const { data: shops } = useShopOptions()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Device | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['devices', table.params],
    queryFn: async () => get<Device[]>('/devices', table.params),
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...form, shop_id: Number(form.shop_id) }
      return editing ? put<Device>(`/devices/${editing.id}`, payload) : post<Device>('/devices', payload)
    },
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Device saved successfully.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
      void queryClient.invalidateQueries({ queryKey: ['device-options'] })
      setDialogOpen(false)
      setEditing(null)
    },
    onError: (caught) => setFormError(apiErrorMessage(caught)),
  })

  function openCreate() {
    setEditing(null)
    setForm({ ...emptyForm, shop_id: table.filters.shop_id ?? '' })
    setFormError(null)
    setDialogOpen(true)
  }

  function openEdit(device: Device) {
    setEditing(device)
    setForm({
      shop_id: String(device.shop_id),
      device_code: device.device_code,
      description: device.description ?? '',
      serial_number: device.serial_number ?? '',
      status: device.status,
    })
    setFormError(null)
    setDialogOpen(true)
  }

  const columns: DataTableColumn<Device>[] = [
    {
      key: 'device_code',
      label: 'Device',
      sortable: true,
      width: 140,
      render: (device) => (
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
          {device.device_code}
        </Typography>
      ),
    },
    {
      key: 'shop_code',
      label: 'Shop',
      render: (device) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {device.shop_code}
          </Typography>
          <Typography variant="caption">{device.shop_name}</Typography>
        </Box>
      ),
    },
    { key: 'description', label: 'Description', hideBelow: 'md' },
    {
      key: 'serial_number',
      label: 'Serial Number',
      width: 160,
      hideBelow: 'lg',
      render: (device) => (
        <Typography variant="body2" sx={{ fontFamily: 'ui-monospace, monospace', fontSize: '0.8125rem' }}>
          {device.serial_number ?? '—'}
        </Typography>
      ),
    },
    {
      key: 'last_submission_at',
      label: 'Last Submission',
      sortable: true,
      width: 175,
      render: (device) => formatDateTime(device.last_submission_at),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 110,
      render: (device) => <StatusBadge status={device.status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 60,
      render: (device) =>
        can(PERMISSIONS.devicesEdit) ? (
          <Tooltip title="Edit device">
            <IconButton size="small" onClick={() => openEdit(device)}>
              <EditRoundedIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        ) : null,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="HHT Devices"
        description="The handheld terminals that perform the physical count. A device code is unique within its shop."
        crumbs={[{ label: 'Master' }, { label: 'HHT Devices' }]}
        actions={
          can(PERMISSIONS.devicesCreate) ? (
            <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={openCreate}>
              Register Device
            </Button>
          ) : null
        }
      />

      <Alert severity="info" sx={{ mb: 2.5 }}>
        A submission is identified by <strong>Shop + Device + Audit Number</strong>. The same audit number can be in
        use on several devices of a shop at the same time, and each device advances its own audit sequence.
      </Alert>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(device) => device.id}
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
        emptyTitle="No devices registered"
        emptyDescription="Register the handheld terminals used by each shop."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search device or serial…" />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={260}
            />
            <SelectFilter
              label="Status"
              value={table.filters.status ?? ''}
              onChange={(value) => table.setFilter('status', value)}
              options={[
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
              ]}
            />
          </FilterBar>
        }
      />

      <FormDialog
        open={dialogOpen}
        title={editing ? `Edit ${editing.device_code}` : 'Register HHT Device'}
        description="Devices belong to one shop. The same code may be reused in a different shop."
        onClose={() => setDialogOpen(false)}
        onSubmit={() => {
          setFormError(null)
          saveMutation.mutate()
        }}
        submitLabel={editing ? 'Save changes' : 'Register device'}
        busy={saveMutation.isPending}
        error={formError}
        submitDisabled={form.shop_id === ''}
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField
            select
            label="Shop"
            value={form.shop_id}
            onChange={(event) => setForm({ ...form, shop_id: event.target.value })}
            required
            fullWidth
            size="small"
            sx={{ gridColumn: { sm: '1 / -1' } }}
            slotProps={{ select: { native: true } }}
          >
            <option value="">Select a shop…</option>
            {(shops ?? []).map((shop) => (
              <option key={shop.id} value={shop.id}>
                {shop.label}
              </option>
            ))}
          </TextField>

          <TextField
            label="Device Code"
            value={form.device_code}
            onChange={(event) => setForm({ ...form, device_code: event.target.value })}
            required
            fullWidth
            size="small"
            helperText="e.g. HHT-01"
          />
          <TextField
            select
            label="Status"
            value={form.status}
            onChange={(event) => setForm({ ...form, status: event.target.value })}
            fullWidth
            size="small"
            slotProps={{ select: { native: true } }}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </TextField>

          <TextField
            label="Description"
            value={form.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
            fullWidth
            size="small"
          />
          <TextField
            label="Serial Number"
            value={form.serial_number}
            onChange={(event) => setForm({ ...form, serial_number: event.target.value })}
            fullWidth
            size="small"
          />
        </Box>
      </FormDialog>
    </Box>
  )
}

import { Alert, Box, Button, Chip, IconButton, Stack, TextField, Tooltip, Typography } from '@mui/material'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import LinkRoundedIcon from '@mui/icons-material/LinkRounded'
import LinkOffRoundedIcon from '@mui/icons-material/LinkOffRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { ConfirmDialog, FormDialog } from '@/components/dialogs'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, destroy, get, post, put } from '@/services/apiClient'
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

/**
 * How recently a terminal was actually heard from.
 *
 * There is no live presence channel here, so this claims none: it reports the
 * last authenticated interaction and lets the age speak. A device is called
 * "Recently seen" rather than "Online" for exactly that reason — the server
 * knows when it last heard from the terminal, not whether it is switched on
 * now, and a pairing record is no evidence either way.
 */
function Presence({ lastSeenAt }: { lastSeenAt?: string | null }) {
  if (!lastSeenAt) {
    return (
      <Typography variant="body2" color="text.secondary">
        Never seen
      </Typography>
    )
  }

  const ageMs = Date.now() - new Date(lastSeenAt).getTime()
  const recent = ageMs < 10 * 60 * 1000

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.75 }}>
        <Box
          sx={{
            width: 8,
            height: 8,
            borderRadius: '50%',
            bgcolor: recent ? 'success.main' : 'text.disabled',
            flexShrink: 0,
          }}
        />
        <Typography variant="body2">{recent ? 'Recently seen' : 'Not seen recently'}</Typography>
      </Box>
      <Typography variant="caption" sx={{ display: 'block' }}>
        {formatDateTime(lastSeenAt)}
      </Typography>
    </Box>
  )
}

export function DevicesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'device_code', sortDir: 'asc' })
  const { data: shops } = useShopOptions()

  /** The code just issued, readable once. Null when no dialog is open. */
  const [unpairTarget, setUnpairTarget] = useState<Device | null>(null)
  const [pairing, setPairing] = useState<
    { pairing_code: string; expires_in_minutes: number; device_code: string; shop_code: string } | null
  >(null)

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

  /**
   * Issues a pairing code for a terminal.
   *
   * The code is readable exactly once — the server keeps only a hash — so it is
   * held in state and shown in a dialog rather than being fetched again when
   * the operator needs it. Pairing a terminal that is already paired revokes
   * its old token, which is the point when a device has been lost or reset.
   */
  const pairingMutation = useMutation({
    mutationFn: async (device: Device) =>
      post<{ pairing_code: string; expires_in_minutes: number; device_code: string; shop_code: string }>(
        `/devices/${device.id}/pairing-code`,
      ),
    onSuccess: (response) => {
      setPairing(response.data)
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  /**
   * Ends a terminal's pairing.
   *
   * Deletes the device's token server-side, so the handheld stops being able to
   * submit immediately — this is what to reach for when a terminal is lost,
   * reassigned to another shop, or handed back at the end of a contract.
   *
   * The handheld is not told. It discovers the pairing is gone the next time it
   * checks or tries to send, and reports it as needing to be paired again.
   * Counts already queued on it are not lost: they wait there until somebody
   * pairs it, which is the safe behaviour for a terminal that may be holding
   * the only copy of a count.
   */
  const unpairMutation = useMutation({
    mutationFn: async (device: Device) => destroy(`/devices/${device.id}/pairing`),
    onSuccess: (_result, device) => {
      enqueueSnackbar(`${device.device_code} is no longer paired.`, { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['devices'] })
      setUnpairTarget(null)
    },
    onError: (caught) => {
      enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' })
      setUnpairTarget(null)
    },
  })

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
      // The last time this terminal proved it was there, by authenticating.
      // Distinct from Last Submission: a device can be switched on, connected
      // and counting for an hour before it files anything, and an admin asking
      // "is HHT-02 alive" wants this one.
      key: 'last_seen_at',
      label: 'Last Seen',
      sortable: true,
      width: 200,
      hideBelow: 'lg',
      render: (device) => <Presence lastSeenAt={device.last_seen_at} />,
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 110,
      render: (device) => <StatusBadge status={device.status} />,
    },
    {
      // Whether this terminal can submit on its own. A device that has never
      // paired can still be used — its counts leave by Excel — so this is
      // information, not a fault.
      key: 'paired_at',
      label: 'Pairing',
      width: 130,
      render: (device) =>
        device.paired_at ? (
          <Chip size="small" variant="outlined" color="success" label="Paired" />
        ) : (
          <Chip size="small" variant="outlined" label="Not paired" />
        ),
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 96,
      render: (device) =>
        can(PERMISSIONS.devicesEdit) ? (
          <Stack direction="row" spacing={0.5} justifyContent="flex-end">
            <Tooltip title={device.paired_at ? 'Pair again' : 'Pair this device'}>
              <IconButton
                size="small"
                aria-label="Pair device"
                onClick={() => pairingMutation.mutate(device)}
                disabled={pairingMutation.isPending}
              >
                <LinkRoundedIcon fontSize="small" />
              </IconButton>
            </Tooltip>
            {/* Only offered for a terminal that has something to revoke. */}
            {device.paired_at ? (
              <Tooltip title="Unpair this device">
                <IconButton
                  size="small"
                  aria-label="Unpair device"
                  onClick={() => setUnpairTarget(device)}
                  disabled={unpairMutation.isPending}
                >
                  <LinkOffRoundedIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            ) : null}
            <Tooltip title="Edit device">
              <IconButton size="small" aria-label="Edit device" onClick={() => openEdit(device)}>
                <EditRoundedIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          </Stack>
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
        focusable
        focusTitle="HHT Devices"
        columnToggle
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

      {/* Confirmed rather than immediate: revoking is not reversible from
          here — the terminal has to be paired again with a fresh code, which
          means finding whoever holds them. */}
      <ConfirmDialog
        open={unpairTarget !== null}
        title={`Unpair ${unpairTarget?.device_code ?? ''}?`}
        message={`${unpairTarget?.device_code ?? 'This terminal'} will stop being able to submit counts to PharmaVerify immediately. Any counts already saved on it stay on the terminal until it is paired again. To use it after this, pair it with a new code.`}
        confirmLabel="Unpair"
        onConfirm={() => unpairTarget && unpairMutation.mutate(unpairTarget)}
        onClose={() => setUnpairTarget(null)}
      />

      {/* The code is readable exactly once — the server keeps only a hash — so
          this dialog is the single moment it exists in the clear. It is shown
          large because it is read aloud across a counter to someone holding a
          handheld. */}
      <ConfirmDialog
        open={pairing !== null}
        title={`Pair ${pairing?.device_code ?? ''}`}
        message={`Enter this code on the handheld, in Settings under PharmaVerify server. It works once and expires in ${
          pairing?.expires_in_minutes ?? 30
        } minutes.`}
        confirmLabel="Done"
        cancelLabel=""
        onConfirm={() => setPairing(null)}
        onClose={() => setPairing(null)}
        detail={
          <Box>
            <Typography
              sx={{
                fontFamily: 'ui-monospace, monospace',
                fontSize: '2rem',
                fontWeight: 700,
                letterSpacing: '0.18em',
                textAlign: 'center',
                py: 2,
                borderRadius: 2,
                bgcolor: 'action.hover',
              }}
            >
              {pairing?.pairing_code}
            </Typography>

            <Alert severity="info" sx={{ mt: 2, py: 0.5 }}>
              The terminal also needs this server&apos;s address, and its device code{' '}
              <strong>{pairing?.device_code}</strong> at shop <strong>{pairing?.shop_code}</strong>.
            </Alert>

            <Typography variant="caption" sx={{ display: 'block', mt: 1.5 }}>
              Pairing a terminal that was already paired revokes its previous token, so a lost or reset handheld stops
              being able to submit.
            </Typography>
          </Box>
        }
      />
    </Box>
  )
}

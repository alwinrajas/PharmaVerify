import {
  Box,
  Button,
  Chip,
  IconButton,
  MenuItem,
  Select,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import LockResetRoundedIcon from '@mui/icons-material/LockResetRounded'
import ToggleOnRoundedIcon from '@mui/icons-material/ToggleOnRounded'
import ToggleOffRoundedIcon from '@mui/icons-material/ToggleOffRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { FormDialog } from '@/components/dialogs'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get, post, put } from '@/services/apiClient'
import { formatDateTime } from '@/utils/format'
import type { ManagedUser } from '@/types'

interface RolesResponse {
  roles: Array<{ id: number; name: string; permissions: string[] }>
  permission_groups: Record<string, string[]>
}

const emptyForm = {
  name: '',
  email: '',
  password: '',
  employee_code: '',
  phone: '',
  status: 'active',
  role: '',
  shop_ids: [] as number[],
}

export function UsersPage() {
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'name', sortDir: 'asc' })
  const { data: shops } = useShopOptions()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<ManagedUser | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState<string | null>(null)

  const [resetTarget, setResetTarget] = useState<ManagedUser | null>(null)
  const [newPassword, setNewPassword] = useState('')
  const [resetError, setResetError] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['users', table.params],
    queryFn: async () => get<ManagedUser[]>('/users', table.params),
  })

  const { data: roleData } = useQuery({
    queryKey: ['user-roles'],
    queryFn: async () => (await get<RolesResponse>('/users/roles')).data,
    staleTime: 10 * 60 * 1000,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        email: form.email,
        employee_code: form.employee_code || null,
        phone: form.phone || null,
        status: form.status,
        role: form.role,
        shop_ids: form.shop_ids,
        ...(editing ? {} : { password: form.password }),
      }

      return editing ? put<ManagedUser>(`/users/${editing.id}`, payload) : post<ManagedUser>('/users', payload)
    },
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'User saved.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      setDialogOpen(false)
      setEditing(null)
    },
    onError: (caught) => setFormError(apiErrorMessage(caught)),
  })

  const toggleMutation = useMutation({
    mutationFn: async (user: ManagedUser) => post(`/users/${user.id}/toggle-status`),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'User status updated.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  const resetMutation = useMutation({
    mutationFn: async () => post(`/users/${resetTarget!.id}/reset-password`, { password: newPassword }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Password reset.', { variant: 'success' })
      setResetTarget(null)
      setNewPassword('')
    },
    onError: (caught) => setResetError(apiErrorMessage(caught)),
  })

  function openCreate() {
    setEditing(null)
    setForm({ ...emptyForm, role: roleData?.roles[0]?.name ?? '' })
    setFormError(null)
    setDialogOpen(true)
  }

  function openEdit(user: ManagedUser) {
    setEditing(user)
    setForm({
      name: user.name,
      email: user.email,
      password: '',
      employee_code: user.employee_code ?? '',
      phone: user.phone ?? '',
      status: user.status,
      role: user.roles[0] ?? '',
      shop_ids: (user.shops ?? []).map((shop) => shop.id),
    })
    setFormError(null)
    setDialogOpen(true)
  }

  const columns: DataTableColumn<ManagedUser>[] = [
    {
      key: 'name',
      label: 'User',
      sortable: true,
      render: (user) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {user.name}
          </Typography>
          <Typography variant="caption">{user.email}</Typography>
        </Box>
      ),
    },
    {
      key: 'roles',
      label: 'Role',
      width: 150,
      render: (user) => (
        <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
          {user.roles.map((role) => (
            <Chip key={role} size="small" label={role} sx={{ bgcolor: 'rgba(15,93,76,0.08)', color: 'primary.main' }} />
          ))}
        </Stack>
      ),
    },
    {
      key: 'shops',
      label: 'Assigned Shops',
      hideBelow: 'md',
      render: (user) =>
        (user.shops ?? []).length === 0 ? (
          <Typography variant="caption">All shops</Typography>
        ) : (
          <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
            {(user.shops ?? []).map((shop) => (
              <Chip key={shop.id} size="small" label={shop.shop_code} sx={{ bgcolor: '#ECEFEE' }} />
            ))}
          </Stack>
        ),
    },
    { key: 'employee_code', label: 'Employee Code', width: 145, hideBelow: 'lg' },
    {
      key: 'last_login_at',
      label: 'Last Login',
      sortable: true,
      width: 175,
      render: (user) => formatDateTime(user.last_login_at),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 110,
      render: (user) => <StatusBadge status={user.status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 130,
      render: (user) => (
        <Stack direction="row" spacing={0.25} justifyContent="flex-end">
          <Tooltip title="Edit user">
            <IconButton size="small" onClick={() => openEdit(user)}>
              <EditRoundedIcon fontSize="small" />
            </IconButton>
          </Tooltip>
          <Tooltip title="Reset password">
            <IconButton
              size="small"
              onClick={() => {
                setResetTarget(user)
                setNewPassword('')
                setResetError(null)
              }}
            >
              <LockResetRoundedIcon fontSize="small" />
            </IconButton>
          </Tooltip>
          <Tooltip title={user.status === 'active' ? 'Deactivate' : 'Activate'}>
            <IconButton size="small" onClick={() => toggleMutation.mutate(user)}>
              {user.status === 'active' ? (
                <ToggleOnRoundedIcon fontSize="small" color="success" />
              ) : (
                <ToggleOffRoundedIcon fontSize="small" />
              )}
            </IconButton>
          </Tooltip>
        </Stack>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title="User Management"
        description="Who may sign in, what they are allowed to do, and which shops they can see."
        crumbs={[{ label: 'Administration' }, { label: 'Users' }]}
        actions={
          <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={openCreate}>
            Add User
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(user) => user.id}
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
        emptyTitle="No users found"
        emptyDescription="Add a user, or widen your filters."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search name, email, code…" width={280} />
            <SelectFilter
              label="Role"
              value={table.filters.role ?? ''}
              onChange={(value) => table.setFilter('role', value)}
              options={(roleData?.roles ?? []).map((role) => ({ value: role.name, label: role.name }))}
              width={175}
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
        title={editing ? `Edit ${editing.name}` : 'Add User'}
        description="A Shop User only sees the shops assigned to them. Administrators and supervisors see every shop."
        onClose={() => setDialogOpen(false)}
        onSubmit={() => {
          setFormError(null)
          saveMutation.mutate()
        }}
        submitLabel={editing ? 'Save changes' : 'Create user'}
        busy={saveMutation.isPending}
        error={formError}
        maxWidth="md"
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField
            label="Full Name"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            size="small"
            fullWidth
            required
          />
          <TextField
            label="Email Address"
            type="email"
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            size="small"
            fullWidth
            required
          />

          {!editing ? (
            <TextField
              label="Password"
              type="password"
              value={form.password}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
              size="small"
              fullWidth
              required
              helperText="At least 8 characters"
              sx={{ gridColumn: { sm: '1 / -1' } }}
            />
          ) : null}

          <TextField
            label="Employee Code"
            value={form.employee_code}
            onChange={(event) => setForm({ ...form, employee_code: event.target.value })}
            size="small"
            fullWidth
          />
          <TextField
            label="Phone"
            value={form.phone}
            onChange={(event) => setForm({ ...form, phone: event.target.value })}
            size="small"
            fullWidth
          />

          <TextField
            select
            label="Role"
            value={form.role}
            onChange={(event) => setForm({ ...form, role: event.target.value })}
            size="small"
            fullWidth
            required
            slotProps={{ select: { native: true } }}
          >
            <option value="">Select a role…</option>
            {(roleData?.roles ?? []).map((role) => (
              <option key={role.id} value={role.name}>
                {role.name}
              </option>
            ))}
          </TextField>

          <TextField
            select
            label="Status"
            value={form.status}
            onChange={(event) => setForm({ ...form, status: event.target.value })}
            size="small"
            fullWidth
            slotProps={{ select: { native: true } }}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </TextField>

          <Box sx={{ gridColumn: { sm: '1 / -1' } }}>
            <Typography variant="subtitle2" sx={{ mb: 0.75 }}>
              Assigned shops
            </Typography>
            <Select
              multiple
              size="small"
              fullWidth
              value={form.shop_ids}
              onChange={(event) => setForm({ ...form, shop_ids: event.target.value as number[] })}
              renderValue={(selected) =>
                selected.length === 0
                  ? 'No restriction — all shops'
                  : (shops ?? [])
                      .filter((shop) => (selected as number[]).includes(shop.id))
                      .map((shop) => shop.code)
                      .join(', ')
              }
              displayEmpty
            >
              {(shops ?? []).map((shop) => (
                <MenuItem key={shop.id} value={shop.id}>
                  {shop.label}
                </MenuItem>
              ))}
            </Select>
            <Typography variant="caption" sx={{ display: 'block', mt: 0.75 }}>
              Leave empty for a user who should see every shop.
            </Typography>
          </Box>

          {form.role && roleData ? (
            <Box sx={{ gridColumn: { sm: '1 / -1' } }}>
              <Typography variant="subtitle2" sx={{ mb: 0.75 }}>
                Permissions granted by this role
              </Typography>
              <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.5 }}>
                {(roleData.roles.find((role) => role.name === form.role)?.permissions ?? []).map((permission) => (
                  <Chip key={permission} size="small" label={permission} sx={{ bgcolor: '#ECEFEE', fontWeight: 400 }} />
                ))}
              </Stack>
            </Box>
          ) : null}
        </Box>
      </FormDialog>

      <FormDialog
        open={Boolean(resetTarget)}
        title={`Reset password for ${resetTarget?.name ?? ''}`}
        description="The user will be signed out everywhere and must sign in again with the new password."
        onClose={() => setResetTarget(null)}
        onSubmit={() => {
          setResetError(null)
          resetMutation.mutate()
        }}
        submitLabel="Reset password"
        busy={resetMutation.isPending}
        error={resetError}
        maxWidth="xs"
        submitDisabled={newPassword.length < 8}
      >
        <TextField
          label="New Password"
          type="password"
          value={newPassword}
          onChange={(event) => setNewPassword(event.target.value)}
          size="small"
          fullWidth
          required
          helperText="At least 8 characters"
        />
      </FormDialog>
    </Box>
  )
}

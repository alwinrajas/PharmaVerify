import { Box, Button, IconButton, Stack, TextField, Tooltip, Typography } from '@mui/material'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import EditRoundedIcon from '@mui/icons-material/EditRounded'
import ToggleOnRoundedIcon from '@mui/icons-material/ToggleOnRounded'
import ToggleOffRoundedIcon from '@mui/icons-material/ToggleOffRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { FormDialog } from '@/components/dialogs'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get, post, put } from '@/services/apiClient'
import { formatDate, formatNumber } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { Shop } from '@/types'

const emptyForm = {
  shop_code: '',
  shop_name: '',
  address: '',
  city: '',
  contact_person: '',
  contact_number: '',
  status: 'active',
}

export function ShopsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'shop_code', sortDir: 'asc' })

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Shop | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['shops', table.params],
    queryFn: async () => get<Shop[]>('/shops', table.params),
  })

  const saveMutation = useMutation({
    mutationFn: async () =>
      editing ? put<Shop>(`/shops/${editing.id}`, form) : post<Shop>('/shops', form),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Shop saved successfully.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['shops'] })
      void queryClient.invalidateQueries({ queryKey: ['shop-options'] })
      closeDialog()
    },
    onError: (caught) => setFormError(apiErrorMessage(caught)),
  })

  const toggleMutation = useMutation({
    mutationFn: async (shop: Shop) =>
      put<Shop>(`/shops/${shop.id}`, {
        shop_code: shop.shop_code,
        shop_name: shop.shop_name,
        address: shop.address ?? '',
        city: shop.city ?? '',
        contact_person: shop.contact_person ?? '',
        contact_number: shop.contact_number ?? '',
        status: shop.status === 'active' ? 'inactive' : 'active',
      }),
    onSuccess: () => {
      enqueueSnackbar('Shop status updated.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['shops'] })
      void queryClient.invalidateQueries({ queryKey: ['shop-options'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    setFormError(null)
    setDialogOpen(true)
  }

  function openEdit(shop: Shop) {
    setEditing(shop)
    setForm({
      shop_code: shop.shop_code,
      shop_name: shop.shop_name,
      address: shop.address ?? '',
      city: shop.city ?? '',
      contact_person: shop.contact_person ?? '',
      contact_number: shop.contact_number ?? '',
      status: shop.status,
    })
    setFormError(null)
    setDialogOpen(true)
  }

  function closeDialog() {
    setDialogOpen(false)
    setEditing(null)
    setFormError(null)
  }

  const columns: DataTableColumn<Shop>[] = [
    {
      key: 'shop_code',
      label: 'Shop Code',
      sortable: true,
      width: 130,
      render: (shop) => (
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
          {shop.shop_code}
        </Typography>
      ),
    },
    {
      key: 'shop_name',
      label: 'Shop Name',
      sortable: true,
      render: (shop) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {shop.shop_name}
          </Typography>
          {shop.address ? (
            <Typography variant="caption" noWrap sx={{ display: 'block', maxWidth: 320 }}>
              {shop.address}
            </Typography>
          ) : null}
        </Box>
      ),
    },
    { key: 'city', label: 'City', sortable: true, hideBelow: 'md' },
    {
      key: 'contact_person',
      label: 'Contact',
      hideBelow: 'lg',
      render: (shop) => (
        <Box>
          <Typography variant="body2">{shop.contact_person ?? '—'}</Typography>
          {shop.contact_number ? <Typography variant="caption">{shop.contact_number}</Typography> : null}
        </Box>
      ),
    },
    {
      key: 'devices_count',
      label: 'Devices',
      align: 'right',
      width: 90,
      hideBelow: 'md',
      render: (shop) => formatNumber(shop.devices_count ?? 0),
    },
    {
      key: 'item_stocks_count',
      label: 'Stock Records',
      align: 'right',
      width: 120,
      hideBelow: 'md',
      render: (shop) => formatNumber(shop.item_stocks_count ?? 0),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 110,
      render: (shop) => <StatusBadge status={shop.status} />,
    },
    {
      key: 'created_at',
      label: 'Created',
      sortable: true,
      width: 120,
      hideBelow: 'lg',
      render: (shop) => formatDate(shop.created_at),
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 96,
      render: (shop) => (
        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
          {can(PERMISSIONS.shopsEdit) ? (
            <>
              <Tooltip title="Edit shop">
                <IconButton size="small" aria-label="Edit shop" onClick={() => openEdit(shop)}>
                  <EditRoundedIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <Tooltip title={shop.status === 'active' ? 'Deactivate' : 'Activate'}>
                <IconButton
                  size="small"
                  aria-label={shop.status === 'active' ? 'Deactivate shop' : 'Activate shop'}
                  onClick={() => toggleMutation.mutate(shop)}
                >
                  {shop.status === 'active' ? (
                    <ToggleOnRoundedIcon fontSize="small" color="success" />
                  ) : (
                    <ToggleOffRoundedIcon fontSize="small" />
                  )}
                </IconButton>
              </Tooltip>
            </>
          ) : null}
        </Stack>
      ),
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Shops"
        description="The pharmacy branches whose stock is verified. Each shop holds its own stock, devices and audits."
        crumbs={[{ label: 'Master' }, { label: 'Shops' }]}
        actions={
          can(PERMISSIONS.shopsCreate) ? (
            <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={openCreate}>
              Add Shop
            </Button>
          ) : null
        }
      />

      <DataTable
        focusable
        focusTitle="Shops"
        columnToggle
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(shop) => shop.id}
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
        emptyTitle="No shops found"
        emptyDescription="Add your first shop to begin verifying stock."
        emptyAction={
          can(PERMISSIONS.shopsCreate) ? (
            <Button variant="contained" size="small" startIcon={<AddRoundedIcon />} onClick={openCreate}>
              Add Shop
            </Button>
          ) : null
        }
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar
              value={table.search}
              onChange={table.setSearch}
              placeholder="Search code, name, city…"
              width={280}
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
        title={editing ? `Edit ${editing.shop_code}` : 'Add Shop'}
        description={
          editing ? 'Update the branch details.' : 'Register a pharmacy branch whose stock will be verified.'
        }
        onClose={closeDialog}
        onSubmit={() => {
          setFormError(null)
          saveMutation.mutate()
        }}
        submitLabel={editing ? 'Save changes' : 'Create shop'}
        busy={saveMutation.isPending}
        error={formError}
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField
            label="Shop Code"
            value={form.shop_code}
            onChange={(event) => setForm({ ...form, shop_code: event.target.value })}
            required
            fullWidth
            size="small"
            helperText="Unique across the business, e.g. PHM001"
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
            label="Shop Name"
            value={form.shop_name}
            onChange={(event) => setForm({ ...form, shop_name: event.target.value })}
            required
            fullWidth
            size="small"
            sx={{ gridColumn: { sm: '1 / -1' } }}
          />

          <TextField
            label="Address"
            value={form.address}
            onChange={(event) => setForm({ ...form, address: event.target.value })}
            fullWidth
            size="small"
            multiline
            rows={2}
            sx={{ gridColumn: { sm: '1 / -1' } }}
          />

          <TextField
            label="City"
            value={form.city}
            onChange={(event) => setForm({ ...form, city: event.target.value })}
            fullWidth
            size="small"
          />
          <TextField
            label="Contact Person"
            value={form.contact_person}
            onChange={(event) => setForm({ ...form, contact_person: event.target.value })}
            fullWidth
            size="small"
          />
          <TextField
            label="Contact Number"
            value={form.contact_number}
            onChange={(event) => setForm({ ...form, contact_number: event.target.value })}
            fullWidth
            size="small"
          />
        </Box>
      </FormDialog>
    </Box>
  )
}

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
import { formatMoney } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { Item } from '@/types'

const UOM_OPTIONS = ['EA', 'STRIP', 'BOX', 'BTL', 'PACK', 'VIAL', 'TUBE']

const emptyForm = {
  product_code: '',
  barcode: '',
  description: '',
  generic_name: '',
  manufacturer: '',
  uom: 'EA',
  price: '0',
  status: 'active',
}

export function ItemsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()
  const table = useTableQuery({ sortBy: 'product_code', sortDir: 'asc' })

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Item | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState<string | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['items', table.params],
    queryFn: async () => get<Item[]>('/items', table.params),
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...form, price: Number(form.price || 0) }
      return editing ? put<Item>(`/items/${editing.id}`, payload) : post<Item>('/items', payload)
    },
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Item saved successfully.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['items'] })
      closeDialog()
    },
    onError: (caught) => setFormError(apiErrorMessage(caught)),
  })

  const toggleMutation = useMutation({
    mutationFn: async (item: Item) =>
      put<Item>(`/items/${item.id}`, {
        product_code: item.product_code,
        barcode: item.barcode ?? '',
        description: item.description,
        generic_name: item.generic_name ?? '',
        manufacturer: item.manufacturer ?? '',
        uom: item.uom,
        price: item.price,
        status: item.status === 'active' ? 'inactive' : 'active',
      }),
    onSuccess: () => {
      enqueueSnackbar('Item status updated.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['items'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    setFormError(null)
    setDialogOpen(true)
  }

  function openEdit(item: Item) {
    setEditing(item)
    setForm({
      product_code: item.product_code,
      barcode: item.barcode ?? '',
      description: item.description,
      generic_name: item.generic_name ?? '',
      manufacturer: item.manufacturer ?? '',
      uom: item.uom,
      price: String(item.price ?? 0),
      status: item.status,
    })
    setFormError(null)
    setDialogOpen(true)
  }

  function closeDialog() {
    setDialogOpen(false)
    setEditing(null)
    setFormError(null)
  }

  const columns: DataTableColumn<Item>[] = [
    {
      key: 'product_code',
      label: 'Product Code',
      sortable: true,
      width: 130,
      render: (item) => (
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
          {item.product_code}
        </Typography>
      ),
    },
    {
      key: 'description',
      label: 'Product Description',
      sortable: true,
      render: (item) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {item.description}
          </Typography>
          <Typography variant="caption">
            {[item.generic_name, item.manufacturer].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    {
      key: 'barcode',
      label: 'Barcode',
      sortable: true,
      width: 150,
      hideBelow: 'md',
      render: (item) => (
        <Typography variant="body2" sx={{ fontFamily: 'ui-monospace, monospace', fontSize: '0.8125rem' }}>
          {item.barcode ?? '—'}
        </Typography>
      ),
    },
    { key: 'uom', label: 'UOM', sortable: true, width: 90, hideBelow: 'md' },
    {
      key: 'price',
      label: 'Price',
      sortable: true,
      align: 'right',
      width: 110,
      render: (item) => formatMoney(item.price),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 110,
      render: (item) => <StatusBadge status={item.status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'right',
      width: 96,
      render: (item) => (
        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
          {can(PERMISSIONS.itemsEdit) ? (
            <>
              <Tooltip title="Edit item">
                <IconButton size="small" onClick={() => openEdit(item)}>
                  <EditRoundedIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <Tooltip title={item.status === 'active' ? 'Deactivate' : 'Activate'}>
                <IconButton size="small" onClick={() => toggleMutation.mutate(item)}>
                  {item.status === 'active' ? (
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
        title="Items"
        description="The pharmacy item master. Stock records and counted lines are matched against these products."
        crumbs={[{ label: 'Master' }, { label: 'Items' }]}
        actions={
          can(PERMISSIONS.itemsCreate) ? (
            <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={openCreate}>
              Add Item
            </Button>
          ) : null
        }
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(item) => item.id}
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
        emptyTitle="No items found"
        emptyDescription="Add products to the item master, or adjust your filters."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar
              value={table.search}
              onChange={table.setSearch}
              placeholder="Search code, barcode, description…"
              width={320}
            />
            <SelectFilter
              label="UOM"
              value={table.filters.uom ?? ''}
              onChange={(value) => table.setFilter('uom', value)}
              options={UOM_OPTIONS.map((uom) => ({ value: uom, label: uom }))}
              width={130}
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
        title={editing ? `Edit ${editing.product_code}` : 'Add Item'}
        description={editing ? 'Update the product details.' : 'Add a product to the pharmacy item master.'}
        onClose={closeDialog}
        onSubmit={() => {
          setFormError(null)
          saveMutation.mutate()
        }}
        submitLabel={editing ? 'Save changes' : 'Create item'}
        busy={saveMutation.isPending}
        error={formError}
        maxWidth="md"
      >
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField
            label="Product Code"
            value={form.product_code}
            onChange={(event) => setForm({ ...form, product_code: event.target.value })}
            required
            fullWidth
            size="small"
            helperText="Unique across the item master"
          />
          <TextField
            label="Barcode"
            value={form.barcode}
            onChange={(event) => setForm({ ...form, barcode: event.target.value })}
            fullWidth
            size="small"
            helperText="Scanned by the HHT device"
          />

          <TextField
            label="Product Description"
            value={form.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
            required
            fullWidth
            size="small"
            sx={{ gridColumn: { sm: '1 / -1' } }}
          />

          <TextField
            label="Generic Name"
            value={form.generic_name}
            onChange={(event) => setForm({ ...form, generic_name: event.target.value })}
            fullWidth
            size="small"
          />
          <TextField
            label="Manufacturer"
            value={form.manufacturer}
            onChange={(event) => setForm({ ...form, manufacturer: event.target.value })}
            fullWidth
            size="small"
          />

          <TextField
            select
            label="Unit of Measure"
            value={form.uom}
            onChange={(event) => setForm({ ...form, uom: event.target.value })}
            fullWidth
            size="small"
            slotProps={{ select: { native: true } }}
          >
            {UOM_OPTIONS.map((uom) => (
              <option key={uom} value={uom}>
                {uom}
              </option>
            ))}
          </TextField>

          <TextField
            label="Price"
            type="number"
            value={form.price}
            onChange={(event) => setForm({ ...form, price: event.target.value })}
            required
            fullWidth
            size="small"
            slotProps={{ htmlInput: { min: 0, step: '0.01' } }}
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
        </Box>
      </FormDialog>
    </Box>
  )
}

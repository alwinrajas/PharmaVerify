import { Alert, Box, Button, Card, CardContent, Divider, Stack, Typography } from '@mui/material'
import AddRoundedIcon from '@mui/icons-material/AddRounded'
import PlaylistAddCheckRoundedIcon from '@mui/icons-material/PlaylistAddCheckRounded'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useAuth } from '@/features/auth/AuthContext'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatDate, formatDateTime, formatQuantity } from '@/utils/format'
import { PERMISSIONS } from '@/constants/permissions'
import type { AuditLine, StockTake } from '@/types'
import { StockTakeDialog } from './StockTakeDialog'

export function StockTakePage() {
  const { can } = useAuth()
  const table = useTableQuery({ sortBy: 'taken_at', sortDir: 'desc' })
  const { data: shops } = useShopOptions()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [fromLine, setFromLine] = useState<AuditLine | null>(null)

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['stock-takes', table.params],
    queryFn: async () => get<StockTake[]>('/stock-takes', table.params),
  })

  const { data: candidates } = useQuery({
    queryKey: ['stock-take-candidates', table.filters.shop_id],
    queryFn: async () =>
      get<AuditLine[]>('/stock-takes/candidates', {
        per_page: 8,
        ...(table.filters.shop_id ? { shop_id: table.filters.shop_id } : {}),
      }),
  })

  const columns: DataTableColumn<StockTake>[] = [
    {
      key: 'taken_at',
      label: 'Recorded',
      sortable: true,
      width: 180,
      render: (row) => (
        <Box>
          <Typography variant="body2">{formatDateTime(row.taken_at)}</Typography>
          <Typography variant="caption">{row.taken_by ?? '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'shop_code',
      label: 'Shop',
      width: 150,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.shop_code}
          </Typography>
          <Typography variant="caption">{row.audit_number ? `Audit ${row.audit_number}` : '—'}</Typography>
        </Box>
      ),
    },
    {
      key: 'description',
      label: 'Product',
      sortable: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.description}
          </Typography>
          <Typography variant="caption">
            {[row.product_code, row.barcode, row.batch && `Batch ${row.batch}`].filter(Boolean).join(' · ') || '—'}
          </Typography>
        </Box>
      ),
    },
    {
      key: 'physical_qty',
      label: 'Physical Qty',
      sortable: true,
      align: 'right',
      width: 120,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 700 }}>
          {formatQuantity(row.physical_qty)} {row.uom}
        </Typography>
      ),
    },
    {
      key: 'expiry_date',
      label: 'Expiry',
      width: 120,
      hideBelow: 'lg',
      render: (row) => formatDate(row.expiry_date),
    },
    { key: 'shelf_location', label: 'Shelf', width: 90, hideBelow: 'lg' },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      width: 120,
      render: (row) => <StatusBadge status={row.status} />,
    },
  ]

  const candidateRows = candidates?.data ?? []

  return (
    <Box>
      <PageHeader
        title="Stock Take"
        description="Physical stock found on the shelf that the shop's stock information does not contain."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Stock Take' }]}
        actions={
          can(PERMISSIONS.stockTakeCreate) ? (
            <Button
              variant="contained"
              startIcon={<AddRoundedIcon />}
              onClick={() => {
                setFromLine(null)
                setDialogOpen(true)
              }}
            >
              Record Stock Take
            </Button>
          ) : null
        }
      />

      <Alert severity="info" sx={{ mb: 2.5 }}>
        A stock take never creates a new item in the item master. The entry is kept separately so the business can
        decide what should happen to the product.
      </Alert>

      {candidateRows.length > 0 ? (
        <Card sx={{ mb: 2.5, borderColor: '#E5C98A' }}>
          <CardContent sx={{ p: 2.5 }}>
            <Stack direction="row" spacing={1.25} alignItems="center" sx={{ mb: 1.5 }}>
              <PlaylistAddCheckRoundedIcon fontSize="small" sx={{ color: 'warning.main' }} />
              <Typography variant="subtitle1">Counted but not in the stock file</Typography>
            </Stack>
            <Typography variant="caption" sx={{ display: 'block', mb: 1.5 }}>
              These products were scanned during a count but do not appear in the shop's stock. Record each one as a
              stock take.
            </Typography>

            <Stack divider={<Divider flexItem />}>
              {candidateRows.map((line) => (
                <Stack key={line.id} direction="row" alignItems="center" spacing={2} sx={{ py: 1.25 }}>
                  <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                    <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                      {line.description ?? line.barcode}
                    </Typography>
                    <Typography variant="caption">
                      {[line.shop_code, line.barcode, `Audit ${line.audit_number}`].filter(Boolean).join(' · ')}
                    </Typography>
                  </Box>

                  <Typography variant="body2" sx={{ fontWeight: 700, flexShrink: 0 }}>
                    {formatQuantity(line.physical_qty)} {line.uom}
                  </Typography>

                  {can(PERMISSIONS.stockTakeCreate) ? (
                    <Button
                      size="small"
                      variant="outlined"
                      sx={{ flexShrink: 0 }}
                      onClick={() => {
                        setFromLine(line)
                        setDialogOpen(true)
                      }}
                    >
                      Record
                    </Button>
                  ) : null}
                </Stack>
              ))}
            </Stack>
          </CardContent>
        </Card>
      ) : null}

      <DataTable
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
        emptyTitle="No stock takes recorded"
        emptyDescription="Record stock found on the shelf that is missing from the shop's stock file."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar value={table.search} onChange={table.setSearch} placeholder="Search product or barcode…" width={280} />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={240}
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

      <StockTakeDialog
        open={dialogOpen}
        fromLine={fromLine}
        defaultShopId={table.filters.shop_id}
        onClose={() => setDialogOpen(false)}
      />
    </Box>
  )
}

import { Box, Card, CardContent, Chip, Stack, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import dayjs from 'dayjs'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { DateFilter, FilterBar, SearchBar, SelectFilter } from '@/components/filters'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useShopOptions } from '@/hooks/useOptions'
import { useTableQuery } from '@/hooks/useTableQuery'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatDate, formatMoney, formatNumber, formatQuantity } from '@/utils/format'
import type { ItemStock } from '@/types'

interface StockMeta {
  total: number
  summary?: { record_count: number; total_quantity: number; expiring_soon: number }
}

export function ItemStockPage() {
  const table = useTableQuery({ sortBy: 'product_code', sortDir: 'asc' })
  const { data: shops } = useShopOptions()

  const { data, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['item-stocks', table.params],
    queryFn: async () => get<ItemStock[]>('/item-stocks', table.params),
  })

  const meta = data?.meta as unknown as StockMeta | undefined
  const summary = meta?.summary

  const columns: DataTableColumn<ItemStock>[] = [
    {
      key: 'shop_code',
      label: 'Shop',
      width: 100,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main' }}>
          {row.shop_code}
        </Typography>
      ),
    },
    {
      key: 'product_code',
      label: 'Product',
      sortable: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.description}
          </Typography>
          <Typography variant="caption">
            {row.product_code}
            {row.gtin ? ` · GTIN ${row.gtin}` : ''}
          </Typography>
        </Box>
      ),
    },
    { key: 'batch', label: 'Batch', sortable: true, width: 110 },
    {
      key: 'expiry_date',
      label: 'Expiry',
      sortable: true,
      width: 130,
      render: (row) => {
        if (!row.expiry_date) return '—'

        const expiring = dayjs(row.expiry_date).diff(dayjs(), 'day') <= 90

        return (
          <Typography variant="body2" sx={{ color: expiring ? 'warning.main' : 'text.primary', fontWeight: expiring ? 600 : 400 }}>
            {formatDate(row.expiry_date)}
          </Typography>
        )
      },
    },
    { key: 'shelf_location', label: 'Shelf', width: 90, hideBelow: 'lg' },
    { key: 'uom', label: 'UOM', width: 80, hideBelow: 'md' },
    {
      key: 'system_qty',
      label: 'System Qty',
      sortable: true,
      align: 'right',
      width: 115,
      render: (row) => (
        <Typography variant="body2" sx={{ fontWeight: 700 }}>
          {formatQuantity(row.system_qty)}
        </Typography>
      ),
    },
    {
      key: 'whole_qty',
      label: 'Whole Qty',
      align: 'right',
      width: 110,
      hideBelow: 'lg',
      // Fractional by design: a part-pack holding is real, so it is shown as
      // it is rather than rounded to a whole number.
      render: (row) => formatQuantity(row.whole_qty),
    },
    {
      key: 'price',
      label: 'Price',
      align: 'right',
      width: 100,
      hideBelow: 'md',
      render: (row) => formatMoney(row.price),
    },
    {
      key: 'total_cost',
      label: 'Total Cost',
      align: 'right',
      width: 115,
      hideBelow: 'lg',
      // As supplied by the ERP. Never recalculated here.
      render: (row) => (row.total_cost === null ? '—' : formatMoney(row.total_cost)),
    },
    {
      key: 'verification_status',
      label: 'Status',
      sortable: true,
      width: 130,
      render: (row) => <StatusBadge status={row.verification_status} />,
    },
  ]

  return (
    <Box>
      <PageHeader
        title="Item Stock"
        description="The system stock each shop currently holds. The same product can appear in several shops, each with its own record."
        crumbs={[{ label: 'Stock Verification' }, { label: 'Item Stock' }]}
      />

      {summary ? (
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(3, 1fr)' }, mb: 3 }}>
          <SummaryCard label="Stock records" value={formatNumber(summary.record_count)} />
          <SummaryCard label="Total quantity" value={formatQuantity(summary.total_quantity)} />
          <SummaryCard
            label="Expiring within 90 days"
            value={formatNumber(summary.expiring_soon)}
            tone={summary.expiring_soon > 0 ? 'warning' : undefined}
          />
        </Box>
      ) : null}

      <DataTable
        focusable
        focusTitle="Item Stock"
        density="compact"
        columnToggle
        freezeFirstColumn
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
        emptyTitle="No stock records"
        emptyDescription="Import a stock file for a shop, or widen your filters."
        toolbar={
          <FilterBar hasFilters={table.hasFilters} onClear={table.clearFilters}>
            <SearchBar
              value={table.search}
              onChange={table.setSearch}
              placeholder="Search product, barcode, batch…"
              width={300}
            />
            <SelectFilter
              label="Shop"
              value={table.filters.shop_id ?? ''}
              onChange={(value) => table.setFilter('shop_id', value)}
              options={(shops ?? []).map((shop) => ({ value: shop.id, label: shop.label }))}
              width={250}
            />
            <SelectFilter
              label="Status"
              value={table.filters.verification_status ?? ''}
              onChange={(value) => table.setFilter('verification_status', value)}
              options={[
                { value: 'not_verified', label: 'Not verified' },
                { value: 'verified', label: 'Verified' },
                { value: 'adjusted', label: 'Adjusted' },
              ]}
              width={150}
            />
            <DateFilter
              label="Expiry from"
              value={table.filters.expiry_from ?? ''}
              onChange={(value) => table.setFilter('expiry_from', value)}
            />
            <DateFilter
              label="Expiry to"
              value={table.filters.expiry_to ?? ''}
              onChange={(value) => table.setFilter('expiry_to', value)}
            />
            <Chip
              size="small"
              label="Expiring soon"
              onClick={() =>
                table.setFilter('expiring_soon', table.filters.expiring_soon === '1' ? '' : '1')
              }
              variant={table.filters.expiring_soon === '1' ? 'filled' : 'outlined'}
              color={table.filters.expiring_soon === '1' ? 'warning' : 'default'}
              sx={{ borderColor: 'divider', cursor: 'pointer' }}
            />
          </FilterBar>
        }
      />
    </Box>
  )
}

function SummaryCard({ label, value, tone }: { label: string; value: string; tone?: 'warning' }) {
  return (
    <Card>
      <CardContent sx={{ p: 2 }}>
        <Stack spacing={0.5}>
          <Typography variant="caption">{label}</Typography>
          <Typography
            sx={{ fontSize: '1.5rem', fontWeight: 700, color: tone === 'warning' ? 'warning.main' : 'text.primary' }}
          >
            {value}
          </Typography>
        </Stack>
      </CardContent>
    </Card>
  )
}

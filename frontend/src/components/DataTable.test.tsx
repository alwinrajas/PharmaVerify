import { screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DataTable, type DataTableColumn } from './DataTable'
import { renderWithProviders } from '@/test/utils'

interface Row {
  id: number
  shop_code: string
  description: string
  system_qty: number
}

const rows: Row[] = [
  { id: 1, shop_code: 'PHM001', description: 'Paracetamol 500mg Tablet', system_qty: 120 },
  { id: 2, shop_code: 'PHM002', description: 'Amoxicillin 250mg Capsule', system_qty: 80 },
]

const columns: DataTableColumn<Row>[] = [
  { key: 'shop_code', label: 'Shop', sortable: true },
  { key: 'description', label: 'Product Description', sortable: true },
  { key: 'system_qty', label: 'System Qty', align: 'right' },
]

function setup(props: Partial<React.ComponentProps<typeof DataTable<Row>>> = {}) {
  return renderWithProviders(
    <DataTable
      columns={columns}
      rows={rows}
      rowKey={(row) => row.id}
      total={2}
      page={0}
      perPage={25}
      onPageChange={vi.fn()}
      onPerPageChange={vi.fn()}
      {...props}
    />,
  )
}

/**
 * The one table behind every list screen. A regression here would show up on
 * twenty screens at once, which is why it carries the most coverage.
 */
describe('DataTable', () => {
  it('renders a column header for each column and a row for each record', () => {
    setup()

    expect(screen.getByRole('columnheader', { name: /shop/i })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /product description/i })).toBeInTheDocument()

    expect(screen.getByText('Paracetamol 500mg Tablet')).toBeInTheDocument()
    expect(screen.getByText('Amoxicillin 250mg Capsule')).toBeInTheDocument()
  })

  it('shows an em dash rather than a blank cell for a missing value', () => {
    setup({ rows: [{ id: 3, shop_code: '', description: 'No shop', system_qty: 0 }] })

    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('uses a custom renderer when the column supplies one', () => {
    setup({
      columns: [
        ...columns,
        { key: 'status', label: 'Status', render: () => <span>Verified</span> },
      ],
    })

    expect(screen.getAllByText('Verified')).toHaveLength(rows.length)
  })

  it('asks for the opposite direction when the active sort column is clicked again', async () => {
    const onSortChange = vi.fn()
    const { user } = setup({ sortBy: 'shop_code', sortDir: 'asc', onSortChange })

    await user.click(screen.getByRole('button', { name: /shop/i }))

    expect(onSortChange).toHaveBeenCalledWith('shop_code', 'desc')
  })

  it('asks for ascending order when a different column is sorted', async () => {
    const onSortChange = vi.fn()
    const { user } = setup({ sortBy: 'shop_code', sortDir: 'desc', onSortChange })

    await user.click(screen.getByRole('button', { name: /product description/i }))

    expect(onSortChange).toHaveBeenCalledWith('description', 'asc')
  })

  it('does not offer sorting on a column that is not sortable', () => {
    setup({ sortBy: 'shop_code', sortDir: 'asc', onSortChange: vi.fn() })

    const header = screen.getByRole('columnheader', { name: /system qty/i })

    expect(within(header).queryByRole('button')).not.toBeInTheDocument()
  })

  it('reports the server-side total to the pager, not the number of rows on screen', () => {
    setup({ total: 137, page: 0, perPage: 25 })

    // Two rows are rendered, but the list has 137 records behind it.
    expect(screen.getByText(/of 137/)).toBeInTheDocument()
  })

  it('asks for the next page when the pager is advanced', async () => {
    const onPageChange = vi.fn()
    const { user } = setup({ total: 137, page: 0, perPage: 25, onPageChange })

    await user.click(screen.getByRole('button', { name: /go to next page/i }))

    expect(onPageChange).toHaveBeenCalledWith(1)
  })

  it('shows the empty state, and no data rows, when there is nothing to list', () => {
    setup({
      rows: [],
      total: 0,
      emptyTitle: 'No stock records',
      emptyDescription: 'Import a stock file for a shop.',
    })

    expect(screen.getByText('No stock records')).toBeInTheDocument()
    expect(screen.getByText('Import a stock file for a shop.')).toBeInTheDocument()
    expect(screen.queryByText('Paracetamol 500mg Tablet')).not.toBeInTheDocument()
  })

  it('keeps showing the rows while a refresh is in flight', () => {
    setup({ loading: true })

    expect(screen.getByRole('progressbar')).toBeInTheDocument()
    // The table does not blank out under the user mid-refresh.
    expect(screen.getByText('Paracetamol 500mg Tablet')).toBeInTheDocument()
  })

  it('shows the error state instead of the table when loading failed', () => {
    setup({ error: 'The shops could not be loaded.' })

    expect(screen.getByText('Unable to load')).toBeInTheDocument()
    expect(screen.getByText('The shops could not be loaded.')).toBeInTheDocument()
    expect(screen.queryByText('Paracetamol 500mg Tablet')).not.toBeInTheDocument()
  })

  it('offers a retry that calls back when loading failed', async () => {
    const onRetry = vi.fn()
    const { user } = setup({ error: 'Network unreachable.', onRetry })

    await user.click(screen.getByRole('button', { name: /try again/i }))

    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('passes the clicked record to the row handler', async () => {
    const onRowClick = vi.fn()
    const { user } = setup({ onRowClick })

    await user.click(screen.getByText('Amoxicillin 250mg Capsule'))

    expect(onRowClick).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }))
  })

  it('renders the toolbar it is given', () => {
    setup({ toolbar: <button type="button">Add Shop</button> })

    expect(screen.getByRole('button', { name: 'Add Shop' })).toBeInTheDocument()
  })
})

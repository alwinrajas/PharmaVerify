import { screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { DataTable, type DataTableColumn } from './DataTable'
import { renderWithProviders } from '@/test/utils'

/**
 * Expanded table mode.
 *
 * The point of these is that expanding is a change of container and nothing
 * else. An operator who has spent a minute setting up filters, sorting and
 * column choices must find all of it intact — and the server must not be asked
 * for the same page again just because the box got bigger.
 */

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
      focusable
      focusTitle="Stock Verification"
      columnToggle
      columns={columns}
      rows={rows}
      rowKey={(row) => row.id}
      total={2}
      page={1}
      perPage={25}
      {...props}
    />,
  )
}

const expandButton = () => screen.getByRole('button', { name: /expand table/i })
const exitButton = () => screen.getByRole('button', { name: /exit expanded table view/i })

describe('DataTable expanded mode', () => {
  it('offers an expand control only where the screen asked for one', () => {
    const { unmount } = setup()
    expect(expandButton()).toBeInTheDocument()
    unmount()

    // A card list or summary panel gains nothing from it.
    setup({ focusable: false })
    expect(screen.queryByRole('button', { name: /expand table/i })).not.toBeInTheDocument()
  })

  it('expands, and reports its state to assistive technology', async () => {
    const user = userEvent.setup()
    setup()

    expect(expandButton()).toHaveAttribute('aria-pressed', 'false')

    await user.click(expandButton())

    expect(screen.getByRole('region', { name: /stock verification, expanded view/i })).toBeInTheDocument()
    expect(exitButton()).toHaveAttribute('aria-pressed', 'true')
  })

  it('keeps the rows on screen while expanded', async () => {
    const user = userEvent.setup()
    setup()

    await user.click(expandButton())

    expect(screen.getByText('Paracetamol 500mg Tablet')).toBeInTheDocument()
    expect(screen.getByText('Amoxicillin 250mg Capsule')).toBeInTheDocument()
  })

  it('closes on Escape', async () => {
    const user = userEvent.setup()
    setup()

    await user.click(expandButton())
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('region', { name: /expanded view/i })).not.toBeInTheDocument()
  })

  it('closes on the exit button and returns focus to the control that opened it', async () => {
    const user = userEvent.setup()
    setup()

    await user.click(expandButton())
    await user.click(exitButton())

    // Back where the operator left off, not at the top of the document.
    expect(expandButton()).toHaveFocus()
  })

  it('does not ask the server for the page again just because the box got bigger', async () => {
    const user = userEvent.setup()
    const onPageChange = vi.fn()
    const onPerPageChange = vi.fn()
    const onSortChange = vi.fn()

    setup({ onPageChange, onPerPageChange, onSortChange })

    await user.click(expandButton())
    await user.click(exitButton())

    // Expanding is a style change on the same element. Anything that would
    // refetch — a page reset, a sort reset — means the subtree remounted.
    expect(onPageChange).not.toHaveBeenCalled()
    expect(onPerPageChange).not.toHaveBeenCalled()
    expect(onSortChange).not.toHaveBeenCalled()
  })

  it('keeps hidden columns hidden across expanding and closing', async () => {
    const user = userEvent.setup()
    setup()

    // Hide a column the way an operator would.
    await user.click(screen.getByRole('button', { name: /columns/i }))
    // Scoped to the menu: the same label is also the column header.
    const menu = await screen.findByRole('menu')
    await user.click(within(menu).getByText('Product Description'))
    await user.keyboard('{Escape}')

    const header = () => within(screen.getByRole('table')).getAllByRole('columnheader')
    expect(header().map((cell) => cell.textContent)).not.toContain('Product Description')

    await user.click(expandButton())

    // Column visibility lives in this component. A remount would silently
    // bring the hidden column back and quietly undo the operator's setup.
    expect(header().map((cell) => cell.textContent)).not.toContain('Product Description')

    await user.click(exitButton())
    expect(header().map((cell) => cell.textContent)).not.toContain('Product Description')
  })

  it('still shows the toolbar the screen provided', async () => {
    const user = userEvent.setup()
    setup({ toolbar: <button type="button">Clear filters</button> })

    await user.click(expandButton())

    // Filters and search have to come with it, or the expanded view is a
    // dead end the operator has to close to change anything.
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument()
  })

  it('shows the existing empty and error states rather than inventing new ones', async () => {
    const user = userEvent.setup()
    const onRetry = vi.fn()
    const { unmount } = setup({ rows: [], total: 0, emptyTitle: 'Nothing counted yet' })

    await user.click(expandButton())
    expect(screen.getByText('Nothing counted yet')).toBeInTheDocument()
    unmount()

    setup({ error: 'Could not load stock.', onRetry })
    expect(screen.getByText('Could not load stock.')).toBeInTheDocument()
  })
})

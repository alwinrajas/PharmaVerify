import { screen, waitFor } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { StockTakeDialog } from './StockTakeDialog'
import { server } from '@/test/server'
import { makeAuditLine, renderWithProviders } from '@/test/utils'

/**
 * Recording stock found on the shelf that the stock file does not carry.
 *
 * The rule this screen exists to uphold is that a stock take never creates a
 * product in the item master, and the dialog says so before anything is saved.
 */
describe('StockTakeDialog', () => {
  beforeEach(() => {
    server.use(
      http.get('/api/shops/options', () =>
        HttpResponse.json({
          success: true,
          data: [
            { id: 1, code: 'PHM001', label: 'PHM001 - Wellness Pharmacy' },
            { id: 2, code: 'PHM002', label: 'PHM002 - MediCare Pharmacy' },
          ],
        }),
      ),
    )
  })

  function setup(props: Partial<React.ComponentProps<typeof StockTakeDialog>> = {}) {
    const onClose = vi.fn()
    const onSaved = vi.fn()

    return {
      onClose,
      onSaved,
      ...renderWithProviders(<StockTakeDialog open onClose={onClose} onSaved={onSaved} {...props} />),
    }
  }

  it('states that the item master will not be changed', () => {
    setup()

    expect(screen.getByText(/does not/i)).toBeInTheDocument()
    expect(screen.getByText(/create a new item in the item master/i)).toBeInTheDocument()
  })

  it('explains what a stock take is for', () => {
    setup()

    expect(screen.getByText('Record Stock Take')).toBeInTheDocument()
    expect(
      screen.getByText(/stock file|stock information/i),
    ).toBeInTheDocument()
  })

  it('cannot be submitted until a shop, a description and a quantity are given', async () => {
    const { user } = setup()

    expect(screen.getByRole('button', { name: /record stock take/i })).toBeDisabled()

    // A description alone is not enough — the shop and the quantity are still
    // missing, and a stock take without either would be meaningless.
    await user.type(screen.getByLabelText(/Product Description/), 'Rabeprazole 20mg Tablet')

    expect(screen.getByRole('button', { name: /record stock take/i })).toBeDisabled()
  })

  it('prefills itself from a counted line that has no stock record', async () => {
    const line = makeAuditLine({
      is_unknown_item: true,
      item_stock_id: null,
      product_code: 'MED-1099',
      description: 'Rabeprazole 20mg Tablet',
      physical_qty: 24,
      barcode: '8901234599999',
    })

    setup({ fromLine: line })

    await waitFor(() =>
      expect(screen.getByLabelText(/Product Description/)).toHaveValue('Rabeprazole 20mg Tablet'),
    )
    expect(screen.getByLabelText(/Barcode/)).toHaveValue('8901234599999')
    expect(screen.getByLabelText(/Physical Quantity/)).toHaveValue(24)
  })

  it('closes without recording when cancelled', async () => {
    const saved = vi.fn()
    server.use(http.post('/api/stock-takes', () => { saved(); return HttpResponse.json({ success: true, data: {} }, { status: 201 }) }))

    const { user, onClose } = setup()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(saved).not.toHaveBeenCalled()
  })

  it('records the entry against the shop and the counted line', async () => {
    let body: Record<string, unknown> | null = null

    server.use(
      http.post('/api/stock-takes', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json(
          { success: true, message: 'Stock take recorded successfully.', data: {} },
          { status: 201 },
        )
      }),
    )

    const line = makeAuditLine({
      is_unknown_item: true,
      item_stock_id: null,
      description: 'Rabeprazole 20mg Tablet',
      physical_qty: 24,
    })

    const { user, onSaved } = setup({ fromLine: line })

    await waitFor(() =>
      expect(screen.getByLabelText(/Product Description/)).toHaveValue('Rabeprazole 20mg Tablet'),
    )

    await user.click(screen.getByRole('button', { name: /record stock take/i }))

    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce())

    expect(body).toMatchObject({
      shop_id: 1,
      description: 'Rabeprazole 20mg Tablet',
      physical_qty: 24,
      audit_line_id: 512,
    })
  })

  it('shows the server refusal and stays open', async () => {
    server.use(
      http.post('/api/stock-takes', () =>
        HttpResponse.json(
          { success: false, message: 'You do not have access to this shop.' },
          { status: 403 },
        ),
      ),
    )

    const line = makeAuditLine({ is_unknown_item: true, item_stock_id: null, physical_qty: 24 })
    const { user, onSaved, onClose } = setup({ fromLine: line })

    await waitFor(() => expect(screen.getByRole('button', { name: /record stock take/i })).toBeEnabled())
    await user.click(screen.getByRole('button', { name: /record stock take/i }))

    expect(await screen.findByText(/do not have access to this shop/i)).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
  })
})

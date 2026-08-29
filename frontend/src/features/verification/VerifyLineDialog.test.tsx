import { screen, waitFor } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { describe, expect, it, vi } from 'vitest'
import { VerifyLineDialog } from './VerifyLineDialog'
import { server } from '@/test/server'
import { makeAuditLine, renderWithProviders } from '@/test/utils'

/**
 * Correcting a counted line.
 *
 * The figure the verifier is shown as they type is what convinces them the
 * correction is right, so the variance on screen must always be
 * physical − system. The server recomputes it regardless, but a wrong number
 * here would lead someone to save the wrong quantity.
 */
describe('VerifyLineDialog', () => {
  function setup(line = makeAuditLine(), props: Record<string, unknown> = {}) {
    const onClose = vi.fn()
    const onSaved = vi.fn()

    return {
      onClose,
      onSaved,
      ...renderWithProviders(
        <VerifyLineDialog line={line} open onClose={onClose} onSaved={onSaved} {...props} />,
      ),
    }
  }

  it('shows the system quantity, the counted quantity and the variance', () => {
    setup()

    expect(screen.getByText('System quantity')).toBeInTheDocument()
    expect(screen.getByText('Physical quantity')).toBeInTheDocument()
    expect(screen.getByText('Variance')).toBeInTheDocument()

    expect(screen.getByText('125')).toBeInTheDocument()
    expect(screen.getByText('120')).toBeInTheDocument()
    // 125 held against 120 counted is five short, and short is positive.
    expect(screen.getByText('5')).toBeInTheDocument()
  })

  it('recalculates the variance as the counted quantity is typed', async () => {
    const { user } = setup()

    const field = screen.getByLabelText(/Physical Quantity/)
    await user.clear(field)
    await user.type(field, '130')

    // 130 counted against 125 held is five over, and excess is negative.
    expect(await screen.findByText('-5')).toBeInTheDocument()
  })

  it('shows a zero variance when the count agrees with the system', async () => {
    const { user } = setup()

    const field = screen.getByLabelText(/Physical Quantity/)
    await user.clear(field)
    await user.type(field, '125')

    // Scoped to the variance figure: the loose quantity beside it also reads
    // zero, so a bare text query would match either.
    const variance = screen.getByText('Variance').parentElement!

    await waitFor(() => expect(variance).toHaveTextContent(/^Variance0$/))
  })

  it('counts loose stock towards the shelf when working out the variance', async () => {
    const { user } = setup()

    // 125 held, 120 whole units and 5 loose: the shelf agrees after all.
    const loose = screen.getByLabelText(/Loose Quantity/)
    await user.clear(loose)
    await user.type(loose, '5')

    const variance = screen.getByText('Variance').parentElement!

    await waitFor(() => expect(variance).toHaveTextContent(/^Variance0$/))
  })

  it('does not accept a negative quantity', () => {
    setup()

    expect(screen.getByLabelText(/Physical Quantity/)).toHaveAttribute('min', '0')
  })

  it('warns that a product missing from the stock file must be a stock take', () => {
    setup(makeAuditLine({ is_unknown_item: true }))

    expect(screen.getByText(/not in the shop's stock file/i)).toBeInTheDocument()
    expect(screen.getByText(/Record it as a Stock Take instead/i)).toBeInTheDocument()
  })

  it('does not show that warning for a product that is in the stock file', () => {
    setup()

    expect(screen.queryByText(/not in the shop's stock file/i)).not.toBeInTheDocument()
  })

  it('closes without saving when cancelled', async () => {
    const saved = vi.fn()
    server.use(http.patch('/api/verification/lines/:id', () => { saved(); return HttpResponse.json({ success: true, data: {} }) }))

    const { user, onClose } = setup()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(saved).not.toHaveBeenCalled()
  })

  it('saves the corrected quantity and the remark, and marks the line verified', async () => {
    let body: Record<string, unknown> | null = null

    server.use(
      http.patch('/api/verification/lines/512', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({ success: true, message: 'Verification saved.', data: makeAuditLine() })
      }),
    )

    const { user, onSaved } = setup()

    const field = screen.getByLabelText(/Physical Quantity/)
    await user.clear(field)
    await user.type(field, '123')
    await user.type(screen.getByLabelText(/Remarks/), 'Recounted with the supervisor')

    await user.click(screen.getByRole('button', { name: /save and verify/i }))

    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce())

    expect(body).toMatchObject({
      physical_qty: 123,
      remarks: 'Recounted with the supervisor',
      mark_verified: true,
    })
  })

  it('shows the server refusal and stays open', async () => {
    server.use(
      http.patch('/api/verification/lines/512', () =>
        HttpResponse.json(
          { success: false, message: 'This audit has been closed and can no longer be edited.' },
          { status: 422 },
        ),
      ),
    )

    const { user, onSaved, onClose } = setup()

    await user.click(screen.getByRole('button', { name: /save and verify/i }))

    expect(await screen.findByText(/has been closed/i)).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('surfaces a field-level validation message from the server', async () => {
    server.use(
      http.patch('/api/verification/lines/512', () =>
        HttpResponse.json(
          {
            success: false,
            message: 'The information supplied is not valid.',
            errors: { physical_qty: ['A physical quantity cannot be negative.'] },
          },
          { status: 422 },
        ),
      ),
    )

    const { user } = setup()

    await user.click(screen.getByRole('button', { name: /save and verify/i }))

    // The specific field message is more use than the generic summary.
    expect(await screen.findByText(/cannot be negative/i)).toBeInTheDocument()
  })
})

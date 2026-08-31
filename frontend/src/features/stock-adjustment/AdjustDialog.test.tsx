import { screen, waitFor } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { describe, expect, it, vi } from 'vitest'
import { AdjustDialog } from './AdjustDialog'
import { server } from '@/test/server'
import { makeAuditLine, renderWithProviders } from '@/test/utils'

/**
 * Posting an adjustment.
 *
 * This dialog is the last thing a user sees before stock changes for real and
 * irreversibly, so what it states must stay true: the quantities it is about to
 * apply, and that there is no approval step to catch a mistake.
 */
describe('AdjustDialog', () => {
  const line = makeAuditLine()

  function setup(props: Partial<React.ComponentProps<typeof AdjustDialog>> = {}) {
    const onClose = vi.fn()
    const onPosted = vi.fn()

    return {
      onClose,
      onPosted,
      ...renderWithProviders(
        <AdjustDialog lines={[line]} open onClose={onClose} onPosted={onPosted} {...props} />,
      ),
    }
  }

  it('shows the resulting stock movement', () => {
    setup()

    expect(screen.getByText('Atorvastatin 10mg Tablet')).toBeInTheDocument()
    expect(screen.getByText('Current stock')).toBeInTheDocument()
    expect(screen.getByText('Physical count')).toBeInTheDocument()
    expect(screen.getByText('Adjustment')).toBeInTheDocument()
    expect(screen.getByText('New stock')).toBeInTheDocument()

    // system 125, physical 120, loose 0: current 125, counted 120 both as the
    // physical count and the resulting new stock, and an adjustment of -5.
    expect(screen.getByText('125')).toBeInTheDocument()
    expect(screen.getAllByText('120')).toHaveLength(2)
    expect(screen.getByText('-5')).toBeInTheDocument()
  })

  it('warns plainly that the change is immediate and unapproved', () => {
    setup()

    expect(screen.getByText('This posts immediately')).toBeInTheDocument()
    expect(screen.getByText(/no approval workflow/i)).toBeInTheDocument()
  })

  it('names the shop, product and batch so the right line is being adjusted', () => {
    setup()

    expect(screen.getByText(/PHM001/)).toBeInTheDocument()
    expect(screen.getByText(/MED-1005/)).toBeInTheDocument()
    expect(screen.getByText(/Batch B01005/)).toBeInTheDocument()
  })

  it('titles itself for a batch when several lines are being posted', () => {
    setup({ lines: [line, makeAuditLine({ id: 513, product_code: 'MED-1006' })] })

    expect(screen.getByText('Post 2 stock adjustments')).toBeInTheDocument()
  })

  it('closes without posting when cancelled', async () => {
    const posted = vi.fn()
    server.use(http.post('/api/adjustments', () => { posted(); return HttpResponse.json({ success: true, data: {} }, { status: 201 }) }))

    const { user, onClose } = setup()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(posted).not.toHaveBeenCalled()
  })

  it('posts the line and the reason, then reports back', async () => {
    let body: Record<string, unknown> | null = null

    server.use(
      http.post('/api/adjustments', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({ success: true, message: 'Adjustment posted.', data: {} }, { status: 201 })
      }),
    )

    const { user, onClose, onPosted } = setup()

    await user.type(screen.getByLabelText('Reason'), 'Physical count confirmed')
    await user.click(screen.getByRole('button', { name: /post adjustment/i }))

    await waitFor(() => expect(onPosted).toHaveBeenCalledOnce())

    expect(body).toMatchObject({ audit_line_id: 512, reason: 'Physical count confirmed' })
    expect(onClose).toHaveBeenCalled()
  })

  it('sends a list of lines when posting several at once', async () => {
    let body: Record<string, unknown> | null = null

    server.use(
      http.post('/api/adjustments', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({ success: true, data: {} }, { status: 201 })
      }),
    )

    const { user, onPosted } = setup({ lines: [line, makeAuditLine({ id: 513 })] })

    await user.click(screen.getByRole('button', { name: /post adjustment/i }))

    await waitFor(() => expect(onPosted).toHaveBeenCalledOnce())
    expect(body).toMatchObject({ audit_line_ids: [512, 513] })
  })

  it('shows the reason the server refused, and stays open', async () => {
    server.use(
      http.post('/api/adjustments', () =>
        HttpResponse.json(
          { success: false, message: 'Product MED-1005 in this audit has already been adjusted on 26 Aug 2026.' },
          { status: 422 },
        ),
      ),
    )

    const { user, onPosted, onClose } = setup()

    await user.click(screen.getByRole('button', { name: /post adjustment/i }))

    expect(await screen.findByText(/has already been adjusted/i)).toBeInTheDocument()
    expect(onPosted).not.toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('reports a network failure without claiming the adjustment went through', async () => {
    server.use(http.post('/api/adjustments', () => HttpResponse.error()))

    const { user, onPosted } = setup()

    await user.click(screen.getByRole('button', { name: /post adjustment/i }))

    // A readable sentence, not a raw network error.
    expect(await screen.findByText(/something went wrong/i)).toBeInTheDocument()
    expect(onPosted).not.toHaveBeenCalled()
  })

  it('surfaces a stock-drift refusal and reposts with it acknowledged on confirm', async () => {
    let calls = 0
    let acknowledgedOnSecondCall = false

    server.use(
      http.post('/api/adjustments', async ({ request }) => {
        calls++
        const body = (await request.json()) as Record<string, unknown>

        if (!body.acknowledge_drift) {
          return HttpResponse.json(
            {
              success: false,
              message:
                'System stock for MED-1005 has changed since this audit was counted: the audit saw 100, stock now holds 110. Adjusting will replace 110 with the counted 120. Confirm to proceed.',
            },
            { status: 422 },
          )
        }

        acknowledgedOnSecondCall = body.acknowledge_drift === true
        return HttpResponse.json({ success: true, message: 'Adjustment posted.', data: {} }, { status: 201 })
      }),
    )

    const { user, onPosted } = setup()

    await user.click(screen.getByRole('button', { name: /post adjustment/i }))

    expect(await screen.findByText(/has changed since this audit was counted/i)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /confirm and post/i }))

    await waitFor(() => expect(onPosted).toHaveBeenCalledOnce())
    expect(calls).toBe(2)
    expect(acknowledgedOnSecondCall).toBe(true)
  })
})

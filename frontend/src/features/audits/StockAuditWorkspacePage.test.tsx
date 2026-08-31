import { describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { StockAuditWorkspacePage } from './StockAuditWorkspacePage'
import { server } from '@/test/server'
import { makeAuditLine, renderWithProviders } from '@/test/utils'

/**
 * Counting a shop live from the browser.
 *
 * The Audit Review tab is the existing, unchanged AuditsPage and is left
 * alone — it has its own test file. These tests exercise the new Scan & Count
 * tab, which stays mounted alongside Audit Review the whole time (so an
 * in-progress count survives a glance at the other tab), so every test also
 * stubs the endpoints Audit Review fires on mount even though nothing here
 * looks at its output.
 */

function shopOption(overrides: Record<string, unknown> = {}) {
  return { id: 1, code: 'PHM001', label: 'PHM001 - Wellness Pharmacy', ...overrides }
}

function audit(overrides: Record<string, unknown> = {}) {
  return {
    id: 501,
    audit_number: 12,
    audit_ref: 'AUD-31082026-0012',
    source: 'system',
    shop_id: 1,
    shop_code: 'PHM001',
    shop_name: 'Wellness Pharmacy',
    device_id: null,
    device_code: null,
    hht_user: 'Arun Prakash',
    audit_date: '2026-08-31',
    submitted_at: null,
    item_count: 0,
    variance_count: 0,
    status: 'in_progress',
    verified_by: null,
    verified_at: null,
    ...overrides,
  }
}

function lookupMatch(overrides: Record<string, unknown> = {}) {
  return {
    item_stock_id: 77,
    product_code: 'MED-1005',
    description: 'Atorvastatin 10mg Tablet',
    barcode: '8901234500059',
    gtin: '08901234500059',
    batch: 'B01005',
    expiry_date: '2027-06-30',
    system_qty: 100,
    uom: 'STRIP',
    price: 94.3,
    shelf_location: 'A-02',
    ...overrides,
  }
}

/** What Audit Review fires on mount, regardless of which tab is on screen. */
function mockBackgroundEndpoints() {
  server.use(
    http.get('*/api/audits', () =>
      HttpResponse.json({
        success: true,
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      }),
    ),
    http.get('*/api/devices/options', () => HttpResponse.json({ success: true, data: [] })),
  )
}

function mockShops(shops = [shopOption()]) {
  server.use(http.get('*/api/shops/options', () => HttpResponse.json({ success: true, data: shops })))
}

function mockLines(auditId: number, lines: Array<Record<string, unknown>> = []) {
  server.use(
    http.get(`*/api/audits/${auditId}/lines`, () =>
      HttpResponse.json({
        success: true,
        data: lines,
        meta: { current_page: 1, last_page: 1, per_page: 200, total: lines.length },
      }),
    ),
  )
}

/**
 * The Scan & Count tab, scoped. Both tabs are mounted at once (see the
 * comment at the top of the file), and Audit Review's own filter bar has a
 * "Shop" field of its own sitting hidden beneath it — an unscoped query would
 * find that one too and fail with "multiple elements found". Testing
 * Library's role queries exclude anything under the `hidden` attribute by
 * default, so this always resolves to the panel actually on screen.
 */
function scanCountPanel() {
  return within(screen.getByRole('tabpanel'))
}

async function chooseShop(user: ReturnType<typeof renderWithProviders>['user']) {
  // The select is disabled until the shop options query resolves; waiting for
  // the real option to appear avoids picking at a still-loading control.
  await scanCountPanel().findByRole('option', { name: /wellness pharmacy/i })
  await user.selectOptions(scanCountPanel().getByLabelText('Shop'), '1')
}

async function startAudit(
  user: ReturnType<typeof renderWithProviders>['user'],
  auditResponse: Record<string, unknown> = audit(),
) {
  server.use(
    http.post('*/api/audits/start', () =>
      HttpResponse.json(
        { success: true, message: `Audit ${auditResponse.audit_ref} is open for counting.`, data: auditResponse },
        { status: 201 },
      ),
    ),
  )
  mockLines(auditResponse.id as number)

  await chooseShop(user)
  await user.click(scanCountPanel().getByRole('button', { name: /start new audit/i }))
  await scanCountPanel().findByText(auditResponse.audit_ref as string)
}

describe('StockAuditWorkspacePage — Scan & Count', () => {
  it('shows the empty state and no usable scanner before a shop is chosen', async () => {
    mockBackgroundEndpoints()
    mockShops()

    renderWithProviders(<StockAuditWorkspacePage />)

    expect(await scanCountPanel().findByText('Choose a shop to begin')).toBeInTheDocument()
    expect(scanCountPanel().queryByLabelText(/scan or type a barcode/i)).not.toBeInTheDocument()
  })

  it('shows the reference and SYSTEM once an audit is started, with no Device field anywhere', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)

    await startAudit(user)

    expect(scanCountPanel().getByText('AUD-31082026-0012')).toBeInTheDocument()
    expect(scanCountPanel().getByText('SYSTEM')).toBeInTheDocument()
    // Not merely absent from a label: the word must not appear anywhere in
    // the tab's content, since a browser audit has no device at all.
    expect(scanCountPanel().queryByText(/device/i)).not.toBeInTheDocument()
  })

  it('says plainly when Start resumed an audit that already had items counted', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)

    await startAudit(user, audit({ item_count: 4, variance_count: 1 }))

    expect(
      await scanCountPanel().findByText(/resumed audit aud-31082026-0012 with 4 items already counted/i),
    ).toBeInTheDocument()
  })

  it('lists both batches for an ambiguous scan and auto-selects neither', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({
          success: true,
          data: {
            found: true,
            matches: [
              lookupMatch({ item_stock_id: 77, batch: 'B01005' }),
              lookupMatch({ item_stock_id: 78, batch: 'B01006' }),
            ],
          },
        }),
      ),
    )

    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), '8901234500059{Enter}')

    expect(await scanCountPanel().findByText('Batch B01005')).toBeInTheDocument()
    expect(scanCountPanel().getByText('Batch B01006')).toBeInTheDocument()
    // Neither is chosen for the operator — the count panel must not appear.
    expect(scanCountPanel().queryByLabelText(/physical quantity/i)).not.toBeInTheDocument()
  })

  it('goes straight to the count panel for a single-match scan', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({ success: true, data: { found: true, matches: [lookupMatch()] } }),
      ),
    )

    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), '8901234500059{Enter}')

    expect(await scanCountPanel().findByLabelText(/physical quantity/i)).toBeInTheDocument()
    expect(scanCountPanel().getByText('Atorvastatin 10mg Tablet')).toBeInTheDocument()
  })

  it('shows the server message for an unknown code and creates nothing', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    let countWasPosted = false
    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({
          success: true,
          data: { found: false, matches: [] },
          message: 'ZZZ999 was not found in PHM001. Check the barcode, or the product may not be stocked here.',
        }),
      ),
      http.post('*/api/audits/:id/count', () => {
        countWasPosted = true
        return HttpResponse.json({ success: true, data: {} }, { status: 201 })
      }),
    )

    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), 'ZZZ999{Enter}')

    expect(await scanCountPanel().findByText(/was not found in PHM001/i)).toBeInTheDocument()
    expect(scanCountPanel().queryByLabelText(/physical quantity/i)).not.toBeInTheDocument()
    expect(countWasPosted).toBe(false)
  })

  it('shows the correct worded variance as the physical quantity is typed', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({ success: true, data: { found: true, matches: [lookupMatch({ system_qty: 100 })] } }),
      ),
    )
    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), '8901234500059{Enter}')

    const physicalInput = await scanCountPanel().findByLabelText(/physical quantity/i)
    await user.type(physicalInput, '95')

    // System 100, physical 95: five short, worded rather than left to a sign.
    expect(await scanCountPanel().findByText('Short 5')).toBeInTheDocument()
  })

  it('says Match at zero and Excess when the count is over, never colour alone', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({ success: true, data: { found: true, matches: [lookupMatch({ system_qty: 100 })] } }),
      ),
    )
    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), '8901234500059{Enter}')

    const physicalInput = await scanCountPanel().findByLabelText(/physical quantity/i)
    await user.type(physicalInput, '100')
    expect(await scanCountPanel().findByText('Match')).toBeInTheDocument()

    await user.clear(physicalInput)
    await user.type(physicalInput, '108')
    expect(await scanCountPanel().findByText('Excess 8')).toBeInTheDocument()
  })

  it('posts the counted quantities and returns focus to the scanner afterwards', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user)

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({ success: true, data: { found: true, matches: [lookupMatch()] } }),
      ),
    )

    let postedBody: Record<string, unknown> | null = null
    server.use(
      http.post('*/api/audits/:id/count', async ({ request }) => {
        postedBody = (await request.json()) as Record<string, unknown>
        return HttpResponse.json(
          {
            success: true,
            message: 'MED-1005 counted.',
            data: makeAuditLine({ id: 900, item_stock_id: 77, physical_qty: 95, loose_qty: 2, variance_qty: 3 }),
            meta: { audit: audit({ item_count: 1, variance_count: 1 }) },
          },
          { status: 201 },
        )
      }),
    )

    const scanner = scanCountPanel().getByLabelText(/scan or type a barcode/i)
    await user.type(scanner, '8901234500059{Enter}')

    const physicalInput = await scanCountPanel().findByLabelText(/physical quantity/i)
    await user.type(physicalInput, '95')
    await user.type(scanCountPanel().getByLabelText('Loose Quantity'), '2')

    await user.click(scanCountPanel().getByRole('button', { name: /save count/i }))

    await waitFor(() =>
      expect(postedBody).toMatchObject({ item_stock_id: 77, physical_qty: 95, loose_qty: 2 }),
    )
    expect(await scanCountPanel().findByText(/✓ Counted/)).toBeInTheDocument()
    await waitFor(() => expect(scanner).toHaveFocus())
  })

  it('says Updated rather than Counted for a product already on the audit', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const openAudit = audit({ item_count: 1, variance_count: 0 })
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)

    server.use(
      http.post('*/api/audits/start', () =>
        HttpResponse.json({ success: true, data: openAudit }, { status: 201 }),
      ),
    )
    mockLines(openAudit.id as number, [makeAuditLine({ item_stock_id: 77 })])
    await chooseShop(user)
    await user.click(scanCountPanel().getByRole('button', { name: /start new audit/i }))
    await scanCountPanel().findByText(openAudit.audit_ref as string)
    // The existing line has to have actually arrived before the next scan,
    // since "already counted" is read from what the workspace has loaded.
    await scanCountPanel().findByText('Counted so far')

    server.use(
      http.get('*/api/audits/lookup', () =>
        HttpResponse.json({ success: true, data: { found: true, matches: [lookupMatch({ item_stock_id: 77 })] } }),
      ),
      http.post('*/api/audits/:id/count', () =>
        HttpResponse.json(
          {
            success: true,
            data: makeAuditLine({ item_stock_id: 77 }),
            meta: { audit: openAudit },
          },
          { status: 201 },
        ),
      ),
    )

    await user.type(scanCountPanel().getByLabelText(/scan or type a barcode/i), '8901234500059{Enter}')
    const physicalInput = await scanCountPanel().findByLabelText(/physical quantity/i)
    await user.type(physicalInput, '95')
    await user.click(scanCountPanel().getByRole('button', { name: /save count/i }))

    expect(await scanCountPanel().findByText(/✓ Updated/)).toBeInTheDocument()
  })

  it('surfaces the server refusal when completing an audit with nothing counted', async () => {
    mockBackgroundEndpoints()
    mockShops()
    const { user } = renderWithProviders(<StockAuditWorkspacePage />)
    await startAudit(user, audit({ item_count: 0, variance_count: 0 }))

    server.use(
      http.post('*/api/audits/:id/complete', () =>
        HttpResponse.json(
          { success: false, message: 'Count at least one product before completing this audit.' },
          { status: 422 },
        ),
      ),
    )

    await user.click(scanCountPanel().getByRole('button', { name: 'Complete audit' }))
    await user.click(await screen.findByRole('button', { name: /yes, complete audit/i }))

    expect(
      await screen.findByText('Count at least one product before completing this audit.'),
    ).toBeInTheDocument()
  })
})

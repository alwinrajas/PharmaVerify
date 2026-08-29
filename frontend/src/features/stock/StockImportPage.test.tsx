import { fireEvent, screen } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { beforeEach, describe, expect, it } from 'vitest'
import { ImportPreviewPanel, StockImportPage, type StockPreview } from './StockImportPage'
import { AuthProvider } from '@/features/auth/AuthContext'
import { tokenStore } from '@/services/apiClient'
import { server } from '@/test/server'
import { makeAuthUser, renderWithProviders } from '@/test/utils'

/**
 * Stock Import replaces a shop's stock outright — the old rows are deleted.
 *
 * That makes this screen the last place a mistake can be caught, so these
 * tests are about one question: can a replacement happen that the user was not
 * shown first?
 *
 * The upload itself is not driven here. A multipart request never resolves
 * under msw in jsdom, so the two halves are tested where they can be tested
 * honestly: the page for its gating, and the preview panel for what it puts in
 * front of the user. What the server actually does with the file is covered by
 * the backend suite.
 */

/** Any request that would write. */
let importCalls: number

function makePreview(overrides: Partial<StockPreview> = {}): StockPreview {
  return {
    file_name: 'Stock report.xlsx',
    total_rows: 8913,
    valid_rows: 8910,
    invalid_rows: 3,
    locations_detected: 3,
    shops: [
      {
        shop_id: 1,
        shop_code: 'PHM001',
        shop_name: 'Wellness Pharmacy',
        ax_location_id: 'P001',
        existing_records: 4952,
        incoming_records: 4949,
        action: 'replace',
      },
      {
        shop_id: 2,
        shop_code: 'PHM002',
        shop_name: 'Care Pharmacy',
        ax_location_id: 'T033',
        existing_records: 3961,
        incoming_records: 3961,
        action: 'replace',
      },
    ],
    unmatched_locations: [{ ax_location_id: 'ZZ99', rows: 3 }],
    items_referenced: 1200,
    items_matched: 1200,
    items_unmatched: 0,
    gtin_missing: 4,
    gtin_duplicates: [{ gtin: '08840149636445', product_codes: ['MRAQ-1', 'MRAQ-2'] }],
    sample_errors: [
      {
        row_number: 41,
        column_name: 'INVENTLOCATIONID',
        column_value: 'ZZ99',
        error_message: 'No shop is linked to warehouse code ZZ99.',
      },
    ],
    ...overrides,
  }
}

function setup({ permissions = ['stock.view', 'stock.import'] }: { permissions?: string[] } = {}) {
  tokenStore.set('test-token')

  server.use(
    http.get('/api/auth/me', () => HttpResponse.json({ success: true, data: makeAuthUser({ permissions }) })),
    http.get('/api/shops/options', () =>
      HttpResponse.json({ success: true, data: [{ id: 1, label: 'PHM001 — Wellness Pharmacy' }] }),
    ),
    http.get('/api/stock-imports/template', () =>
      HttpResponse.json({ success: true, data: { required: ['ITEMID'], optional: [], note: '' } }),
    ),
    http.get('/api/stock-imports', () =>
      HttpResponse.json({ success: true, data: [], meta: { total: 0, current_page: 1, per_page: 25, last_page: 1 } }),
    ),
    http.post('/api/stock-imports', () => {
      importCalls++

      return HttpResponse.json({ success: true, data: [], meta: {} }, { status: 201 })
    }),
  )

  return renderWithProviders(
    <AuthProvider>
      <StockImportPage />
    </AuthProvider>,
  )
}

/**
 * Picks a file. The input is `hidden` behind a styled label, which userEvent
 * refuses to click, so the change is dispatched on the input itself — the same
 * event the browser raises once a file has been chosen.
 */
async function chooseFile() {
  const label = await screen.findByText(/Choose an Excel file/)
  const input = label.closest('label')!.querySelector('input[type="file"]') as HTMLInputElement

  fireEvent.change(input, {
    target: { files: [new File(['x'], 'Stock report.xlsx')] },
  })

  await screen.findByText(/Stock report\.xlsx/)
}

describe('StockImportPage', () => {
  beforeEach(() => {
    importCalls = 0
    tokenStore.clear()
  })

  it('cannot replace stock until the file has been checked', async () => {
    setup()

    await chooseFile()

    // The whole safety rule in one assertion: a file nobody has looked at
    // cannot replace anything.
    expect(await screen.findByRole('button', { name: /Replace stock/ })).toBeDisabled()
    expect(screen.getByRole('button', { name: /Check file/ })).toBeEnabled()
    expect(screen.getByText(/Check the file first/)).toBeInTheDocument()
    expect(importCalls).toBe(0)
  })

  it('replaces nothing merely by opening the screen', async () => {
    setup()

    expect(await screen.findByText(/Import stock file/)).toBeInTheDocument()
    expect(importCalls).toBe(0)
  })

  it('offers no import controls to a user without the permission', async () => {
    setup({ permissions: ['stock.view'] })

    expect(await screen.findByText(/do not have permission to import stock/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Check file/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Replace stock/ })).not.toBeInTheDocument()
    expect(importCalls).toBe(0)
  })
})

describe('ImportPreviewPanel', () => {
  it('states what each shop holds now and what would take its place', () => {
    renderWithProviders(<ImportPreviewPanel preview={makePreview()} />)

    expect(screen.getByText('PHM001 · Wellness Pharmacy')).toBeInTheDocument()
    expect(screen.getByText('4,952')).toBeInTheDocument()
    expect(screen.getByText('4,949')).toBeInTheDocument()

    // Named as a replacement, not an "import".
    expect(screen.getAllByText('Replace')).toHaveLength(2)
  })

  it('names a location no shop answers to instead of dropping it quietly', () => {
    renderWithProviders(<ImportPreviewPanel preview={makePreview()} />)

    expect(screen.getByText(/Unmatched locations/)).toBeInTheDocument()
    expect(screen.getByText(/ZZ99 — 3 row/)).toBeInTheDocument()
    expect(screen.getByText(/will not be assigned to another shop/)).toBeInTheDocument()
  })

  it('surfaces a GTIN that two products answer to', () => {
    renderWithProviders(<ImportPreviewPanel preview={makePreview()} />)

    expect(screen.getByText(/Shared GTINs/)).toBeInTheDocument()
    expect(screen.getByText(/08840149636445 → MRAQ-1, MRAQ-2/)).toBeInTheDocument()
  })

  it('says why rows were rejected', () => {
    renderWithProviders(<ImportPreviewPanel preview={makePreview()} />)

    expect(screen.getByText(/Row 41 · INVENTLOCATIONID/)).toBeInTheDocument()
    expect(screen.getByText(/No shop is linked to warehouse code ZZ99/)).toBeInTheDocument()
  })

  it('reports a clean file as having no issues', () => {
    renderWithProviders(
      <ImportPreviewPanel
        preview={makePreview({
          invalid_rows: 0,
          unmatched_locations: [],
          gtin_duplicates: [],
          sample_errors: [],
        })}
      />,
    )

    expect(screen.getByText('No issues found')).toBeInTheDocument()
    expect(screen.queryByText(/Unmatched locations/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Shared GTINs/)).not.toBeInTheDocument()
  })

  it('is explicit that checking has changed nothing', () => {
    renderWithProviders(<ImportPreviewPanel preview={makePreview()} />)

    expect(screen.getByText(/nothing has been changed yet/)).toBeInTheDocument()
  })
})

import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HhtPreviewPanel, type HhtImportPreview } from './HhtImportPage'
import { renderWithProviders } from '@/test/utils'

/**
 * What the import screen puts in front of the operator before anything is
 * recorded.
 *
 * The upload itself is not driven here — a multipart request never resolves
 * under msw in jsdom — so the panel is tested directly and the round trip is
 * covered by the backend suite.
 */

function makePreview(overrides: Partial<HhtImportPreview> = {}): HhtImportPreview {
  return {
    kind: 'audit',
    file_name: 'Audit_export.xlsx',
    reference: 'AUD-11082026-0002',
    audit_number: 2,
    audit_date: '2026-08-11',
    shop: { shop_id: 1, shop_code: 'PHM001', shop_name: 'Wellness Pharmacy', ax_location_id: 'P001' },
    device: { device_id: 3, device_code: 'HHT-02' },
    total_rows: 120,
    valid_rows: 118,
    invalid_rows: 2,
    unmatched_locations: [],
    items_matched: 115,
    items_unmatched: 3,
    matched_on: { gtin: 90, product_code: 20, barcode: 5, none: 3 },
    lines_with_loose: 7,
    system_qty_disagreements: 0,
    variance_disagreements: 0,
    sample_errors: [
      {
        row_number: 14,
        column_name: 'PHYSICALQTY',
        column_value: 'nope',
        error_message: 'The physical quantity must be a number.',
      },
    ],
    action: 'create',
    conflict: null,
    ...overrides,
  }
}

describe('HhtPreviewPanel', () => {
  it('names the reference, the shop and what would be recorded', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview()} />)

    expect(screen.getByText('AUD-11082026-0002')).toBeInTheDocument()
    expect(screen.getByText('PHM001')).toBeInTheDocument()
    expect(screen.getByText('118')).toBeInTheDocument()
    expect(screen.getByText(/nothing has been recorded yet/)).toBeInTheDocument()
  })

  it('says which kind of export the file is, from the file itself', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview()} />)
    expect(screen.getByText('Stock Audit')).toBeInTheDocument()

    renderWithProviders(<HhtPreviewPanel preview={makePreview({ kind: 'stock_take' })} />)
    expect(screen.getByText('Stock Take')).toBeInTheDocument()
  })

  it('breaks down how each product was identified, GTIN first', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview()} />)

    expect(screen.getByText('GTIN — 90')).toBeInTheDocument()
    expect(screen.getByText('Item code — 20')).toBeInTheDocument()
    expect(screen.getByText('7-digit barcode — 5')).toBeInTheDocument()
    expect(screen.getByText('No match — 3')).toBeInTheDocument()
  })

  it('asks for a device on an audit, because the export does not name one', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview()} needsDevice />)

    expect(screen.getByText('Choose the handheld')).toBeInTheDocument()
    expect(screen.getByText(/identified by its shop, its device and its number/)).toBeInTheDocument()
  })

  it('states plainly when the file has already been imported', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview({ action: 'duplicate_ignored' })} />)

    expect(screen.getByText('Already imported')).toBeInTheDocument()
    expect(screen.getByText(/Importing again changes nothing/)).toBeInTheDocument()
  })

  it('blocks a reference that exists with different contents', () => {
    renderWithProviders(
      <HhtPreviewPanel
        preview={makePreview({
          action: 'conflict',
          conflict: { audit_id: 8, audit_ref: 'AUD-11082026-0002', source: 'excel' },
        })}
      />,
    )

    expect(screen.getByText('Already recorded, with different contents')).toBeInTheDocument()
    expect(screen.getByText(/Replacing a recorded count is a separate decision/)).toBeInTheDocument()
  })

  it('names a location no shop answers to instead of dropping it quietly', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview({ unmatched_locations: ['ZZ99'] })} />)

    expect(screen.getByText('Unmatched locations')).toBeInTheDocument()
    expect(screen.getByText('ZZ99')).toBeInTheDocument()
    expect(screen.getByText(/will not be assigned to another shop/)).toBeInTheDocument()
  })

  it('explains a system quantity that disagrees with the handheld', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview({ system_qty_disagreements: 4 })} />)

    expect(screen.getByText(/System quantity differs from the handheld/)).toBeInTheDocument()
    expect(screen.getByText(/PharmaVerify's figure is used for the variance/)).toBeInTheDocument()
  })

  it('does not raise a system-quantity warning on a stock take, which has none', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview({ kind: 'stock_take', system_qty_disagreements: 4 })} />)

    expect(screen.queryByText(/System quantity differs/)).not.toBeInTheDocument()
  })

  it('says why rows were rejected', () => {
    renderWithProviders(<HhtPreviewPanel preview={makePreview()} />)

    expect(screen.getByText(/Row 14 · PHYSICALQTY/)).toBeInTheDocument()
    expect(screen.getByText('The physical quantity must be a number.')).toBeInTheDocument()
  })

  it('reports a clean file as having no issues', () => {
    renderWithProviders(
      <HhtPreviewPanel preview={makePreview({ invalid_rows: 0, unmatched_locations: [], sample_errors: [] })} />,
    )

    expect(screen.getByText('No issues found')).toBeInTheDocument()
  })
})

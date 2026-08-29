import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { AuditsPage } from './AuditsPage'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'

/**
 * What the office sees after a handheld submits.
 *
 * The point of these is the wording and the refresh: an audit that has reached
 * the database has been received, must say so, must say it came from a device,
 * and must appear without anyone reloading the page — otherwise the operator
 * assumes the direct submission failed and imports the spreadsheet anyway,
 * which is the workflow this replaced.
 */

function audit(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    audit_number: 7777,
    audit_ref: 'AUD-28082026-7777',
    source: 'api',
    shop_id: 1,
    shop_code: 'P001',
    shop_name: 'Al Manara Pharmacy',
    device_id: 2,
    device_code: 'HHT-02',
    hht_user: 'Karthik Subramani',
    audit_date: '2026-08-28',
    submitted_at: '2026-08-28T14:32:00+04:00',
    item_count: 24,
    variance_count: 3,
    status: 'submitted',
    ...overrides,
  }
}

function serveAudits(rows: Array<Record<string, unknown>>, onCall?: () => void) {
  server.use(
    http.get('*/api/audits', () => {
      onCall?.()
      return HttpResponse.json({
        success: true,
        data: rows,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
      })
    }),
    http.get('*/api/shops/options', () => HttpResponse.json({ success: true, data: [] })),
    http.get('*/api/devices/options', () => HttpResponse.json({ success: true, data: [] })),
  )
}

describe('AuditsPage', () => {
  it('shows a stored audit as Received rather than Submitted', async () => {
    serveAudits([audit()])
    renderWithProviders(<AuditsPage />)

    // "Submitted" describes what the handheld did, which the office cannot
    // observe. This row exists only because the server accepted and stored it.
    //
    // Only the status badge is checked, not the whole page: the timestamp
    // column is legitimately headed "Submitted", because that is the moment
    // the device sent it. The two words are both correct in their own place.
    const status = await screen.findByText('Received')
    expect(status).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /Status/ })).toBeInTheDocument()
  })

  it('shows the reference, shop and device the count came from', async () => {
    serveAudits([audit()])
    renderWithProviders(<AuditsPage />)

    expect(await screen.findByText('AUD-28082026-7777')).toBeInTheDocument()
    expect(screen.getByText(/P001.*HHT-02/)).toBeInTheDocument()
  })

  it('distinguishes a direct submission from an imported spreadsheet', async () => {
    serveAudits([audit(), audit({ id: 2, audit_ref: 'AUD-28082026-7778', source: 'excel' })])
    renderWithProviders(<AuditsPage />)

    // The difference between the live path working and somebody having
    // imported around it.
    expect(await screen.findByText('HHT')).toBeInTheDocument()
    expect(screen.getByText('Excel')).toBeInTheDocument()
  })

  it('announces a count arriving from a device, naming the terminal', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      let rows = [audit()]
      server.use(
        http.get('*/api/audits', () =>
          HttpResponse.json({
            success: true,
            data: rows,
            meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
          }),
        ),
        http.get('*/api/shops/options', () => HttpResponse.json({ success: true, data: [] })),
        http.get('*/api/devices/options', () => HttpResponse.json({ success: true, data: [] })),
      )

      renderWithProviders(<AuditsPage />)
      await screen.findByText('AUD-28082026-7777')

      // A second handheld files while the screen is open and unattended.
      rows = [
        audit({ id: 2, audit_ref: 'AUD-29082026-0042', device_code: 'HHT-03' }),
        ...rows,
      ]
      await vi.advanceTimersByTimeAsync(16_000)

      // The operator at the counter needs to see it land, not discover it by
      // scanning a table they were not watching.
      expect(await screen.findByText(/Received AUD-29082026-0042 from HHT-03/)).toBeInTheDocument()
    } finally {
      vi.useRealTimers()
    }
  })

  it('does not announce the audits already on screen when it first loads', async () => {
    serveAudits([audit()])
    renderWithProviders(<AuditsPage />)

    await screen.findByText('AUD-28082026-7777')

    // Opening the page is not an arrival. Announcing the existing rows would
    // be noise every time somebody navigates here.
    expect(screen.queryByText(/Received AUD-28082026-7777 from/)).not.toBeInTheDocument()
  })

  it('polls, so an audit submitted while the page sits open appears on its own', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      let calls = 0
      serveAudits([audit()], () => {
        calls += 1
      })
      renderWithProviders(<AuditsPage />)

      await waitFor(() => expect(calls).toBe(1))

      // Nobody touches the screen; it has to come and look by itself.
      await vi.advanceTimersByTimeAsync(16_000)
      await waitFor(() => expect(calls).toBeGreaterThan(1))
    } finally {
      vi.useRealTimers()
    }
  })
})

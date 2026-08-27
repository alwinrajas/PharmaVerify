import { screen, waitFor, within } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FinalOutputPage } from './FinalOutputPage'
import { AuthProvider } from '@/features/auth/AuthContext'
import { tokenStore } from '@/services/apiClient'
import { server } from '@/test/server'
import { makeAuthUser, renderWithProviders } from '@/test/utils'

/**
 * Final Output and the share to OneDrive.
 *
 * The rule this screen exists to protect is that a generated file stays inside
 * the application until somebody deliberately sends it out. The backend
 * enforces it, but the screen is where the decision is actually made, so the
 * tests below spend most of their effort on one question: did anything reach
 * the upload endpoint that a user did not ask for?
 */

/** Records every call to the share endpoint so a test can prove it was silent. */
let shareCalls: number[]

function makeOutput(overrides: Record<string, unknown> = {}) {
  return {
    id: 91,
    shop_id: 1,
    shop_code: 'PHM001',
    shop_name: 'Wellness Pharmacy',
    audit_id: 41,
    audit_number: 3,
    device_code: 'HHT-02',
    file_name: 'PHM001_HHT-02_Audit-3_20260826-143349.xlsx',
    record_count: 137,
    verification_status: 'verified',
    adjustment_status: 'adjusted',
    onedrive_status: 'not_uploaded',
    onedrive_url: null,
    upload_attempts: 0,
    last_error: null,
    uploaded_at: null,
    generated_by: 'Arun Prakash',
    generated_at: '2026-08-26T14:33:49+05:30',
    ...overrides,
  }
}

/**
 * @param rows      what the list returns
 * @param driver    the OneDrive driver the API reports in meta
 * @param shareBody how the share endpoint answers, if it is reached at all
 */
function setup({
  rows = [makeOutput()],
  permissions = ['finaloutput.view', 'finaloutput.generate', 'onedrive.share'],
  driver = 'graph',
  share = () => HttpResponse.json({ success: true, message: 'Uploaded to OneDrive.', data: makeOutput({ onedrive_status: 'uploaded' }) }),
}: {
  rows?: ReturnType<typeof makeOutput>[]
  permissions?: string[]
  driver?: string
  share?: () => Response
} = {}) {
  tokenStore.set('test-token')

  server.use(
    http.get('/api/auth/me', () => HttpResponse.json({ success: true, data: makeAuthUser({ permissions }) })),
    http.get('/api/shops/options', () => HttpResponse.json({ success: true, data: [] })),
    http.get('/api/audits', () => HttpResponse.json({ success: true, data: [], meta: { total: 0 } })),
    http.get('/api/final-outputs', () =>
      HttpResponse.json({
        success: true,
        data: rows,
        meta: { total: rows.length, current_page: 1, per_page: 25, last_page: 1, onedrive_driver: driver },
      }),
    ),
    http.post('/api/final-outputs/:id/share-onedrive', ({ params }) => {
      shareCalls.push(Number(params.id))
      return share()
    }),
  )

  return renderWithProviders(
    <AuthProvider>
      <FinalOutputPage />
    </AuthProvider>,
  )
}

/** The row's Share (or Retry) button, then the dialog's confirm button. */
async function openShareDialog(user: ReturnType<typeof renderWithProviders>['user'], name: RegExp = /^Share$/) {
  await user.click(await screen.findByRole('button', { name }))

  return within(await screen.findByRole('dialog'))
}

describe('FinalOutputPage', () => {
  beforeEach(() => {
    shareCalls = []
    tokenStore.clear()
  })

  // ------------------------------------------------ nothing leaves by itself

  it('uploads nothing merely by showing the list', async () => {
    setup()

    expect(await screen.findByText(/PHM001_HHT-02_Audit-3/)).toBeInTheDocument()

    // The whole business rule in one assertion.
    expect(shareCalls).toEqual([])
  })

  it('asks for confirmation before sharing, and uploads nothing until it is given', async () => {
    const { user } = setup()

    const dialog = await openShareDialog(user)

    expect(dialog.getByText(/will be uploaded to OneDrive now/i)).toBeInTheDocument()

    // Opening the confirmation is not consent.
    expect(shareCalls).toEqual([])
  })

  it('does not upload when the confirmation is dismissed', async () => {
    const { user } = setup()

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(shareCalls).toEqual([])
  })

  it('uploads the chosen file only once the user confirms', async () => {
    const { user } = setup()

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: /share to onedrive/i }))

    await waitFor(() => expect(shareCalls).toEqual([91]))

    expect(await screen.findByText('Uploaded to OneDrive.')).toBeInTheDocument()
  })

  it('tells the user this is the point at which the file leaves the application', async () => {
    const { user } = setup()

    const dialog = await openShareDialog(user)

    expect(dialog.getByText(/only point at which the file leaves the application/i)).toBeInTheDocument()
  })

  it('says that generating a final output uploads nothing', async () => {
    const { user } = setup()

    await user.click(await screen.findByRole('button', { name: /generate final output/i }))

    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByText(/not uploaded anywhere/i)).toBeInTheDocument()
    expect(shareCalls).toEqual([])
  })

  // ----------------------------------------------------------- already shared

  it('offers no share button for a file that has already been uploaded', async () => {
    setup({ rows: [makeOutput({ onedrive_status: 'uploaded', upload_attempts: 1, uploaded_at: '2026-08-26T15:00:00+05:30' })] })

    expect(await screen.findByText(/PHM001_HHT-02_Audit-3/)).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /^Share$/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Retry$/ })).not.toBeInTheDocument()
    expect(shareCalls).toEqual([])
  })

  // ------------------------------------------------------------- permissions

  it('offers no share button to a user without the share permission', async () => {
    setup({ permissions: ['finaloutput.view'] })

    expect(await screen.findByText(/PHM001_HHT-02_Audit-3/)).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /^Share$/ })).not.toBeInTheDocument()
    // The row is still readable and downloadable — only sharing is withheld.
    expect(screen.getByRole('button', { name: /download/i })).toBeInTheDocument()
  })

  it('offers no generate button to a user without the generate permission', async () => {
    setup({ permissions: ['finaloutput.view', 'onedrive.share'] })

    expect(await screen.findByText(/PHM001_HHT-02_Audit-3/)).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /generate final output/i })).not.toBeInTheDocument()
  })

  // --------------------------------------------------------- failure states

  it('reports a refused upload without claiming it succeeded', async () => {
    const { user } = setup({
      share: () =>
        HttpResponse.json(
          { success: false, message: 'OneDrive refused the upload. The application does not have permission to write to this folder.' },
          { status: 502 },
        ),
    })

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: /share to onedrive/i }))

    expect(await screen.findByText(/does not have permission to write to this folder/i)).toBeInTheDocument()
    expect(screen.queryByText(/Uploaded to OneDrive\./)).not.toBeInTheDocument()
  })

  it('passes on a throttling message rather than a generic failure', async () => {
    const { user } = setup({
      share: () =>
        HttpResponse.json(
          { success: false, message: 'Too many requests. Please try again in 43 seconds.' },
          { status: 429 },
        ),
    })

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: /share to onedrive/i }))

    expect(await screen.findByText(/try again in 43 seconds/i)).toBeInTheDocument()
  })

  it('reports a refusal by the server even though the button was shown', async () => {
    // The frontend hides what a user cannot do, but the API is the authority.
    const { user } = setup({
      share: () => HttpResponse.json({ success: false, message: 'You do not have access to this shop.' }, { status: 403 }),
    })

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: /share to onedrive/i }))

    expect(await screen.findByText(/do not have access to this shop/i)).toBeInTheDocument()
  })

  it('does not claim success when the upload never reached the server', async () => {
    const { user } = setup({ share: () => HttpResponse.error() as unknown as Response })

    const dialog = await openShareDialog(user)
    await user.click(dialog.getByRole('button', { name: /share to onedrive/i }))

    await waitFor(() => expect(screen.queryByText(/Uploaded to OneDrive\./)).not.toBeInTheDocument())
  })

  // ------------------------------------------------------------------ retry

  it('offers a retry, not a first attempt, for a file whose upload failed', async () => {
    const { user } = setup({
      rows: [
        makeOutput({
          onedrive_status: 'failed',
          upload_attempts: 2,
          last_error: 'The destination folder could not be found in OneDrive.',
        }),
      ],
    })

    expect(await screen.findByRole('button', { name: /^Retry$/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Share$/ })).not.toBeInTheDocument()

    // The banner surfaces the reason without the user opening anything.
    expect(screen.getByText(/destination folder could not be found/i)).toBeInTheDocument()
    expect(screen.getByText(/did not complete/i)).toBeInTheDocument()

    const dialog = await openShareDialog(user, /^Retry$/)
    expect(dialog.getByRole('button', { name: /retry upload/i })).toBeInTheDocument()
    expect(shareCalls).toEqual([])
  })

  it('shows how many attempts a file has already had', async () => {
    setup({ rows: [makeOutput({ onedrive_status: 'failed', upload_attempts: 2, last_error: 'Timed out.' })] })

    expect(await screen.findByText('2 attempts')).toBeInTheDocument()
  })

  // ----------------------------------------------------------- driver notice

  it('says plainly when uploads are only being simulated', async () => {
    setup({ driver: 'demo' })

    expect(await screen.findByText(/OneDrive is running in demonstration mode/i)).toBeInTheDocument()
  })

  it('shows no demonstration notice when the real Graph driver is configured', async () => {
    setup({ driver: 'graph' })

    expect(await screen.findByText(/PHM001_HHT-02_Audit-3/)).toBeInTheDocument()
    expect(screen.queryByText(/demonstration mode/i)).not.toBeInTheDocument()
  })

  // -------------------------------------------------------------- list state

  it('shows the error state, not an empty list, when the list cannot be loaded', async () => {
    tokenStore.set('test-token')
    server.use(
      http.get('/api/auth/me', () => HttpResponse.json({ success: true, data: makeAuthUser({ permissions: ['finaloutput.view'] }) })),
      http.get('/api/shops/options', () => HttpResponse.json({ success: true, data: [] })),
      http.get('/api/final-outputs', () =>
        HttpResponse.json({ success: false, message: 'The final outputs could not be loaded.' }, { status: 500 }),
      ),
    )

    renderWithProviders(
      <AuthProvider>
        <FinalOutputPage />
      </AuthProvider>,
    )

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument()
  })

  it('invites the user to generate one when there is nothing to list', async () => {
    setup({ rows: [] })

    expect(await screen.findByText('No final output generated')).toBeInTheDocument()
    expect(shareCalls).toEqual([])
  })
})

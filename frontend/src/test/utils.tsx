import { ThemeProvider } from '@mui/material'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, type RenderOptions } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SnackbarProvider } from 'notistack'
import type { ReactElement, ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { theme } from '@/theme'

/**
 * Renders a component inside the providers the real application supplies.
 *
 * Retries are off so a deliberately failing request surfaces immediately
 * instead of being retried while the test waits.
 */
export function renderWithProviders(
  ui: ReactElement,
  { route = '/', ...options }: RenderOptions & { route?: string } = {},
) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0 },
      mutations: { retry: false },
    },
  })

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <ThemeProvider theme={theme}>
        <QueryClientProvider client={queryClient}>
          <SnackbarProvider>
            <MemoryRouter initialEntries={[route]}>{children}</MemoryRouter>
          </SnackbarProvider>
        </QueryClientProvider>
      </ThemeProvider>
    )
  }

  return {
    user: userEvent.setup(),
    queryClient,
    ...render(ui, { wrapper: Wrapper, ...options }),
  }
}

/** A signed-in user, shaped as `/auth/me` returns one. */
export function makeAuthUser(overrides: Partial<AuthUserShape> = {}): AuthUserShape {
  return {
    id: 1,
    name: 'Arun Prakash',
    email: 'admin@pharmaverify.com',
    employee_code: 'EMP-1001',
    phone: null,
    status: 'active',
    last_login_at: null,
    roles: ['Administrator'],
    permissions: ['shops.view', 'audits.view', 'adjustments.create', 'verification.edit'],
    shops: [],
    ...overrides,
  }
}

interface AuthUserShape {
  id: number
  name: string
  email: string
  employee_code: string | null
  phone: string | null
  status: string
  last_login_at: string | null
  roles: string[]
  permissions: string[]
  shops: unknown[]
}

/** A counted audit line, shaped as the API returns one. */
export function makeAuditLine(overrides: Record<string, unknown> = {}) {
  return {
    id: 512,
    audit_id: 41,
    audit_number: 3,
    shop_id: 1,
    shop_code: 'PHM001',
    device_code: 'HHT-02',
    item_stock_id: 77,
    product_code: 'MED-1005',
    barcode: '8901234500059',
    description: 'Atorvastatin 10mg Tablet',
    system_qty: 125,
    physical_qty: 120,
    loose_qty: 0,
    source_system_qty: null,
    // System - (Physical + Loose) = 125 - 120 = 5. Positive is short.
    variance_qty: 5,
    uom: 'STRIP',
    price: 94.3,
    batch: 'B01005',
    expiry_date: '2027-06-30',
    shelf_location: 'A-02',
    is_unknown_item: false,
    verification_status: 'pending',
    adjustment_status: 'not_adjusted',
    verified_by: null,
    verified_at: null,
    adjusted_at: null,
    remarks: null,
    ...overrides,
  }
}

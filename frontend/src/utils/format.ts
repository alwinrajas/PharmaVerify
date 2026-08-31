import dayjs from 'dayjs'

/** Quantities: up to three decimals, but never trailing zeroes. */
export function formatQuantity(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)

  if (Number.isNaN(amount)) return '—'

  return String(Number(amount.toFixed(3)))
}

/**
 * Same as formatQuantity, but a positive value carries an explicit "+" —
 * used for a delta such as a stock adjustment, where the sign is the point.
 */
export function formatSignedQuantity(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)

  if (Number.isNaN(amount)) return '—'

  const formatted = formatQuantity(amount)

  return amount > 0 ? `+${formatted}` : formatted
}

/** Money: always two decimals with thousands separators. */
export function formatMoney(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)

  if (Number.isNaN(amount)) return '—'

  return amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function formatNumber(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)

  return Number.isNaN(amount) ? '—' : amount.toLocaleString('en-IN')
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'

  const date = dayjs(value)

  return date.isValid() ? date.format('DD MMM YYYY') : '—'
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'

  const date = dayjs(value)

  return date.isValid() ? date.format('DD MMM YYYY, HH:mm') : '—'
}

/** "2 hours ago" style, used on dashboard activity lists. */
export function formatRelative(value: string | null | undefined): string {
  if (!value) return '—'

  const date = dayjs(value)

  if (!date.isValid()) return '—'

  const minutes = dayjs().diff(date, 'minute')

  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes} min ago`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`

  const days = Math.floor(hours / 24)
  if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`

  return date.format('DD MMM YYYY')
}

/** Renders a report cell according to the column type sent by the API. */
export function formatByType(value: unknown, type: string): string {
  if (value === null || value === undefined || value === '') return '—'

  switch (type) {
    case 'money':
      return formatMoney(value as number)
    case 'decimal':
      return formatQuantity(value as number)
    case 'number':
      return formatNumber(value as number)
    case 'date':
      return formatDate(value as string)
    case 'datetime':
      return formatDateTime(value as string)
    default:
      return String(value)
  }
}

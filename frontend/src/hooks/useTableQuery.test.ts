import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useTableQuery } from './useTableQuery'

/**
 * The query state behind every list screen.
 *
 * The behaviour that matters is the page reset: changing a filter or the search
 * term while on page 5 must go back to page 1, or the user sees an empty table
 * and believes there are no results.
 */
describe('useTableQuery', () => {
  it('starts on the first page with the requested sort', () => {
    const { result } = renderHook(() => useTableQuery({ sortBy: 'shop_code', sortDir: 'asc' }))

    expect(result.current.page).toBe(0)
    expect(result.current.sortBy).toBe('shop_code')
    expect(result.current.sortDir).toBe('asc')
    expect(result.current.hasFilters).toBe(false)
  })

  it('sends a one-based page to the API while counting from zero internally', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(3))

    expect(result.current.page).toBe(3)
    expect(result.current.params.page).toBe(4)
  })

  it('returns to the first page when a filter changes', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(4))
    expect(result.current.page).toBe(4)

    act(() => result.current.setFilter('status', 'active'))

    expect(result.current.page).toBe(0)
    expect(result.current.params.status).toBe('active')
  })

  it('returns to the first page when the search term changes', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(2))
    act(() => result.current.setSearch('paracetamol'))

    expect(result.current.page).toBe(0)
    expect(result.current.params.search).toBe('paracetamol')
  })

  it('returns to the first page when the page size changes', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(6))
    act(() => result.current.setPerPage(100))

    expect(result.current.page).toBe(0)
    expect(result.current.params.per_page).toBe(100)
  })

  it('keeps the page when only the sort changes', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(2))
    act(() => result.current.setSort('description', 'desc'))

    expect(result.current.page).toBe(2)
    expect(result.current.params.sort_by).toBe('description')
    expect(result.current.params.sort_dir).toBe('desc')
  })

  it('leaves empty filters out of the query entirely', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setFilter('status', 'active'))
    act(() => result.current.setFilter('shop_id', ''))

    expect(result.current.params).toHaveProperty('status', 'active')
    expect(result.current.params).not.toHaveProperty('shop_id')
  })

  it('trims the search term and omits it when blank', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setSearch('   '))
    expect(result.current.params).not.toHaveProperty('search')

    act(() => result.current.setSearch('  amoxicillin  '))
    expect(result.current.params.search).toBe('amoxicillin')
  })

  it('reports whether anything is filtering the list', () => {
    const { result } = renderHook(() => useTableQuery())

    expect(result.current.hasFilters).toBe(false)

    act(() => result.current.setFilter('status', 'active'))
    expect(result.current.hasFilters).toBe(true)
    expect(result.current.activeFilters).toBe(1)

    act(() => result.current.setSearch('x'))
    expect(result.current.activeFilters).toBe(1)
    expect(result.current.hasFilters).toBe(true)
  })

  it('clears the search and every filter together, back to page one', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setFilters({ status: 'active', shop_id: '2' }))
    act(() => result.current.setSearch('paracetamol'))
    act(() => result.current.setPage(3))

    act(() => result.current.clearFilters())

    expect(result.current.page).toBe(0)
    expect(result.current.search).toBe('')
    expect(result.current.hasFilters).toBe(false)
    expect(result.current.params).not.toHaveProperty('status')
    expect(result.current.params).not.toHaveProperty('shop_id')
    expect(result.current.params).not.toHaveProperty('search')
  })

  it('sets several filters at once and resets the page', () => {
    const { result } = renderHook(() => useTableQuery())

    act(() => result.current.setPage(5))
    act(() => result.current.setFilters({ shop_id: '1', variance: 'negative' }))

    expect(result.current.page).toBe(0)
    expect(result.current.params.shop_id).toBe('1')
    expect(result.current.params.variance).toBe('negative')
  })
})

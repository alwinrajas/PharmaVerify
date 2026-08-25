import { useCallback, useMemo, useState } from 'react'

export interface TableQueryState {
  page: number
  perPage: number
  search: string
  sortBy: string
  sortDir: 'asc' | 'desc'
  filters: Record<string, string>
}

/**
 * Query state for a list screen: page, page size, search, sort and filters.
 *
 * Changing a filter or the search term resets to the first page, which is
 * almost always what the user means and easy to forget screen by screen.
 */
export function useTableQuery(initial?: Partial<TableQueryState>) {
  const [state, setState] = useState<TableQueryState>({
    page: 0,
    perPage: 25,
    search: '',
    sortBy: initial?.sortBy ?? 'id',
    sortDir: initial?.sortDir ?? 'desc',
    filters: initial?.filters ?? {},
    ...(initial ?? {}),
  })

  const setPage = useCallback((page: number) => setState((current) => ({ ...current, page })), [])

  const setPerPage = useCallback(
    (perPage: number) => setState((current) => ({ ...current, perPage, page: 0 })),
    [],
  )

  const setSearch = useCallback(
    (search: string) => setState((current) => ({ ...current, search, page: 0 })),
    [],
  )

  const setSort = useCallback(
    (sortBy: string, sortDir: 'asc' | 'desc') => setState((current) => ({ ...current, sortBy, sortDir })),
    [],
  )

  const setFilter = useCallback(
    (key: string, value: string) =>
      setState((current) => ({
        ...current,
        page: 0,
        filters: { ...current.filters, [key]: value },
      })),
    [],
  )

  const setFilters = useCallback(
    (filters: Record<string, string>) =>
      setState((current) => ({ ...current, page: 0, filters: { ...current.filters, ...filters } })),
    [],
  )

  const clearFilters = useCallback(
    () => setState((current) => ({ ...current, page: 0, search: '', filters: {} })),
    [],
  )

  const activeFilters = useMemo(
    () => Object.values(state.filters).filter((value) => value !== '' && value !== undefined).length,
    [state.filters],
  )

  /** The query string sent to the API, with empty values stripped out. */
  const params = useMemo(() => {
    const query: Record<string, string | number> = {
      page: state.page + 1,
      per_page: state.perPage,
      sort_by: state.sortBy,
      sort_dir: state.sortDir,
    }

    if (state.search.trim() !== '') {
      query.search = state.search.trim()
    }

    Object.entries(state.filters).forEach(([key, value]) => {
      if (value !== '' && value !== undefined && value !== null) {
        query[key] = value
      }
    })

    return query
  }, [state])

  return {
    ...state,
    params,
    activeFilters,
    hasFilters: activeFilters > 0 || state.search.trim() !== '',
    setPage,
    setPerPage,
    setSearch,
    setSort,
    setFilter,
    setFilters,
    clearFilters,
  }
}

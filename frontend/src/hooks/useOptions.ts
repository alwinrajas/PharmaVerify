import { useQuery } from '@tanstack/react-query'
import { get } from '@/services/apiClient'
import type { SelectOption } from '@/types'

/** The shops this user may work with, for every shop selector in the app. */
export function useShopOptions() {
  return useQuery({
    queryKey: ['shop-options'],
    queryFn: async () => (await get<SelectOption[]>('/shops/options')).data,
    staleTime: 5 * 60 * 1000,
  })
}

/** Devices, optionally narrowed to one shop. */
export function useDeviceOptions(shopId?: string | number | null) {
  return useQuery({
    queryKey: ['device-options', shopId ?? 'all'],
    queryFn: async () =>
      (await get<SelectOption[]>('/devices/options', shopId ? { shop_id: shopId } : undefined)).data,
    staleTime: 5 * 60 * 1000,
  })
}

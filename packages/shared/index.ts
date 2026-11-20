export const DEFAULT_CURRENCY = 'UGX'

export type Role = 'admin' | 'manager' | 'cashier'

export type Cap = 'view_costs'

export const PAGES = [
  'dashboard','search','sales','receipts','sales_history','products','purchases','suppliers',
  'credits','creditors','expenses','reports','balance','repairs','settings','users','account',
  'activity','crm','cms',
] as const

export type PageKey = (typeof PAGES)[number]

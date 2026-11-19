import { DEFAULT_CURRENCY } from '@hybrid/shared'

// Legacy equivalent: money() in lib/functions.php
export const money = (n: number | string, currency = DEFAULT_CURRENCY) =>
  `${currency} ${Number(n).toLocaleString('en-US', { minimumFractionDigits: 2 })}`

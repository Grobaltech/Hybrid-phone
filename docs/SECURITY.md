# Security model and release checklist

- Browser uses only the Supabase anon key; privileged operations use the Cloudflare Worker service-role secret.
- Supabase RLS is enabled on application tables. Apply migrations `0001`–`0009` in order.
- Cost columns are not granted for direct browser reads. `sales_reporting` and `inventory_value()` disclose cost/profit only when `app_has_cap('view_costs')` is true (admins are allowed by the helper).
- Sales, purchase receiving, customer-credit repayment and creditor repayment use transaction-safe PostgreSQL functions. Direct sale/credit/payment writes are revoked from the browser role.
- User creation and account disabling are performed by the Worker using Supabase Auth Admin API. The service-role key must never appear in `apps/web`, GitHub source or client build variables.
- Profile users may update only their own contact/about fields; they cannot update their own role or disabled status.
- `branding` is public-read; staff photos and private staff documents are private Storage buckets with owner/admin policies.
- Worker CORS must be restricted to the actual Pages/custom-domain origin.
- Do not use the old PHP `reset_admin.php` password (`admin123`) in production. Create a new Supabase Auth administrator.
- Do not commit `.env`, `.dev.vars`, SQL dumps, real customer exports, staff IDs, CVs or uploads.

## Before go-live

- [ ] Apply all migrations and confirm no SQL errors.
- [ ] Create first admin Auth user and matching `profiles` row.
- [ ] Configure GitHub secrets and Actions variables; confirm Worker secrets exist.
- [ ] Test login and role/page access for admin, manager and cashier accounts.
- [ ] Test cost/profit access with and without `view_costs`.
- [ ] Test stock deduction, insufficient-stock rejection, purchase receiving, partial/full repayments and concurrent sales.
- [ ] Confirm backups and restore procedure for Supabase.
- [ ] Confirm private Storage policies with non-admin and admin accounts.
- [ ] Compare imported record counts and balances with the legacy MySQL installation.

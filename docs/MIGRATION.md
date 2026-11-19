# Local PHP / MySQL to Cloud Edition migration

The authoritative legacy implementation is `legacy/php-app/` and the supplied original MySQL dump is the source for the old table structure. The cloud application is PostgreSQL-backed; the MySQL dump is **not** directly executable in Supabase.

## Page/function mapping

| Legacy PHP page | Cloud view | Cloud data/workflow |
|---|---|---|
| `login.php`, `logout.php` | `LoginView` / auth store | Supabase Auth; username is mapped to `<username>@shop.local` |
| `dashboard.php` | `DashboardView` | today's sales, revenue, stock, customer credit and expenses |
| `search.php`, `api/search*.php` | `SearchView` | products and customers |
| `sales.php` | `SalesView` | `create_sale` PostgreSQL RPC; transaction locks stock and deducts quantities |
| `receipts.php`, `receipt.php`, `receipt_pdf.php` | `ReceiptsView`, `ReceiptDetailView` | sale and sale-item rows; browser Print → Save as PDF |
| `sales_history.php` | `SalesHistoryView` | sales ledger |
| `products.php`, `product_edit.php` | `ProductsView`, `ProductEditView` | products |
| `purchases.php` | `PurchasesView` | `receive_purchase` RPC; records purchase, purchase items, updates stock and cost |
| `suppliers.php` | `SuppliersView` | suppliers |
| `credits.php` | `CreditsView` | credits and `record_credit_payment` RPC |
| `creditors.php` | `CreditorsView` | creditors and `record_creditor_payment` RPC |
| `expenses.php` | `ExpensesView` | expenses |
| `reports.php` | `ReportsView` | sales/profit/expenses for date range |
| `balance.php`, `balance_pdf.php` | `BalanceView` | receivables, payables, inventory cost and expenses; print to PDF |
| `settings.php` | `SettingsView` | settings key/value records |
| `users.php` | `UsersView` | Supabase Auth Admin API plus profiles; role and disabled state |
| `account.php` | `AccountView` | current user's profile |
| `activity.php` | `ActivityView` | `audit_logs`; business-table audit triggers are installed in migration 0007 |
| New CRM | `CrmHomeView` | customers, contact notes and follow-up reminders |
| New CMS | `ContentHomeView` | `site_content` pages/notices |

## Legacy table mapping

| MySQL table | PostgreSQL destination | Notes |
|---|---|---|
| `products` | `products` | Preserve `id`, SKU, name, category, barcode, `price_cost`, `price_sell`, `stock`, `quantity`, timestamps. The old sale/purchase pages use `stock` for inventory movements. `track_inventory` is a new switch for non-stock services; mark repair/labour service items as false after import. |
| `customers` | `customers` | Preserve IDs, name, phone, email, timestamps. |
| `suppliers` | `suppliers` | Preserve IDs and contact fields. |
| `purchases`, `purchase_items` | same names | `total` and `total_cost` are retained; preserve packs/pack_size where populated. |
| `sales`, `sale_items` | same names | Preserve receipt numbers, tax, payment method, `actual_price`, cost and profit snapshots. `created_by` is a new Supabase profile UUID and can be null for imported sales. |
| `credits`, `credit_payments` | same names | Preserve opening balance and payment history; migration 0005 includes compatibility columns. |
| `creditors`, `creditor_payments` | same names | Preserve opening amount, paid amount, balance and payment history. |
| `expenses` | `expenses` | Preserve date/category/amount/note. |
| `settings` | `settings` | Preserve `k` and `v`. |
| `audit_log`, `audit_logs` | `audit_logs` | The two legacy audit tables have different shapes. Map `audit_log.entity` to `entity_type`, `details` to JSONB, and `audit_logs` columns to their matching fields. Keep original timestamps. |
| `users` | Supabase Auth + `profiles` | Do not import `password_hash` as a Supabase password. Recreate users in Auth and map the old numeric user ID to `profiles.legacy_user_id`. |
| `user_caps`, `user_page_access` | same names | Convert numeric `user_id` to the new profile UUID using `legacy_user_id`. Preserve allowed flags and page names. |

## Recommended import sequence

1. Back up the MySQL database and the `uploads/` directory separately. Keep the backup private.
2. Apply Supabase migrations `0001` through `0007` first.
3. Export each legacy table to CSV using UTF-8. Do not export password hashes or private identity documents into a public repository.
4. Import parent/reference tables first: `suppliers`, `products`, `customers`, `creditors`, `settings`.
5. Import transactional tables in dependency order: `purchases`, `purchase_items`, `sales`, `sale_items`, `credits`, `credit_payments`, `creditor_payments`, `expenses`.
6. Create each staff member in Supabase Auth. Insert the matching `profiles` row with `legacy_user_id` set to the old `users.id`, then import `user_caps` and `user_page_access` by joining the old numeric IDs to the new UUIDs.
7. Import the two audit tables into `audit_logs` after mapping fields. Do not import IP addresses/user-agent data unless there is a legitimate retention need.
8. After CSV imports that include explicit IDs, reset each identity sequence to the maximum imported ID. For example:

```sql
select setval(pg_get_serial_sequence('public.products','id'), coalesce((select max(id) from public.products), 1), true);
select setval(pg_get_serial_sequence('public.customers','id'), coalesce((select max(id) from public.customers), 1), true);
select setval(pg_get_serial_sequence('public.sales','id'), coalesce((select max(id) from public.sales), 1), true);
```

Repeat for every imported identity table (`suppliers`, `purchases`, `purchase_items`, `sale_items`, `credits`, `credit_payments`, `creditors`, `creditor_payments`, `expenses`, and `audit_logs`).

## User/account conversion

- Supabase Auth email is internally generated as `<lowercase-username>@shop.local`; the user types only the username in the app login form.
- Staff passwords must be reset or issued securely; do not try to reuse the PHP `password_hash` column.
- The first administrator must be created in Supabase Dashboard and have a `profiles` row with `role='admin'` before using the Users screen.
- Map old profile fields `phone`, `email`, `national_id`, `about`, `photo_path`, `id_photo_path`, and `cv_path` only when needed. Keep identity/CV files in private Supabase Storage buckets, never public buckets.

## Validation before switching the live shop

- Compare counts and totals between MySQL and Supabase for products, stock, purchases, sales, credits, creditors and expenses.
- Test a sale with multiple products, insufficient stock, cash payment and credit payment.
- Test partial and full customer-credit repayments and creditor repayments.
- Verify each role's route access and RLS behavior with dedicated admin, manager and cashier accounts.
- Print sample receipts and balance reports and confirm currency/tax display.
- Keep the old PHP/MySQL installation read-only until the cloud system has passed acceptance testing.

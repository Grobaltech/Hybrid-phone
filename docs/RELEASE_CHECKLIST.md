# First deployment checklist

1. Create the Supabase project and apply migrations `0001` through `0009` in order.
2. Create the first Auth user and matching admin profile using the README instructions.
3. Set `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY`, and `VITE_API_URL` as GitHub Actions secrets/variables.
4. Set `SUPABASE_SERVICE_ROLE_KEY` and Cloudflare credentials as GitHub secrets; set `ALLOWED_ORIGIN` as a GitHub Actions variable.
5. Create Cloudflare Pages project `hybrid-shop` and deploy the Worker `hybrid-shop-api`.
6. Confirm `/health` returns `{ "ok": true }` on the Worker URL.
7. Sign in as admin, create test manager and cashier users, and test their access.
8. Test service items (`track_inventory=false`), normal stock products, cash and credit sales, purchase orders, receiving, repayments, receipt printing and date reports.
9. Import production data following `docs/MIGRATION.md`; never put the source MySQL dump in GitHub.
10. Compare database totals and run a restore test before making the cloud app the only live system.

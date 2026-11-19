# Hybrid Shop — Cloud Edition

A hosted rewrite of the local PHP/XAMPP point-of-sale application. The legacy PHP source is retained under `legacy/php-app/` as the business-logic and data-migration reference; it is **not** deployed to Cloudflare.

## Architecture

| Layer | Technology | Hosting |
|---|---|---|
| Web POS and admin | Vue 3, TypeScript, Vite, Pinia | Cloudflare Pages |
| Privileged API | Hono on Cloudflare Workers | Cloudflare Workers |
| Database, authentication, storage | Supabase Auth + PostgreSQL + Storage | Supabase |

Implemented workspaces include dashboard, sales with atomic stock deduction, receipts/print-to-PDF, sales history, product and supplier management, stock receiving, customer credits and repayments, creditors and repayments, expenses, reports, balance overview, customer records/CRM notes/follow-ups, company content, user administration, activity log, account and settings.

## 1. Create the Supabase project

1. Create a Supabase project and keep its URL, anon/public key and service-role key available.
2. In the Supabase SQL Editor, apply migrations in filename order: `0001_init_schema.sql` through `0009_storage_policies.sql`.
3. Do **not** put the service-role key in the browser, GitHub source, or `VITE_*` variables. It is used only by the Worker.
4. For local demo data only, run `supabase/seed.sql`. It inserts fake records, not real business data.
5. Create the first administrator in Supabase Dashboard → Authentication → Users. Use an email in the form `admin@shop.local`, confirm the user, then run the following SQL with the actual Auth user UUID:

```sql
insert into public.profiles (id, username, role, email)
values ('AUTH-USER-UUID-HERE', 'admin', 'admin', 'your-real-contact-email');
```

The login screen accepts the username and Supabase Auth password. Subsequent staff accounts are created in the app's Users workspace.

## 2. Local development

Requirements: Node.js 20+, npm, Supabase project.

```bash
cp .env.example .env
# Fill in VITE_SUPABASE_URL, VITE_SUPABASE_ANON_KEY, VITE_API_URL.
npm install
npm run dev:web
```

In another terminal, run the API:

```bash
npm run dev:api
```

For local Worker secrets, create `apps/api/.dev.vars` (ignored by git) containing `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`, and `ALLOWED_ORIGIN=http://localhost:5173`.

## 3. Deploy to Cloudflare

Create a Cloudflare Pages project named `hybrid-shop` and a Workers deployment named `hybrid-shop-api`. Add these GitHub repository secrets:

- `CLOUDFLARE_API_TOKEN` — token with Pages edit and Workers edit permissions.
- `CLOUDFLARE_ACCOUNT_ID`.
- `VITE_SUPABASE_URL`.
- `VITE_SUPABASE_ANON_KEY`.
- `SUPABASE_SERVICE_ROLE_KEY` — server-only.

Add these GitHub Actions variables:

- `VITE_API_URL` — the deployed Worker URL, e.g. `https://hybrid-shop-api.<your-subdomain>.workers.dev`.
- `ALLOWED_ORIGIN` — the exact Pages production origin, e.g. `https://hybrid-shop.pages.dev` (or your custom domain origin).

Push to `main` or run the **Deploy to Cloudflare** workflow manually. The workflow builds/deploys Pages and deploys the API Worker. Configure the same Worker secrets in Cloudflare if deploying manually:

```bash
cd apps/api
npx wrangler secret put SUPABASE_URL
npx wrangler secret put SUPABASE_SERVICE_ROLE_KEY
npx wrangler secret put ALLOWED_ORIGIN
npx wrangler deploy
```

For Pages manual deployment, from the repository root:

```bash
npm install
VITE_SUPABASE_URL=... VITE_SUPABASE_ANON_KEY=... VITE_API_URL=... npm run build -w apps/web
npx wrangler pages deploy apps/web/dist --project-name=hybrid-shop
```

In Supabase → Authentication → URL Configuration, set the production Site URL to your Pages/custom domain and add the local and production URLs to Redirect URLs. Configure the Storage buckets described in `supabase/storage-buckets.md` before enabling staff photo/ID/CV uploads.

## 4. Migrating existing MySQL data

The old application database is MySQL/MariaDB (`php_pos`). The new application uses PostgreSQL, so do not upload the old `.sql` dump directly into Supabase. See `docs/MIGRATION.md` for table mapping, import order, user-account conversion, and identity-sequence reset steps.

Important: staff passwords are not imported as MySQL password hashes. Create Supabase Auth users and corresponding `profiles` records; staff must receive/reset passwords. Keep personal data and uploaded identity documents private during transfer.

## 5. Important production checks

- Apply every Supabase migration before the first login.
- Create an administrator profile before using the Users screen.
- Set Cloudflare Worker secrets and the exact allowed web origin.
- Use a production Supabase project with backups enabled.
- Confirm the `products`, `sales`, `credits`, and `creditors` RLS policies using test accounts for each role.
- The receipt and balance print actions use the browser's Print → Save as PDF flow.
- The app uses UGX as the display currency; update company details/settings to match the business.

Before switching production traffic, complete `docs/RELEASE_CHECKLIST.md`.

## Repository map

- `apps/web/` — Vue frontend.
- `apps/api/` — Hono Worker API; privileged staff-account operations.
- `packages/shared/` — shared role and permission types.
- `supabase/migrations/` — PostgreSQL schema, RLS and transactional functions.
- `legacy/php-app/` — old PHP reference only.
- `docs/` — security, architecture and migration instructions.

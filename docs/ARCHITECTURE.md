# Architecture

```
Browser (Vue, Cloudflare Pages)
   |-- supabase-js (anon key + user JWT) --> Supabase Postgres (RLS + column grants)
   |                                         Supabase Auth / private Storage
   '-- fetch + user JWT ------------------> Hono API (Cloudflare Workers)
                                              '-- service-role key --> Supabase Auth Admin / controlled server tasks
```

- Normal reads/writes use Supabase with RLS and explicit column grants.
- Atomic business workflows run as PostgreSQL functions: `create_sale`, `receive_purchase`, `record_credit_payment`, and `record_creditor_payment`.
- The Worker verifies each bearer token with Supabase Auth and loads the active profile. User administration is admin-only and uses the service-role key.
- Cost/profit access is enforced in PostgreSQL using `view_costs`, not by hiding a UI field.
- Receipt and balance PDF output uses the browser print stylesheet and Print → Save as PDF.

## Environments

| Environment | Web | API | Database |
|---|---|---|---|
| Local | Vite dev server | Wrangler dev | Supabase local or development project |
| Staging | Pages preview | Worker staging deployment | Separate Supabase project with test data |
| Production | Cloudflare Pages | Cloudflare Worker | Production Supabase project with backups |

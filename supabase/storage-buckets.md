# Supabase Storage

Migration `0009_storage_policies.sql` creates the buckets and their access policies:

| Bucket | Public | Contents | Access |
|---|---|---|---|
| `branding` | yes | Shop logo and public brand assets | Public read; admin write |
| `staff-photos` | no | Profile photos | User can access own UUID folder; admin can access all |
| `staff-private` | no | National ID images, CVs and private staff documents | Admin only |

Use a folder named with the Supabase Auth user UUID in `staff-photos`. Do not store private staff files in the public bucket. Signed URLs should be short-lived. Never commit uploaded staff documents to Git.

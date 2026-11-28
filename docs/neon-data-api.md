# Neon Data API: HendyStore access boundary

## Production endpoint

The Neon Data API for `Hendy_Store` is configured through Render variable `NEON_DATA_API_URL`. The endpoint URL is not a credential. Never place a Neon API key, PostgreSQL password, or service credential in the browser bundle.

## Public catalogue projection

The Data API exposes one relation: `public.api_public_catalog`. It is a security-barrier view that returns only rows where `products.status = 'published'` and only these fields:

- `id, slug, title, developer, category, short_description`
- `version, platform, icon, cover, downloads, rating, featured, updated_at, price_type, price_amount_vnd`

It deliberately excludes `download_url`, internal product status, account details, password hashes, Auth subjects, sessions, user libraries, download history, review moderation, payments, refunds, and orders.

PostgreSQL grants only:
- `USAGE` on schema `public` to `anonymous` and `authenticated`.
- `SELECT` on `public.api_public_catalog` to those two roles.

Do not use the broad Data API preset that grants access to all public-schema tables. The 17 existing business tables remain inaccessible to Data API roles; the PHP application uses its private server-side PostgreSQL connection for those workflows.

## Browser authentication and query controls

- Signed-in customers send their short-lived Managed Neon Auth JWT.
- Visitors obtain a short-lived anonymous Data API JWT through the Managed Auth client.
- CORS is restricted to `https://hendystore.onrender.com`.
- Browser queries use an explicit field allow-list, bounded pagination, and allow-listed sort expressions.
- If Data API is unavailable, the storefront falls back to the existing PHP catalogue endpoint.
- Product detail, account mapping, Admin CRUD, uploads, reviews, customer library, notifications, and download grants remain on the PHP API, with server-side authentication and authorization.

## Customer sign-up

Neon Auth `Allow Sign Up` is enabled on the production branch. Email verification remains mandatory. Render variable `NEON_AUTH_PUBLIC_SIGNUP` controls the registration tab; the legacy PHP registration route remains disabled with `ALLOW_REGISTRATION=false`.

## Migration

Migration: `database/migrations/20261128_public_catalog_data_api.sql`

This is additive: it creates/replaces one view and adds narrowly-scoped grants. It does not modify or delete customer, product, session, payment, or order records.

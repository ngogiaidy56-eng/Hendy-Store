# Hendy Store identity and session architecture

## Identity providers

- Customer accounts use Neon Managed Better Auth from `@neon/auth`. Neon Auth is the source of truth for customer sign-in, email/password, social providers, sessions and identity subjects.
- The PHP backend accepts a customer identity only from a Bearer JWT verified against the configured Neon Auth JWKS and issuer. It maps the stable `sub` to `users.neon_auth_subject`.
- Email-based linking and first-time provisioning require a signed, verified email claim. Existing Admin rows are never linked to social identities, including when their email matches.
- Neon identities are always mapped to the `customer` role. Admin privileges are never copied from JWT claims or Google email addresses.
- The Admin console uses a separate PHP email/password session created only after checking the configured Admin row. PHP customer registration/login is disabled to prevent competing customer identity stores.

## PHP sessions, HttpOnly and CSRF

- PHP sessions use strict mode, cookie-only transport, HttpOnly, SameSite=Lax and Secure in production.
- PostgreSQL-backed sessions use the existing `app_sessions` table. Session IDs are regenerated after Admin password login.
- Mutating API requests require `X-CSRF-Token` and a session token comparison. Browser Origin/Referer is checked against the canonical origin and exact `APP_ALLOWED_ORIGINS`; requests marked `Sec-Fetch-Site: cross-site` are refused.
- Admin API handlers require `role=admin` from the PHP session plus `auth_mode=admin_password`. Neon customer bearer tokens cannot operate the Admin API.
- An invalid Bearer token does not fall back to another PHP cookie identity.

## Vanilla HTML/CSS/JavaScript frontend

The existing PHP-rendered storefront remains the default and continues using `src/neon-browser.js` and the bundled `@neon/auth` client. Email/password and Google buttons are driven by server-returned feature flags. Customer API calls send the short-lived Neon JWT as a Bearer token; this integration does not persist access tokens in localStorage.

## Optional Next.js storefront

The optional app lives in `apps/storefront-next` and retains the requested Next.js, React and Tailwind versions:

- `/api/auth/[...path]` proxies Managed Better Auth on the Next app's own origin using `@neon/auth/next/server`.
- Browser code imports `createAuthClient` from `@neon/auth/next`; it has no server secret or provider credential.
- `/sign-in` supports email/password, a feature-gated Google button and sign-up only when the PHP backend reports Neon public signup enabled.
- `/account` checks the server Managed Auth session and then verifies a Bearer JWT with the PHP API before showing customer data.
- The PHP API remains the authorization boundary. Hiding/protecting a Next page is not permission to read private records.

## Environment variables

PHP/Render:
- `NEON_AUTH_BASE_URL`, `NEON_AUTH_JWKS_URL`, `DATABASE_URL`
- `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_SESSION_FALLBACK_ENABLED=true`
- `APP_KEY`, `APP_ALLOWED_ORIGINS`, `APP_ALLOWED_HOSTS`

Optional Next.js Render service:
- `NEON_AUTH_BASE_URL` must point to the same Auth branch as the PHP service.
- `NEON_AUTH_COOKIE_SECRET` is a separate server-only random secret, 32+ characters. The optional `render.next.yaml` Blueprint asks Render to generate it; for manual setup, generate locally with `openssl rand -base64 32` and store it only in the Next service Environment.
- `HENDY_API_ORIGIN` must point to the active PHP Render service URL.

Google OAuth activation:
1. Configure a production Google Web OAuth client in Neon Console → production branch → Auth → OAuth providers.
2. Set the exact Neon Auth callback URI displayed in Neon Console in the Google Cloud OAuth client.
3. Add the active browser origins to Neon Auth trusted domains.
4. Verify a real login and return to `/account`.
5. Only then set `GOOGLE_OAUTH_ENABLED=true` on the PHP service. Keep it false until the production client is configured.
6. Keep public signup disabled unless the operator has explicitly enabled signup and required email verification.

## Verification still required after deployment

Run the GitHub quality workflow, then verify on HTTPS:
- Admin login with the configured Admin credentials; ensure Admin can access every Admin API and customers cannot.
- Neon email/password sign-in and email verification; the JWT reaches PHP API and maps the same subject after reload.
- Google OAuth only when the server flag and Neon provider are configured.
- Invalid, expired, wrong-issuer and wrong-signature JWTs fail closed.
- Customers cannot use PHP sessions, Neon JWTs or matching Google emails to access Admin endpoints.
- Missing/incorrect CSRF token, cross-site Origin, disallowed Origin and cross-site Fetch Metadata are rejected.
- Cookies are HttpOnly and Secure in production; secrets remain in Render Environment.

# HendyStore authentication and Google sign-in rollout

## Current verified state

- Production app database: Neon project `sparkling-grass-08962862`, branch `production`, database `Hendy_Store`.
- Production identity provider: Neon Managed Better Auth, with identity data in the separate `neondb` database.
- Auth settings enable email/password and public sign-up. Email verification by OTP is required; automatic sign-in occurs after successful verification.
- Google is still listed with shared development credentials. A production Google OAuth Client ID/Secret is not configured, so Google sign-in remains disabled in the website.
- The production origin `https://hendystore.onrender.com` has been added to Neon Auth trusted origins.
- `Hendy_Store` currently has one local Admin account and no customer accounts or products. Neon Auth currently has zero users and zero sessions.
- `/admin/` continues to use its dedicated local email/password sign-in.

## Target sign-in experience

1. The homepage account button opens a sign-in dialog.
2. When Google OAuth is configured and the server feature flag is enabled, show **Continue with Google**.
3. Keep email/password sign-in as a fallback.
4. After OAuth returns, restore the authenticated session, show the profile name/avatar, and offer My Library and Sign out.
5. Admin continues to sign in at `/admin/`; a matching Google email alone must never grant the local Admin role.

## Google OAuth setup required before activation

1. In Google Cloud Console, create an OAuth 2.0 Client ID of type **Web application**.
2. Add `https://hendystore.onrender.com` to Authorized JavaScript origins.
3. In Authorized redirect URIs, add the exact callback shown by Neon Auth. For the current production Auth base URL, the expected callback is `https://ep-withered-sunset-bekyloue.neonauth.c-9.us-east-2.aws.neon.tech/neondb/auth/callback/google`. Confirm the callback displayed by Neon Auth before saving it in Google Cloud; it is not the HendyStore homepage URL.
4. In Neon Console → project → production branch → Auth → OAuth providers, configure Google using that Client ID and Client Secret. Never place the Client Secret in this repository, a browser bundle, or chat.
5. Confirm `https://hendystore.onrender.com` is listed in Neon Auth trusted domains; it has been added for the production branch.
6. Set Render variable `GOOGLE_OAUTH_ENABLED=true` only after the Google provider is saved and tested. Leave it false until then.
7. **Allow Sign Up is enabled** on the production Neon Auth branch. First-time customer accounts can be created through Neon Auth; email verification remains required. The homepage registration tab is controlled by Render variable `NEON_AUTH_PUBLIC_SIGNUP=true`.
8. The legacy PHP registration route remains closed with `ALLOW_REGISTRATION=false`; do not enable it as a side effect.
9. The Google button stays hidden until a production OAuth Client ID/Secret is configured and `GOOGLE_OAUTH_ENABLED=true`.

## Identity and privilege boundaries

- Neon Auth's signed subject is the stable external identity and is mapped to `users.neon_auth_subject`.
- A pre-existing linked subject cannot be silently replaced by another Google identity.
- An unlinked local Admin row cannot be adopted by social sign-in just because the email matches. Admin remains on the dedicated local password flow until an explicit, audited linking process is implemented.
- Do not run Clerk/Auth0/Supabase Auth alongside Neon Auth. The application should have one customer identity provider.
- OAuth Client Secrets must never be exposed in browser variables such as `VITE_*`, committed files, or build output.

## Verification checklist

- Google sign-in redirects to Google, completes consent, and returns to the HendyStore origin.
- First-time social account creation is permitted only when the business has explicitly enabled sign-up.
- A returning Google identity resolves to the same local user ID and preserves library/review ownership.
- A different Google subject with the same email cannot take over an already linked account.
- Google sign-in for the Admin email does not grant Admin.
- Email/password, logout, session restoration, CSRF-protected mutations, `/admin/`, and health checks continue to work.
- Complete the flow in a real browser over the Render HTTPS origin; source smoke tests alone do not prove OAuth is operational.
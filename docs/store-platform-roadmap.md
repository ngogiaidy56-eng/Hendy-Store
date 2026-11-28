# HendyStore platform architecture and module roadmap

## Production inventory snapshot

- Repository head audited: `05124176dde33a5a32dbd6ca38cf6bba21fb931a` on `main`.
- Runtime architecture: PHP 8.4 + Apache + PDO; Neon PostgreSQL; Neon Object Storage (S3-compatible); HTML/CSS/vanilla JavaScript; Node.js 22 + npm + esbuild; Render Docker; GitHub Actions.
- `Hendy_Store` has 17 public tables and the migrations `20261128_store_ecosystem` and `20261128_paid_store_payments` are recorded as applied.
- Current row counts at audit time: one local Admin, zero customers, zero products, zero versions, zero reviews, zero personal-library rows, and zero download-history rows.
- Legacy order/payment/refund tables exist but currently have zero rows. Do not drop them without a separate schema/data review.
- Neon Auth's `neondb` database currently has zero Auth users and zero sessions. Email/password is enabled; public sign-up is disabled; Google is listed with shared development credentials, not production credentials.

## Existing modules

| Domain | Current code | Current state |
|---|---|---|
| Catalog and search | `index.php`, `api.php` | Homepage, category, search, sort and pagination exist |
| App detail | `subpage/`, `features/storefront.js` | Description, media, specs, related apps, versions and download UI exist |
| Admin catalog | `admin/`, `api.php` | Admin login and product CRUD exist |
| Upload and storage | `config/bootstrap.php`, `api.php` | Private Neon Object Storage upload exists |
| Versioning | `features/api.php`, `app_versions` | Platform, architecture, minimum OS, package URL and release notes exist |
| Reviews | `features/api.php`, `app_product_reviews` | Rating, moderation status and developer reply exist |
| User library | `library/`, `app_user_library` | Personal library, notifications and download history exist |
| Download security | `app_download_grants` | Hashed one-time, expiring download grant exists |
| Publisher/developer portal | `developer-policy/` and Admin pages | Policy exists; self-service publisher onboarding and submissions are incomplete |
| Package security | upload path | No complete malware scan/quarantine/signature-verification pipeline yet |
| Identity | `src/neon-browser.js`, `config/bootstrap.php`, `api.php` | Neon Auth email/password and local PHP session fallback; Google UI is feature-gated; dedicated Admin password flow remains |

## Recommended target architecture: modular monolith first

See the detailed architecture designs:
- [Unified Cloudflare + Render + Neon target architecture](architecture/target-architecture.md)
- [Windows 10/11 distribution plan](architecture/windows-distribution.md)

These documents distinguish existing production services from proposed infrastructure.

Keep PHP and Render as the current production runtime. Refactor the API into bounded domains instead of replacing the backend with Node.js immediately. Maintain `api.php` as a compatibility router while new module handlers are extracted.

Suggested source layout:

```text
modules/
  identity/       # Neon Auth token validation, account mapping, sessions, roles
  catalog/        # discovery, search, categories, product detail
  publishers/     # developer profile, membership, submissions
  packages/       # upload, quarantine, object storage, download grants
  versions/       # release history, platform, architecture, minimum OS
  reviews/        # reviews, moderation, publisher replies
  library/        # personal library, update notifications, download history
  security/       # malware scans, signatures, hashes, audit events
  admin/          # moderation, publishing, category and policy controls
database/
  migrations/     # versioned, additive production migrations
tests/
  unit/
  integration/
  e2e/
```

Do not move to React/Next.js, Express/NestJS, or Workers as a prerequisite. A frontend migration or backend split can happen after API contracts and PostgreSQL integration tests are stable.

## Target data model (additive only)

- Keep existing `users`, `products`, `app_versions`, `app_product_screenshots`, `app_product_specs`, `app_product_reviews`, `app_user_library`, `app_download_history`, `app_user_notifications`, and `app_download_grants`.
- Add publisher identity and ownership: `publishers`, `publisher_memberships`, `publisher_verifications`.
- Add package integrity and safety: `app_packages`, `app_security_scans`, `app_signature_checks`, `app_moderation_events`.
- Add device compatibility: `app_platform_targets` with OS, architecture, package type, minimum version and install notes.
- Add migration/audit metadata where needed; use immutable events for security and publishing decisions.
- Avoid changing local user IDs or dropping user, product, review, library, or download relationships.

## User-facing experience inspired by Google Play

1. Store discovery: featured collections, categories, search suggestions, sorting and device filters.
2. Product detail: app identity, publisher, screenshots/video, description, permissions/privacy notes, compatibility, version history, ratings and reviews.
3. Install/download: platform-specific package selection, file size, version, SHA-256, publisher signature status, safe download grant and update availability.
4. User account: Google sign-in, verified profile, library, update notifications, review history and sign-out.
5. Developer console: publisher verification, draft submission, package upload, release notes, rollout status and response to reviews.

iOS should link to App Store/TestFlight or provide PWA install guidance; do not advertise direct IPA installation unless the distribution and signing path is valid.

## Package publishing safety workflow

1. Admin or verified publisher uploads to a private quarantine prefix.
2. Validate extension, MIME, magic bytes, size limit and archive structure.
3. Calculate SHA-256 and extract package metadata (APK manifest, Windows package identity, architecture, version).
4. Verify platform signatures where the package format supports it.
5. Scan with a malware engine and optional VirusTotal hash lookup; store scan result, scanner version, time and verdict.
6. Keep pending, failed, suspicious or malicious files unavailable to public download.
7. Require manual review for uncertain verdicts; publish only clean and approved packages.
8. Issue short-lived download grants and log a minimal, privacy-conscious audit record.

## Database and deployment safeguards

- Replace request-time schema creation with versioned migration execution in a controlled deploy step.
- Test migrations on a Neon branch before production and use additive DDL first.
- Take/verify a backup before any destructive migration.
- Keep file bytes in private object storage, not Render ephemeral filesystem.
- Keep API/login/admin/download endpoints out of shared CDN cache.
- Run PostgreSQL integration tests against a branch; SQLite smoke tests remain useful but are not sufficient for production.
- Keep payment/order code disabled as a product policy until explicitly requested; existing zero-row legacy tables should not be deleted as part of this refactor.

## Implementation phases

| Phase | Deliverables | Acceptance condition |
|---|---|---|
| 1. Identity | Neon Auth Google OAuth, account menu, verified identity mapping, Admin isolation | Google flow tested in browser; Admin password login still works |
| 2. Domain modularization | Extract catalog, versions, reviews, library and admin handlers | Existing endpoints remain compatible; PHP/JS/Neon tests pass |
| 3. Publisher portal | Publisher profile, ownership verification, submissions and moderation | A publisher cannot edit another publisher's apps |
| 4. Package trust | Quarantine, SHA-256, signature checks, malware scan queue | No unscanned or blocked package can be downloaded |
| 5. Discovery and updates | Device filters, richer detail page, changelog and update alerts | Correct platform version and compatibility are shown |
| 6. Distribution edge | Cloudflare CDN/R2 only after load and security tests | Private routes bypass cache; signed downloads work end-to-end |

## Non-goals for the first refactor

- No wholesale rewrite of PHP backend into Node/Workers.
- No replacement of Neon Auth with multiple competing identity providers.
- No automatic copying of third-party APKs/apps without distribution rights.
- No destructive cleanup of production tables or user data.
- No claims that malware scanning, WAF, or OAuth are live before real end-to-end verification.
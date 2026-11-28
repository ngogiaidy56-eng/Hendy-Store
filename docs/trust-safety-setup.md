# HendyStore Trust, Safety & Developer Console

## Required Render secrets

- \`VIRUSTOTAL_API_KEY\`: VirusTotal API v3 key. Keep this secret in Render, not Git.
- \`MAX_DIRECT_UPLOAD_BYTES\`: default production limit \`681574400\` (650 MiB).
- \`NEON_AUTH_BASE_URL\` and \`NEON_AUTH_JWKS_URL\`: same Neon Auth project/domain.
- Existing S3-compatible credentials: \`AWS_ACCESS_KEY_ID\`, \`AWS_SECRET_ACCESS_KEY\`, \`AWS_ENDPOINT_URL_S3\`, \`AWS_REGION\`, \`NEON_STORAGE_BUCKET\`.

The \`CODE_SIGNING_SERVICE_URL\` and \`CODE_SIGNING_SERVICE_TOKEN\` variables are reserved for a future signing adapter; the current application does not sign binaries.

## Private Object Storage

Use a private bucket named \`hendy-store-files\`. Block public access and do not enable anonymous GET.

Example CORS policy (replace the origin if the production Storefront domain changes):

\`\`\`json
[
  {
    "AllowedOrigins": ["https://hendystore.onrender.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 300
  }
]
\`\`\`

The browser receives a five-minute, single-object presigned PUT URL. The backend records the package owner and metadata, then downloads the private object to calculate the authoritative SHA-256 and submit it to VirusTotal. A manual approval action waits six minutes from presigned URL creation and rechecks the exact stored object before publication.

## Moderation procedure

1. A user signs in and opens \`/developer/\`.
2. The user submits a developer profile.
3. An administrator reviews it at \`/admin/security.php\`.
4. After profile approval, the developer uploads the package and fills version, release notes, screenshots, video, requirements and privacy details.
5. The package remains private. The backend verifies the object, computes SHA-256 and scans it.
6. Only a clean scan allows the release to enter the manual review queue.
7. An administrator rechecks and approves or rejects the release.
8. A production release becomes visible in the Storefront. Alpha/Beta releases remain non-public.

## What is deliberately not enabled

- Direct IPA installation on iOS. Use App Store, TestFlight or MDM distribution.
- Native install/uninstall telemetry. The backend counts views and completed download responses, but a native SDK is required to report installation and removal.
- Tester-group management and one-click release rollback UI.
- Binary signing. A dedicated signing service backed by HSM/Key Vault is required; do not send private signing keys through ordinary web forms.

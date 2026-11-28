# CloudFly Object Storage for HendyStore

## Current architecture and safety

HendyStore keeps Neon PostgreSQL and Neon Auth. CloudFly is an optional S3-compatible file-storage provider; it does not replace the database or authentication service.

The application defaults to the current Neon Object Storage configuration. Do not set \`OBJECT_STORAGE_PROVIDER=cloudfly\` until a CloudFly bucket and API credentials have been created and a connection test has succeeded.

When CloudFly becomes primary:
- New media and application packages are written to CloudFly.
- Existing objects are read from CloudFly first, then from the legacy Neon bucket when they are not found.
- Keep the existing \`AWS_ACCESS_KEY_ID\`, \`AWS_SECRET_ACCESS_KEY\`, \`AWS_ENDPOINT_URL_S3\`, \`AWS_REGION\`, and \`NEON_STORAGE_BUCKET\` values until all old objects have been copied and verified.
- Never make the bucket public to make the integration work. HendyStore uses private packages and short-lived presigned uploads.

## CloudFly values

CloudFly's official API guide documents:

| Setting | HendyStore value |
|---|---|
| Endpoint | \`https://s3.cloudfly.vn\` |
| Region | \`hn\` |
| Signature | AWS Signature Version 4 |
| Addressing | Path-style enabled |
| Access | S3 Access Key ID + Secret Access Key |
| Bucket | The bucket you create in your CloudFly Object Storage |

Official instructions:
- API and SDK connection: https://cloudfly.vn/docs/object-storage/huong-dan-su-dung-api-de-ket-noi-object-storage
- Create/manage the storage and bucket: https://cloudfly.vn/docs/object-storage/huong-dan-tao-va-quan-ly-su-dung-dich-vu-object-storage-tai-cloudfly
- S3 Browser connection: https://cloudfly.vn/docs/object-storage/huong-dan-su-dung-s3-browser-de-ket-noi-object-storage

The supplied management URL requires CloudFly ID sign-in:
\`https://my.cloudfly.vn/object-storage/aa993386-378d-44c6-3828-733890057fbc\`
CloudFly's documentation says to create a bucket inside the Object Storage service and select Private access for app packages. The page-specific bucket name and credentials are visible only after account sign-in.

## Render Environment

Add these variables to the existing HendyStore web service. Use Render's Environment page; do not paste credentials into GitHub or chat.

| Key | Value |
|---|---|
| \`OBJECT_STORAGE_PROVIDER\` | \`cloudfly\` (only after the test succeeds) |
| \`OBJECT_STORAGE_ENDPOINT\` | \`https://s3.cloudfly.vn\` |
| \`OBJECT_STORAGE_REGION\` | \`hn\` |
| \`OBJECT_STORAGE_BUCKET\` | Your actual CloudFly bucket name |
| \`OBJECT_STORAGE_ACCESS_KEY_ID\` | Access Key ID created in CloudFly |
| \`OBJECT_STORAGE_SECRET_ACCESS_KEY\` | Secret Access Key created in CloudFly |
| \`OBJECT_STORAGE_SESSION_TOKEN\` | Optional; blank for a long-lived API key |
| \`OBJECT_STORAGE_FORCE_PATH_STYLE\` | \`true\` |
| \`OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK\` | \`true\` during migration |

Create a dedicated API key for HendyStore with only the S3 permissions required for the application: bucket access/HeadBucket, object read (GetObject/HeadObject) and object write (PutObject). Add list permission only if a provider-side verification or inventory operation needs it. Do not enable public-read or public-write ACLs. Keep the secret only in Render.

CloudFly's official guide also describes temporary STS credentials (maximum 24 hours). The server supports an optional \`OBJECT_STORAGE_SESSION_TOKEN\`, but a short-lived token must be renewed before expiry. Do not use a 24-hour token as a permanent Render credential without an automated renewal design.

## Bucket CORS

Direct browser uploads use a short-lived presigned \`PUT\` URL with a signed \`Content-Type\` header. Configure CORS on the CloudFly bucket for the exact Storefront origin:

\`\`\`json
{
  "CORSRules": [
    {
      "AllowedOrigins": ["https://hendystore.onrender.com"],
      "AllowedMethods": ["PUT"],
      "AllowedHeaders": ["*"],
      "ExposeHeaders": ["ETag"],
      "MaxAgeSeconds": 300
    }
  ]
}
\`\`\`

Do not add \`ACL=public-read\` to the presigned request. The bucket remains private; package download is streamed through the authenticated HendyStore API after moderation and integrity checks.

## Safe cutover sequence

1. Sign in to CloudFly ID and inspect the given Object Storage service.
2. Confirm the bucket name and create/obtain an API key. Do not send the Secret Access Key in chat.
3. Create the bucket as Private and apply the CORS rule above.
4. Add the CloudFly variables to Render but keep \`OBJECT_STORAGE_PROVIDER=neon\` for now.
5. Run a connection probe using a dedicated test key/object and verify PutObject, HeadObject, GetObject and presigned PUT from the HendyStore origin.
6. Set \`OBJECT_STORAGE_PROVIDER=cloudfly\` and deploy. The application writes new objects to CloudFly and retains read fallback to Neon.
7. Verify a new private test upload/download and an existing Neon-hosted media/package download.
8. Copy legacy objects only after the test path is successful. Compare object count, size and SHA-256 before considering a source object migrated.
9. Keep \`OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK=true\` until all old references are verified. Do not remove Neon storage keys or bucket until the migration is complete.

## Session / CSRF recovery

The login screenshot shows HTTP 419-style CSRF expiration. The server-side CSRF check remains enforced. Browser API clients now refresh the same-origin bootstrap/session token and retry the original write once when the API returns 419. A second failure remains an error; the client does not bypass CSRF or disable cookies.

# Automatic multi-domain routing

Hendy Store can serve the Render hostname and multiple custom domains from the same PHP/Apache service.

## Render defaults

- `APP_URL=auto` lets the app derive the public origin for each request.
- `APP_AUTO_DETECT_DOMAINS=true` enables request-host detection.
- `TRUST_PROXY_HEADERS=true` is required when Render or Cloudflare terminates HTTPS in front of Apache.
- `RENDER_EXTERNAL_URL` is used automatically as a fallback when a request does not contain a usable trusted host.
- Render health checks use `/healthz`, a process-level endpoint that does not depend on Neon or Object Storage.

## Custom-domain allowlist

By default, valid hostnames are detected dynamically behind the configured trusted proxy. To pin the service to known domains, optionally set `APP_ALLOWED_HOSTS` in Render Environment to a comma-separated list:

```text
hendystore.onrender.com,store.example.com,*.example.net
```

Exact hosts and `*.example.com` wildcard entries are supported. A host outside this list falls back to Render's managed external URL. Keep this list synchronized with the domains attached to the Render service / Cloudflare proxy.

## Canonical URL override

Set `APP_URL=https://store.example.com` to force one canonical host. Use `APP_URL=auto` to support multiple hostnames. Do not place credentials, query strings or fragments in `APP_URL`.

The detailed database/storage diagnostic endpoint remains `/api.php?action=health`; unlike `/healthz`, it checks Neon and Object Storage and can return a dependency-related 503.

import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";

await access("js/neon-client.bundle.js");
const neonConfig = await readFile("neon.ts", "utf8");
for (const token of [
  "auth: true",
  "aiGateway: true",
  "hendy-store-files",
  "functions:",
  "realtime:",
  "allowedOrigins",
]) {
  assert.ok(neonConfig.includes(token), "Missing Neon config: " + token);
}

const apiSource = await readFile("api.php", "utf8");
assert.ok(apiSource.includes("GOOGLE_OAUTH_ENABLED"), "Google OAuth feature flag must be server-controlled");
assert.ok(apiSource.includes("Liên kết tải trực tiếp đã ngừng hỗ trợ"), "Legacy unauthenticated file-download endpoint must be closed");
assert.ok(apiSource.includes("Gói riêng tư cần phiếu tải một lần"), "Private package downloads must require one-time grants");
assert.ok(apiSource.includes("Sản phẩm trả phí không được tải qua luồng miễn phí"), "Paid products must be denied by the legacy download route");
const featureApiSource = await readFile("features/api.php", "utf8");
assert.ok(featureApiSource.includes("Phiếu tải đã hết hạn hoặc đã được sử dụng"), "Download grants must be one-use and time-limited");
assert.ok(featureApiSource.includes("Sản phẩm trả phí không thể tải qua phiếu tải miễn phí"), "Grant consumption must reject Paid products");
assert.ok(featureApiSource.includes("Sản phẩm trả phí không được tải qua luồng miễn phí"), "Grant creation must reject Paid products");
const grantLimitPosition = featureApiSource.indexOf("rate_limit_or_fail($db, 'store-version-download'");
const consumeGrantPosition = featureApiSource.indexOf("$consume = $db->prepare('UPDATE app_download_grants");
assert.ok(grantLimitPosition >= 0 && consumeGrantPosition > grantLimitPosition, "Rate limiting must run before consuming a one-time grant");

const bootstrapSource = await readFile("config/bootstrap.php", "utf8");
assert.ok(bootstrapSource.includes("unlinked social identity"), "Social identity must not inherit an unlinked Admin role");
assert.ok(bootstrapSource.includes("neon_auth_subject"), "Social identities must map to a stable auth subject");
assert.ok(bootstrapSource.includes("RENDER_EXTERNAL_URL"), "Empty proxy host allow-list must fall back to Render's assigned origin");

const homeSource = await readFile("index.php", "utf8");
assert.ok(homeSource.includes("googleSignInButton"), "Homepage must expose the feature-gated Google sign-in control");
assert.ok(homeSource.includes("accountMenu"), "Signed-in users need an account menu");
assert.ok(!homeSource.includes("accountAdminLink"), "Admin console must not be exposed in the public account menu");
assert.ok(!homeSource.includes('href="tech-stack/"'), "Technology stack links must be hidden from the public storefront");

const bootstrapStorageSource = await readFile("config/bootstrap.php", "utf8");
for (const token of [
  "OBJECT_STORAGE_PROVIDER",
  "OBJECT_STORAGE_ENDPOINT",
  "OBJECT_STORAGE_REGION",
  "s3.cloudfly.vn",
  "OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK",
  "object_storage_s3_client",
]) {
  assert.ok(bootstrapStorageSource.includes(token), "Missing S3-compatible storage support: " + token);
}
const workerSource = await readFile("cloudflare/platform-worker/src/index.ts", "utf8");
for (const token of [
  "/api/v2/platform/health",
  "/api/v2/platform/capabilities",
  "Authorization",
  "X-CSRF-Token",
  "isPublicCatalog",
  "expirationTtl: 30",
]) {
  assert.ok(workerSource.includes(token), "Missing Cloudflare Worker API behavior: " + token);
}
assert.ok(workerSource.includes('incoming.searchParams.get("action") === "products"'), "KV cache must be limited to the public catalogue action");
assert.ok(workerSource.includes('!request.headers.has("Authorization")'), "KV cache must exclude authenticated requests");
assert.ok(workerSource.includes('!request.headers.has("Cookie")'), "KV cache must exclude cookie-bearing requests");
const publicHealthSource = workerSource.slice(workerSource.indexOf("async function platformHealth"), workerSource.indexOf("function constantTimeEquals"));
assert.ok(!publicHealthSource.includes("CACHE.put"), "Public health must not mutate KV");
assert.ok(!publicHealthSource.includes("R2.put"), "Public health must not mutate R2");
assert.ok(workerSource.includes('url.pathname === "/api/v2/platform/probe" && request.method === "POST"'), "Write probe must use an explicit POST route");
assert.ok(workerSource.includes("constantTimeEquals(supplied, expected)"), "Write probe must require its protected token");
for (const token of ["__hendy_health__", "CACHE.put", "CACHE.get", "CACHE.delete", "R2.put", "R2.get", "R2.delete", "env.DB.prepare"]) {
  assert.ok(workerSource.includes(token), "Missing live Cloudflare binding health probe: " + token);
}

const workerConfig = await readFile("cloudflare/platform-worker/wrangler.toml", "utf8");
assert.ok(workerConfig.includes('OBJECT_STORAGE_PROVIDER = "neon"'), "Worker proxy must route storage through the Render/Neon origin");
assert.ok(!workerConfig.includes("[[r2_buckets]]"), "Proxy deployment must not require a duplicate R2 bucket binding");
const pagesConfig = await readFile("cloudflare/pages/wrangler.toml", "utf8");
assert.ok(pagesConfig.includes('ORIGIN_URL = "https://hendystore.onrender.com"'), "Pages must have a non-secret Render origin var");
const workerDatabase = await readFile("cloudflare/platform-worker/src/database.ts", "utf8");
for (const token of ["origin", "env.DB", "prepare", "SELECT 1"]) {
  assert.ok(workerDatabase.includes(token), "Missing Cloudflare database adapter: " + token);
}
const workerStorage = await readFile("cloudflare/platform-worker/src/storage.ts", "utf8");
for (const token of ["cloudfly", "r2", "s3", "createPresignedPutUrl", "HeadBucketCommand"]) {
  assert.ok(workerStorage.includes(token), "Missing Worker object storage adapter: " + token);
}
const provisionSource = await readFile("scripts/provision-cloudflare-resources.mjs", "utf8");
for (const token of ["CF_CREATE_R2", "CF_CREATE_KV", "CF_CREATE_D1", "CF_R2_PRIVATE_ACCESS_CONFIRMED", "origin", "overwrite it"]) {
  assert.ok(provisionSource.includes(token), "Missing explicit Cloudflare provisioning safety gate: " + token);
}
assert.ok(!provisionSource.includes("r2 bucket delete"), "Provisioning must never delete R2 buckets");
assert.ok(!provisionSource.toLowerCase().includes("hyperdrive"), "Provisioning must not configure Hyperdrive");
assert.ok(!workerDatabase.toLowerCase().includes("hyperdrive"), "Worker database adapter must not use Hyperdrive");
assert.ok(!workerDatabase.includes("mysql2"), "Worker database adapter must not include MySQL");
assert.ok(!provisionSource.includes("d1 delete"), "Provisioning must never delete D1 databases");
const architecture = await readFile("docs/architecture/target-architecture.md", "utf8");
assert.ok(!/Azure Front Door|Azure SQL|Azure Blob|Python package worker|Hyperdrive/i.test(architecture), "Target architecture must not reference retired Azure or worker components");
const nextPage = await readFile("apps/storefront-next/src/app/page.tsx", "utf8");
const nextDetail = await readFile("apps/storefront-next/src/app/apps/[slug]/page.tsx", "utf8");
const nextApi = await readFile("apps/storefront-next/src/lib/api.ts", "utf8");
for (const [source, token, label] of [
  [nextPage, "force-dynamic", "live Next storefront catalogue"],
  [nextDetail, "SoftwareApplication", "SEO structured application details"],
  [nextDetail, "screenshots", "application screenshots"],
  [nextDetail, "SHA-256", "public integrity checksums"],
  [nextApi, "store_product_extras", "application extras API client"],
]) {
  assert.ok(source.includes(token), "Missing " + label);
}
const infraPage = await readFile("admin/infrastructure.php", "utf8");
const infraClient = await readFile("admin/infrastructure.js", "utf8");
assert.ok(infraPage.includes('id="infraWorkspace" hidden'), "Infrastructure controls must start hidden until Admin authorization");
assert.ok(infraPage.includes("ACTIVE · PRIVATE"), "Infrastructure Center must identify the provisioned private Neon bucket as active");
assert.ok(infraPage.includes("OBJECT_STORAGE_PROVIDER=neon"), "Infrastructure Center must show the Neon provider");
assert.ok(infraClient.includes("OBJECT_STORAGE_PROVIDER=neon"), "Copy configuration must default to Neon Object Storage");
assert.ok(infraClient.includes("user?.role === 'admin'"), "Infrastructure page must require an Admin session");
const platformWorkflow = await readFile(".github/workflows/deploy-platform.yml", "utf8");
assert.ok(platformWorkflow.includes("workflow_dispatch:"), "Cloudflare deployments must be manually triggered");
assert.ok(!/^\s+push:/m.test(platformWorkflow.split("\n").slice(0,12).join("\n")), "Cloudflare deployments must not auto-run on normal pushes");
assert.ok(platformWorkflow.includes("secrets.CLOUDFLARE_API_TOKEN"), "Cloudflare deployments must use a protected repository secret");
assert.ok(platformWorkflow.includes("secrets.CF_PLATFORM_HEALTH_TOKEN"), "Worker write-probes must use a separate protected secret");
assert.ok(platformWorkflow.includes("working-directory: cloudflare/pages"), "Pages Wrangler config must be loaded from its project directory");
assert.ok(!platformWorkflow.includes("pages secret put ORIGIN_URL"), "Pages origin must not be stored as an unnecessary secret");

for (const [path, token] of [
  ["js/custom.261126163157.js", "response.status===419"],
  ["admin/security.js", "response.status === 419"],
  ["developer/console.js", "response.status === 419"],
  ["admin/admin.js", "response.status === 419"],
  ["admin/store-features.js", "response.status===419"],
  ["library/library.js", "response.status === 419"],
]) {
  const source = await readFile(path, "utf8");
  assert.ok(source.includes(token), "Missing automatic CSRF recovery in " + path);
}

const securitySource = await readFile("features/security.php", "utf8");
for (const token of [
  "app_developer_profiles",
  "app_security_packages",
  "app_release_security",
  "VIRUSTOTAL_API_KEY",
  "sha256",
  "createPresignedRequest",
  "trust_release_review",
  "moderation_status",
  "scan_status",
  "package_size_bytes",
  "upload_method",
  "moderation_status",
  "developer_rollback",
  "developer_review_reply",
]) {
  assert.ok(securitySource.includes(token), "Missing Trust & Safety integration: " + token);
}
const developerPage = await readFile("developer/index.php", "utf8");
const developerClient = await readFile("developer/console.js", "utf8");
const adminTrustPage = await readFile("admin/security.php", "utf8");
const adminTrustClient = await readFile("admin/security.js", "utf8");
for (const [source, token, label] of [
  [developerPage, "Developer Console", "Developer Console page"],
  [developerClient, "developer_upload_intent", "Developer direct upload flow"],
  [developerClient, "developer_submit", "Developer release submission"],
  [adminTrustPage, "Kiểm duyệt & Bảo mật", "Admin moderation page"],
  [adminTrustClient, "trust_release_review", "Admin release approval"],
]) {
  assert.ok(source.includes(token), "Missing " + label);
}


const functionSource = await readFile("neon/functions/storeapi.ts", "utf8");
assert.ok(functionSource.includes("APP_ALLOWED_ORIGINS"), "Neon Function CORS must be restricted to configured application origins");
assert.ok(!functionSource.includes('"Access-Control-Allow-Origin": "*"'), "Neon Function must not use wildcard CORS");
for (const token of [
  "createRealtime",
  "HeadBucketCommand",
  "NEON_AI_GATEWAY_BASE_URL",
  "NEON_AUTH_BASE_URL",
  "DATABASE_URL",
  "catalog-live",
]) {
  assert.ok(functionSource.includes(token), "Missing Neon service integration: " + token);
}

const dataApiMigration = await readFile("database/migrations/20261128_public_catalog_data_api.sql", "utf8");
for (const token of [
  "api_public_catalog",
  "security_barrier = true",
  "GRANT SELECT ON TABLE public.api_public_catalog TO anonymous, authenticated",
  "price_type",
  "price_amount_vnd",
]) {
  assert.ok(dataApiMigration.includes(token), "Missing least-privilege Data API migration: " + token);
}
assert.ok(!dataApiMigration.includes("GRANT SELECT ON ALL TABLES"), "Data API must not receive blanket table grants");
assert.ok(!dataApiMigration.includes("download_url"), "Public catalogue view must not expose package URLs");

const browserSource = await readFile("src/neon-browser.js", "utf8");
const packageManifest = JSON.parse(await readFile("package.json", "utf8"));
assert.ok(packageManifest.dependencies?.["@neon/auth"], "Use the supported Neon Managed Auth browser package");
assert.ok(!packageManifest.dependencies?.["@neondatabase/auth"], "Remove the obsolete Neon Auth package name");
assert.ok(browserSource.includes('from "@neon/auth"'), "Browser authentication must import @neon/auth");
assert.ok(browserSource.includes("auth.token()"), "Browser must use the current Managed Auth JWT accessor");
assert.ok(!browserSource.includes("allowAnonymous"), "Do not rely on unsupported anonymous-auth options");
for (const token of [
  "@neon/auth",
  "@neon/realtime/client",
  "signIn.email",
  "signUp.email",
  "signIn.social",
  "signInGoogle",
  "googleOAuthEnabled",
  "getSession",
  "listPublicCatalog",
  "api_public_catalog",
  "config.dataApiUrl",
  "subscribe(sealed)",
  "renew()",
]) {
  assert.ok(browserSource.includes(token), "Missing browser integration: " + token);
}

const pagesProvision = await readFile("scripts/ensure-cloudflare-pages-project.mjs", "utf8");
assert.ok(pagesProvision.includes("/pages/projects"), "Pages deployment must ensure its project exists first");
assert.ok(pagesProvision.includes("hendystore-pages"), "Pages provisioning must target the dedicated HendyStore project");
const deploymentWorkflow = await readFile(".github/workflows/deploy-platform.yml", "utf8");
assert.ok(deploymentWorkflow.includes("workflow_dispatch:"), "Cloudflare deployments must be manual");
for (const target of ["provision", "worker", "next", "pages", "all"]) {
  assert.ok(deploymentWorkflow.includes("- " + target), "Missing Cloudflare deployment target: " + target);
}
assert.ok(deploymentWorkflow.includes("secrets.CLOUDFLARE_API_TOKEN"), "Cloudflare deployments must use an encrypted repository secret");
assert.ok(!deploymentWorkflow.includes("HYPERDRIVE"), "Deployment workflow must not configure Hyperdrive");
assert.ok(!deploymentWorkflow.includes("MYSQL_HYPERDRIVE"), "Deployment workflow must not configure MySQL Hyperdrive");

console.log("Neon source smoke test passed: Auth, least-privilege Data API catalogue, Object Storage, Functions, AI Gateway and Realtime.");


// Authentication hardening contracts.
const nextPackage = JSON.parse(await readFile("apps/storefront-next/package.json", "utf8"));
assert.ok(nextPackage.dependencies?.["@neon/auth"], "Next storefront must use Neon Managed Auth SDK");
assert.equal(nextPackage.dependencies?.next, "16.4.6", "Next version must match the target");
assert.equal(nextPackage.dependencies?.react, "19.3.1", "React version must match the target");
assert.equal(nextPackage.devDependencies?.tailwindcss, "4.3.5", "Tailwind version must match the target");
const authServer = await readFile("apps/storefront-next/src/lib/auth/server.ts", "utf8");
const authClient = await readFile("apps/storefront-next/src/lib/auth/client.ts", "utf8");
const authRoute = await readFile("apps/storefront-next/src/app/api/auth/[...path]/route.ts", "utf8");
const signInUi = await readFile("apps/storefront-next/src/app/sign-in/sign-in-form.tsx", "utf8");
const accountUi = await readFile("apps/storefront-next/src/app/account/account-panel.tsx", "utf8");
for (const [source, token, label] of [
  [authServer, "createNeonAuth", "Next Managed Auth server adapter"],
  [authServer, "NEON_AUTH_COOKIE_SECRET", "server-only Auth cookie secret"],
  [authClient, "createAuthClient", "same-origin Managed Auth browser client"],
  [authRoute, "getNeonAuth().handler()", "Managed Auth catch-all handler"],
  [signInUi, "authClient.signIn.email", "Next email/password sign-in"],
  [signInUi, "authClient.signIn.social", "Next Google OAuth sign-in"],
  [signInUi, "googleOAuthEnabled", "Google feature flag"],
  [signInUi, "neonAuthPublicSignup", "Neon signup feature flag"],
  [accountUi, "Authorization", "backend JWT verification on account screen"],
  [accountUi, "authClient.signOut", "Managed Auth sign-out"],
]) assert.ok(source.includes(token), "Missing " + label);

const hardenedBootstrap = await readFile("config/bootstrap.php", "utf8");
for (const [token, label] of [
  ["session.cookie_httponly", "HttpOnly session cookie"],
  ["session.cookie_secure", "Secure session cookie"],
  ["app_security_origin_allowed", "CSRF exact Origin validation"],
  ["Sec-Fetch-Site", "cross-site Fetch Metadata rejection"],
  ["isset($claims->sub, $claims->exp, $claims->iss)", "strict JWT issuer check"],
  ["neon_auth_jwks($attempt === 1)", "JWKS key-rotation refresh"],
  ["PHP sessions are reserved exclusively", "Admin-only PHP session"],
  ["preg_match('/^Bearer", "Bearer-token Admin isolation"],
]) assert.ok(hardenedBootstrap.includes(token), "Missing " + label);
assert.ok(apiSource.includes("ADMIN_SESSION_FALLBACK_ENABLED"), "Admin session fallback feature flag required");
assert.ok(apiSource.includes("Đăng ký khách hàng qua PHP đã ngừng hỗ trợ"), "Customer registration must use Neon Auth only");
assert.ok(apiSource.includes("'registrationEnabled' => false"), "Legacy PHP registration must remain disabled");
const nextBlueprint = await readFile("render.next.yaml", "utf8");
assert.ok(nextBlueprint.includes("NEON_AUTH_COOKIE_SECRET"), "Next service must declare its private Auth cookie secret");
assert.ok(nextBlueprint.includes("https://hendy-store.onrender.com"), "Next service must target the PHP Render service");

assert.ok(hardenedBootstrap.includes("($jwtHeader['alg'] ?? '') !== 'EdDSA'"), "Neon JWT verifier must pin EdDSA");
assert.ok(hardenedBootstrap.includes("hash_equals($expectedIssuer, (string)$claims->iss)"), "JWT issuer must exactly match the Neon Auth origin");
assert.ok(hardenedBootstrap.includes("'follow_location' => 0"), "JWKS fetch must not follow cross-host redirects");

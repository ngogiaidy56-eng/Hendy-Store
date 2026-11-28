import { chmod, rm, writeFile } from "node:fs/promises";
import process from "node:process";

const outputPath = process.argv[2];
if (!outputPath) {
  console.error("Usage: node scripts/write-cloudflare-secrets.mjs <temporary-output.json>");
  process.exit(2);
}

const mappings = [
  ["OBJECT_STORAGE_ACCESS_KEY_ID", "CF_OBJECT_STORAGE_ACCESS_KEY_ID"],
  ["OBJECT_STORAGE_SECRET_ACCESS_KEY", "CF_OBJECT_STORAGE_SECRET_ACCESS_KEY"],
  ["OBJECT_STORAGE_SESSION_TOKEN", "CF_OBJECT_STORAGE_SESSION_TOKEN"],
  ["PLATFORM_HEALTH_TOKEN", "CF_PLATFORM_HEALTH_TOKEN"]
];

const secrets = {};
for (const [target, source] of mappings) {
  const value = process.env[source];
  if (value) secrets[target] = value;
}
const hasAccessKey = Boolean(secrets.OBJECT_STORAGE_ACCESS_KEY_ID);
const hasSecretKey = Boolean(secrets.OBJECT_STORAGE_SECRET_ACCESS_KEY);
if (hasAccessKey !== hasSecretKey) {
  console.error("Both CF_OBJECT_STORAGE_ACCESS_KEY_ID and CF_OBJECT_STORAGE_SECRET_ACCESS_KEY are required together.");
  process.exit(2);
}
if (!Object.keys(secrets).length) {
  console.log("No optional Worker secrets supplied; existing Cloudflare secrets will be preserved.");
  process.exit(0);
}
try {
  await writeFile(outputPath, JSON.stringify(secrets), { encoding: "utf8", mode: 0o600, flag: "wx" });
  await chmod(outputPath, 0o600);
  console.log("Generated an ephemeral secrets payload (values were not printed).");
} catch (error) {
  await rm(outputPath, { force: true });
  console.error("Could not create ephemeral secret payload.");
  process.exit(1);
}

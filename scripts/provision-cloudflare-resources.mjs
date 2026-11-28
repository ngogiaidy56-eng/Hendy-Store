import { execFileSync } from "node:child_process";
import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";

const workerDir = path.join(process.cwd(), "cloudflare", "platform-worker");
const configPath = path.join(workerDir, "wrangler.toml");
const accountId = process.env.CLOUDFLARE_ACCOUNT_ID || "";
const apiToken = process.env.CLOUDFLARE_API_TOKEN || "";

if (!accountId || !apiToken) {
  console.error("CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN are required.");
  process.exit(2);
}
const childEnv = { ...process.env, CLOUDFLARE_ACCOUNT_ID: accountId, CLOUDFLARE_API_TOKEN: apiToken };

function run(args) {
  try {
    return execFileSync("npx", ["--yes", "wrangler", ...args], {
      cwd: workerDir,
      env: childEnv,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      maxBuffer: 8 * 1024 * 1024
    });
  } catch {
    // Do not log CLI arguments or output; resource commands may contain credentials.
    throw new Error("Cloudflare resource command failed. Check token scopes and the selected resource.");
  }
}

async function readConfig() {
  return readFile(configPath, "utf8");
}

async function appendBinding(block, binding) {
  const current = await readConfig();
  const lines = current.split(/\r?\n/);
  const lineIndex = lines.findIndex((line) =>
    line.trim() === 'binding = "' + binding + '"' ||
    line.trim() === "binding = '" + binding + "'"
  );
  if (lineIndex >= 0) {
    let end = lineIndex + 1;
    while (end < lines.length && !lines[end].trimStart().startsWith("[[")) end += 1;
    const section = lines.slice(lineIndex, end).join("\n");
    const expected = block.trim().split(/\r?\n/).slice(2).filter(Boolean);
    if (!expected.every((line) => section.includes(line))) {
      throw new Error("Binding " + binding + " already targets another resource. Refusing to overwrite it.");
    }
    return false;
  }
  await writeFile(configPath, current.trimEnd() + "\n\n" + block.trim() + "\n", "utf8");
  return true;
}

function parseJson(raw, label) {
  try {
    const parsed = JSON.parse(raw);
    return parsed?.result ?? parsed;
  } catch {
    throw new Error("Could not safely parse Cloudflare " + label + " output.");
  }
}

function namedItems(data) {
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.buckets)) return data.buckets;
  if (Array.isArray(data?.databases)) return data.databases;
  if (Array.isArray(data?.result)) return data.result;
  return [];
}

const report = [];

// R2 must be confirmed private before the application binds it.
const r2Name = String(process.env.CF_R2_BUCKET_NAME || "").trim();
if (process.env.CF_CREATE_R2 === "true" || r2Name) {
  if (!/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/.test(r2Name)) {
    throw new Error("CF_R2_BUCKET_NAME must be 3-63 lowercase letters, numbers or hyphens.");
  }
  if (process.env.CF_R2_PRIVATE_ACCESS_CONFIRMED !== "true") {
    throw new Error("Confirm that public access is disabled for the selected R2 bucket before binding it.");
  }
  const jurisdiction = String(process.env.CF_R2_JURISDICTION || "").toLowerCase();
  if (jurisdiction && !["eu", "us", "fedramp"].includes(jurisdiction)) throw new Error("CF_R2_JURISDICTION must be eu, us, or fedramp.");
  const buckets = namedItems(parseJson(run(["r2", "bucket", "list", ...(jurisdiction ? ["--jurisdiction", jurisdiction] : []), "--json"]), "R2 bucket list"));
  let exists = buckets.some((item) => item.name === r2Name || item.bucketName === r2Name);
  if (!exists && process.env.CF_CREATE_R2 === "true") {
    run(["r2", "bucket", "create", r2Name, ...(jurisdiction ? ["--jurisdiction", jurisdiction] : []), "--location", process.env.CF_R2_LOCATION || "weur"]);
    const refreshed = namedItems(parseJson(run(["r2", "bucket", "list", ...(jurisdiction ? ["--jurisdiction", jurisdiction] : []), "--json"]), "R2 bucket list"));
    exists = refreshed.some((item) => item.name === r2Name || item.bucketName === r2Name);
    if (!exists) throw new Error("R2 create command completed but the bucket could not be verified.");
    report.push("Created an empty private R2 bucket " + r2Name + ".");
  }
  if (!exists) throw new Error("Selected R2 bucket does not exist; enable CF_CREATE_R2 to create it.");
  const block = ["[[r2_buckets]]", 'binding = "R2"', 'bucket_name = "' + r2Name + '"', ...(jurisdiction ? ['jurisdiction = "' + jurisdiction + '"'] : [])].join("\n");
  if (await appendBinding(block, "R2")) report.push("Bound existing R2 bucket " + r2Name + ".");
  else report.push("R2 binding already targets " + r2Name + "; retained.");
}

// KV cache namespace: bind the existing namespace by name, or create it only when explicitly requested.
const kvName = String(process.env.CF_KV_NAMESPACE_NAME || "").trim();
const kvId = String(process.env.CF_KV_NAMESPACE_ID || "").trim();
if (kvId) {
  const binding = ["[[kv_namespaces]]", 'binding = "CACHE"', 'id = "' + kvId + '"'].join("\n");
  if (await appendBinding(binding, "CACHE")) report.push("Attached existing KV namespace by ID.");
} else if (kvName) {
  let namespaces = namedItems(parseJson(run(["kv", "namespace", "list", "--json"]), "KV namespace list"));
  let namespace = namespaces.find((item) => item.title === kvName || item.name === kvName);
  if (!namespace && process.env.CF_CREATE_KV === "true") {
    run(["kv", "namespace", "create", kvName]);
    namespaces = namedItems(parseJson(run(["kv", "namespace", "list", "--json"]), "KV namespace list"));
    namespace = namespaces.find((item) => item.title === kvName || item.name === kvName);
    if (!namespace) throw new Error("KV namespace create completed but its ID could not be verified.");
    report.push("Created KV namespace " + kvName + ".");
  }
  if (!namespace) throw new Error("KV namespace " + kvName + " does not exist; run provision with CF_CREATE_KV=true.");
  const id = namespace.id || namespace.namespace_id;
  if (!id) throw new Error("KV namespace ID is missing; refusing to write an incomplete binding.");
  const binding = ["[[kv_namespaces]]", 'binding = "CACHE"', 'id = "' + id + '"'].join("\n");
  if (await appendBinding(binding, "CACHE")) report.push("Bound KV namespace " + kvName + ".");
}

// D1 is a separate edge database. It is never imported from or dual-written to Neon.
const d1Name = String(process.env.CF_D1_DATABASE_NAME || "").trim();
const d1Id = String(process.env.CF_D1_DATABASE_ID || "").trim();
if (d1Id) {
  const binding = ["[[d1_databases]]", 'binding = "DB"', 'database_name = "' + d1Name + '"', 'database_id = "' + d1Id + '"'].join("\n");
  if (await appendBinding(binding, "DB")) report.push("Attached D1 database by ID.");
} else if (d1Name) {
  let databases = namedItems(parseJson(run(["d1", "list", "--json"]), "D1 database list"));
  let database = databases.find((item) => item.name === d1Name);
  if (!database && process.env.CF_CREATE_D1 === "true") {
    run(["d1", "create", d1Name, "--location", process.env.CF_D1_LOCATION || "weur"]);
    databases = namedItems(parseJson(run(["d1", "list", "--json"]), "D1 database list"));
    database = databases.find((item) => item.name === d1Name);
    if (!database) throw new Error("D1 create completed but its ID could not be verified.");
    report.push("Created empty D1 database " + d1Name + "; no Neon data was copied.");
  }
  if (!database) throw new Error("D1 database " + d1Name + " does not exist; run provision with CF_CREATE_D1=true.");
  const id = database.uuid || database.database_id || database.databaseId || database.id;
  if (!id) throw new Error("D1 database ID is missing; refusing to write an incomplete binding.");
  const binding = ["[[d1_databases]]", 'binding = "DB"', 'database_name = "' + d1Name + '"', 'database_id = "' + id + '"'].join("\n");
  if (await appendBinding(binding, "DB")) report.push("Bound D1 database " + d1Name + ".");
}

// Select only the direct PHP origin or a native D1 binding.
if (process.env.CF_DATABASE_PROVIDER) {
  const value = process.env.CF_DATABASE_PROVIDER.toLowerCase();
  if (!["origin", "d1"].includes(value)) throw new Error("CF_DATABASE_PROVIDER must be origin or d1.");
  if (value === "d1") {
    const config = await readConfig();
    if (!config.includes('binding = "DB"')) throw new Error("Cannot select D1 before the DB binding exists.");
  }
  const current = await readConfig();
  const lines = current.split(/\r?\n/);
  const index = lines.findIndex((line) => line.trimStart().startsWith("DATABASE_PROVIDER ="));
  if (index < 0) throw new Error("DATABASE_PROVIDER is missing from Worker TOML.");
  lines[index] = 'DATABASE_PROVIDER = "' + value + '"';
  await writeFile(configPath, lines.join("\n"), "utf8");
  report.push("Selected Worker database mode " + value + ".");
}

if (process.env.CF_OBJECT_STORAGE_PROVIDER) {
  const value = process.env.CF_OBJECT_STORAGE_PROVIDER.toLowerCase();
  if (!["neon", "cloudfly", "r2", "s3"].includes(value)) throw new Error("CF_OBJECT_STORAGE_PROVIDER is invalid.");
  if (value === "r2" && !(await readConfig()).includes('binding = "R2"')) {
    throw new Error("Cannot select R2 before the R2 binding exists.");
  }
  const current = await readConfig();
  const lines = current.split(/\r?\n/);
  const index = lines.findIndex((line) => line.trimStart().startsWith("OBJECT_STORAGE_PROVIDER ="));
  if (index < 0) throw new Error("OBJECT_STORAGE_PROVIDER is missing from Worker TOML.");
  lines[index] = 'OBJECT_STORAGE_PROVIDER = "' + value + '"';
  await writeFile(configPath, lines.join("\n"), "utf8");
  report.push("Selected Worker storage provider " + value + ".");
}

console.log(report.length ? report.join("\n") : "No Cloudflare resources selected; nothing changed.");
console.log("No resources were deleted and no database contents were migrated.");

import process from "node:process";

const accountId = String(process.env.CLOUDFLARE_ACCOUNT_ID || "");
const apiToken = String(process.env.CLOUDFLARE_API_TOKEN || "");
const projectName = String(process.env.CF_PAGES_PROJECT_NAME || "hendystore-pages");
const productionBranch = String(process.env.CF_PAGES_PRODUCTION_BRANCH || "main");

if (!accountId || !apiToken) {
  console.error("CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN are required.");
  process.exit(2);
}

const headers = {
  Authorization: "Bearer " + apiToken,
  "Content-Type": "application/json"
};

async function request(url, init = {}) {
  const response = await fetch(url, { ...init, headers: { ...headers, ...(init.headers || {}) } });
  const body = await response.json().catch(() => null);
  if (!response.ok || body?.success !== true) {
    const message = Array.isArray(body?.errors)
      ? body.errors.map((item) => item.message).join("; ")
      : "Cloudflare API request failed.";
    throw new Error(message);
  }
  return body.result;
}

const base = "https://api.cloudflare.com/client/v4/accounts/" + accountId + "/pages/projects";
const projects = await request(base);
const existing = Array.isArray(projects) ? projects.find((project) => project.name === projectName) : null;

if (existing) {
  console.log("Pages project already exists: " + projectName + "; retaining its configuration.");
} else {
  await request(base, {
    method: "POST",
    body: JSON.stringify({
      name: projectName,
      production_branch: productionBranch
    })
  });
  console.log("Created Pages project: " + projectName + ".");
}

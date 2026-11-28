(() => {
'use strict';
const $ = (s) => document.querySelector(s);
const snippets = {
  neon: [
    "OBJECT_STORAGE_PROVIDER=neon",
    "AWS_ENDPOINT_URL_S3=https://br-frosty-wildflower-bevhhzlb.storage.c-9.us-east-2.aws.neon.tech",
    "AWS_REGION=us-east-2",
    "NEON_STORAGE_BUCKET=hendy-store-files",
    "AWS_ACCESS_KEY_ID=",
    "AWS_SECRET_ACCESS_KEY=",
    "NEON_STORAGE_FORCE_PATH_STYLE=true",
    "OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK=false"
  ].join("\n"),
  r2: [
    "OBJECT_STORAGE_PROVIDER=r2",
    "CLOUDFLARE_ACCOUNT_ID=918ff2f016938fc978ed23b96505b21e",
    "OBJECT_STORAGE_ENDPOINT=https://918ff2f016938fc978ed23b96505b21e.eu.r2.cloudflarestorage.com",
    "OBJECT_STORAGE_REGION=auto",
    "OBJECT_STORAGE_BUCKET=hendy-store",
    "OBJECT_STORAGE_ACCESS_KEY_ID=",
    "OBJECT_STORAGE_SECRET_ACCESS_KEY=",
    "OBJECT_STORAGE_FORCE_PATH_STYLE=true",
    "OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK=true"
  ].join("\n"),
  cloudfly: [
    "OBJECT_STORAGE_PROVIDER=cloudfly",
    "OBJECT_STORAGE_ENDPOINT=https://s3.cloudfly.vn",
    "OBJECT_STORAGE_REGION=hn",
    "OBJECT_STORAGE_BUCKET=",
    "OBJECT_STORAGE_ACCESS_KEY_ID=",
    "OBJECT_STORAGE_SECRET_ACCESS_KEY=",
    "OBJECT_STORAGE_SESSION_TOKEN=",
    "OBJECT_STORAGE_FORCE_PATH_STYLE=true",
    "OBJECT_STORAGE_LEGACY_NEON_READ_FALLBACK=true"
  ].join("\n"),
  database: [
    "# PHP / business data stays on Neon PostgreSQL through the Render origin",
    "# Keep DATABASE_URL only in Render Environment; never put its value in Worker vars",
    "DATABASE_PROVIDER=origin",
    "# D1 is optional, isolated edge-local storage; it is not a Neon mirror"
  ].join("\n")
};
function show(text) { const node=$('#configPreview'); if(node)node.textContent=text; }
async function copy(text) {
  try { await navigator.clipboard.writeText(text); }
  catch { const area=document.createElement('textarea');area.value=text;document.body.append(area);area.select();document.execCommand('copy');area.remove(); }
  const toast=$('#toast');if(toast){toast.textContent='Đã sao chép cấu hình.';toast.classList.add('show');window.setTimeout(()=>toast.classList.remove('show'),2500);}
}
async function authorizeInfrastructurePage() {
  const workspace = $('#infraWorkspace');
  const denied = $('#infraDenied');
  try {
    const response = await fetch(new URL('/api.php?action=me', window.location.origin), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    const user = result?.data?.user;
    if (response.ok && result.ok && user?.role === 'admin') {
      if (workspace) workspace.hidden = false;
      return;
    }
  } catch (_) {}
  if (denied) denied.hidden = false;
}

document.addEventListener('DOMContentLoaded', authorizeInfrastructurePage);
document.addEventListener('click',(event)=>{
  const button=event.target.closest('[data-copy]');
  if(button) show(snippets[button.dataset.copy]||snippets.neon);
});
$('#copyCurrentConfig')?.addEventListener('click',()=>copy($('#configPreview')?.textContent||''));
$('#checkHealth')?.addEventListener('click',async()=>{
 const badge=$('#originHealth');badge.textContent='Đang kiểm tra…';badge.className='infra-badge';
 try{
   const response=await fetch(new URL('/api.php?action=health',window.location.origin),{cache:'no-store',headers:{Accept:'application/json'}});
   const data=await response.json();
   const databaseOk=data.database==='postgresql'||data.components?.database==='healthy';
   const storageOk=data.components?.storage==='healthy'||(response.ok&&data.ok);
   badge.textContent=databaseOk?(storageOk?'Neon DB + Storage Healthy':'Neon DB Healthy · Storage unavailable'):'Database/API '+response.status+' — cần kiểm tra';
   badge.className='infra-badge '+(databaseOk&&storageOk?'ok':(databaseOk?'warn':'err'));
 }catch{
   badge.textContent='Không kết nối được API origin';
   badge.className='infra-badge err';
 }
});
})();

(() => {
'use strict';
const $ = (s, root=document) => root.querySelector(s);
const all = (s, root=document) => Array.from(root.querySelectorAll(s));
const state = {csrf:'', user:null, categories:[], category:'Tất cả', query:'', sort:'popular', page:1, pages:1, registration:false, googleOAuthEnabled:false};
const api = new URL('api.php', document.baseURI);
const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function notify(message) {
  const el=$('#toast'); if(!el) return; el.textContent=message; el.classList.add('show');
  window.setTimeout(()=>el.classList.remove('show'),2800);
}
async function call(action, method='GET', body=null, retryCsrf=true) {
  const url=new URL(api); url.searchParams.set('action',action);
  const headers={Accept:'application/json'};
  if(method!=='GET'){headers['Content-Type']='application/json';if(state.csrf)headers['X-CSRF-Token']=state.csrf;}
  try {
    const token = window.HendyNeon ? await window.HendyNeon.getToken() : null;
    if (token) headers.Authorization = 'Bearer ' + token;
  } catch (_) {}
  const response=await fetch(url,{method,credentials:'same-origin',headers,body:body?JSON.stringify(body):undefined});
  let result; try{result=await response.json();}catch(_){throw new Error('Máy chủ trả về dữ liệu không hợp lệ.');}
  if(response.status===419 && method!=='GET' && retryCsrf){
    const refreshed=await call('bootstrap','GET',null,false);
    state.csrf=refreshed.data?.csrfToken||'';
    state.user=refreshed.data?.user||state.user;
    return call(action,method,body,false);
  }
  if(!response.ok||!result.ok)throw new Error(result.error||'Yêu cầu thất bại.');
  if(result.data?.csrfToken)state.csrf=result.data.csrfToken;
  return result;
}
function renderAccount() {
  const label=$('#accountLabel'), avatar=$('#accountAvatar'), menu=$('#accountMenu');
  if(!label||!avatar)return;
  const user=state.user;
  label.textContent=user?.name||'Đăng nhập';
  avatar.textContent=(user?.name||'H').trim().slice(0,1).toUpperCase()||'H';
  if(menu) {
    $('#accountMenuName').textContent=user?.name||'Tài khoản';
    $('#accountMenuEmail').textContent=user?.email||'';
    menu.hidden=true;
  }
  $('#accountButton')?.setAttribute('aria-expanded','false');
}
function updateAuthControls() {
  const visible=!!(state.googleOAuthEnabled&&state.neonAuthEnabled&&($('.auth-tab.active')?.dataset.authMode||'login')==='login');
  const button=$('#googleSignInButton'), divider=$('#authDivider');
  if(button)button.hidden=!visible;
  if(divider)divider.hidden=!visible;
}
function assetUrl(value) {
  if(!value)return 'images/dummy.png';
  if(String(value).indexOf('media:')===0)return 'api.php?action=media&file='+encodeURIComponent(String(value).slice(6));
  return String(value);
}
function renderCategories() {
  const list=$('#categoryList'), mobile=$('#mobileCategory'); if(!list)return;
  const cats=['Tất cả'].concat(state.categories);
  list.innerHTML=cats.map(c=>'<button class="category-button '+(state.category===c?'active':'')+'" data-category="'+esc(c)+'" type="button">'+(c==='Tất cả'?'✳':'◈')+' '+esc(c)+'</button>').join('');
  if(mobile)mobile.innerHTML=cats.map(c=>'<option value="'+esc(c)+'" '+(state.category===c?'selected':'')+'>'+esc(c)+'</option>').join('');
}
function appCard(p, featured) {
  const href='apps/'+encodeURIComponent(p.slug);
  const icon=esc(assetUrl(p.icon));
  if(featured) {
    return '<a class="featured-card" href="'+href+'"><img class="app-icon" src="'+icon+'" alt="" loading="lazy"><div class="featured-info"><span class="featured-label">'+(p.featured?'EDITOR PICK':'ĐƯỢC GIỚI THIỆU')+'</span><h3>'+esc(p.title)+'</h3><p>'+esc(p.shortDescription)+'</p><div class="featured-meta">'+esc(p.developer)+' · '+Number(p.downloads).toLocaleString('vi-VN')+' lượt tải</div></div></a>';
  }
  const price=p.priceType==='Paid'?(Number(p.priceAmountVnd||0).toLocaleString('vi-VN')+' ₫'):(p.priceType==='Freemium'?'Freemium':'Miễn phí');
  return '<a class="app-card" href="'+href+'"><div class="app-card-top"><img class="app-icon" src="'+icon+'" alt="" loading="lazy"><div><h3>'+esc(p.title)+'</h3><div class="app-developer">'+esc(p.developer)+'</div></div></div><p class="app-description">'+esc(p.shortDescription)+'</p><div class="app-meta"><span>★ '+Number(p.rating||0).toFixed(1)+' · '+Number(p.downloads||0).toLocaleString('vi-VN')+' lượt tải</span><span class="app-price">'+price+'</span></div></a>';
}
async function loadProducts() {
  const grid=$('#appGrid'); if(!grid)return;
  grid.innerHTML='<div class="loading-card">Đang tải ứng dụng…</div>';
  const filters={page:state.page,limit:18,sort:state.sort,category:state.category,query:state.query};
  let result=null;
  try {
    if(!window.HendyNeon?.listPublicCatalog) throw new Error('Neon Data API client is unavailable.');
    result=await window.HendyNeon.listPublicCatalog(filters);
  } catch(dataApiError) {
    // Keep the PHP API as a compatibility path while Data API connectivity is recovered.
    console.warn('[Hendy Store] Data API catalogue fallback:', dataApiError?.message || 'request failed');
    try {
      const url=new URL(api); url.searchParams.set('action','products');
      url.searchParams.set('page',String(state.page)); url.searchParams.set('limit','18');
      url.searchParams.set('sort',state.sort);
      if(state.category!=='Tất cả')url.searchParams.set('category',state.category);
      if(state.query)url.searchParams.set('q',state.query);
      const headers={Accept:'application/json'};
      try {
        const token=window.HendyNeon?await window.HendyNeon.getToken():null;
        if(token)headers.Authorization='Bearer '+token;
      } catch (_) {}
      const response=await fetch(url,{headers,credentials:'same-origin'});
      result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.error||'Không tải được danh sách.');
    } catch(error) {
      grid.innerHTML='<div class="loading-card">'+esc(error.message||'Không thể tải danh mục.')+'</div>';
      return;
    }
  }
  grid.innerHTML=(result.data||[]).map(p=>appCard(p,false)).join('');
  state.pages=Math.max(1,Number(result.meta?.pages||1));
  $('#resultTitle').textContent=state.category==='Tất cả'?'Tất cả ứng dụng':state.category;
  $('#resultCount').textContent=Number(result.meta?.total||0).toLocaleString('vi-VN')+' kết quả';
  $('#statApps').textContent=Number(result.meta?.total||0).toLocaleString('vi-VN');
  $('#pagination').classList.toggle('hidden',state.pages<=1);
  $('#pageLabel').textContent=state.page+' / '+state.pages;
  $('#prevPage').disabled=state.page<=1; $('#nextPage').disabled=state.page>=state.pages;
  $('#emptyState').classList.toggle('hidden',(result.data||[]).length!==0);
}
async function loadStore() {
  const grid=$('#featuredGrid'); if(!grid)return;
  try {
    const result=await call('bootstrap');
    state.csrf=result.data.csrfToken||''; state.user=result.data.user||null;
    state.categories=result.data.categories||[];
    state.registration=!!(result.data.neonAuthPublicSignup || result.data.registrationEnabled);
    state.neonAuthEnabled=!!result.data.neonAuthEnabled;
    state.googleOAuthEnabled=!!result.data.googleOAuthEnabled;
    state.realtimeEnabled=!!result.data.realtimeEnabled;
    renderCategories();
    renderAccount();
    updateAuthControls();
    grid.innerHTML=(result.data.featured||[]).map(p=>appCard(p,true)).join('')||'<div class="loading-card">Chưa có ứng dụng nổi bật.</div>';
    if(state.user){$('#accountLabel').textContent=state.user.name;$('#accountAvatar').textContent=(state.user.name||'H').slice(0,1).toUpperCase();}
    if(!state.registration){const registerButton=$('[data-auth-mode="register"]');if(registerButton)registerButton.hidden=true;}
  } catch(error) {
    grid.innerHTML='<div class="loading-card">'+esc(error.message)+'</div>';
  }
  await loadProducts();
  if (window.HendyNeon && state.realtimeEnabled) {
    window.HendyNeon.subscribeCatalog().catch(() => {});
  }
}
function authMode(mode) {
  all('.auth-tab').forEach(button=>button.classList.toggle('active',button.dataset.authMode===mode));
  $('#nameField').classList.toggle('hidden',mode!=='register');
  $('#authTitle').textContent=mode==='register'?'Tạo tài khoản':'Chào mừng trở lại';
  $('#authSubmit').textContent=mode==='register'?'Tạo tài khoản →':'Đăng nhập →';
  $('#authMessage').textContent='';
  updateAuthControls();
}
function showAuth() {
  const dialog=$('#authDialog');
  if(dialog&&typeof dialog.showModal==='function')dialog.showModal();
  else notify('Trình duyệt không hỗ trợ cửa sổ đăng nhập.');
}
function bindEvents() {
  $('#searchForm')?.addEventListener('submit',event=>{event.preventDefault();state.query=$('#searchInput').value.trim();state.page=1;loadProducts();});
  $('#searchInput')?.addEventListener('input',()=>{
    window.clearTimeout(window.hendySearchTimer);
    window.hendySearchTimer=window.setTimeout(()=>{state.query=$('#searchInput').value.trim();state.page=1;loadProducts();},250);
  });
  $('#categoryList')?.addEventListener('click',event=>{
    const button=event.target.closest('[data-category]');if(!button)return;
    state.category=button.dataset.category;state.page=1;renderCategories();loadProducts();
  });
  $('#mobileCategory')?.addEventListener('change',event=>{state.category=event.target.value;state.page=1;renderCategories();loadProducts();});
  $('#sortSelect')?.addEventListener('change',event=>{state.sort=event.target.value;state.page=1;loadProducts();});
  $('#prevPage')?.addEventListener('click',()=>{state.page=Math.max(1,state.page-1);loadProducts();});
  $('#nextPage')?.addEventListener('click',()=>{state.page=Math.min(state.pages,state.page+1);loadProducts();});
  $('#clearFilters')?.addEventListener('click',()=>{state.query='';state.category='Tất cả';state.page=1;$('#searchInput').value='';renderCategories();loadProducts();});
  $('#themeToggle')?.addEventListener('click',()=>document.body.classList.toggle('dark-theme'));
  $('#accountButton')?.addEventListener('click',()=>{
    if(!state.user){showAuth();return;}
    const menu=$('#accountMenu');if(!menu)return;
    menu.hidden=!menu.hidden;
    $('#accountButton').setAttribute('aria-expanded',String(!menu.hidden));
  });
  document.addEventListener('click',event=>{
    if(event.target.closest('.header-actions'))return;
    const menu=$('#accountMenu');if(menu)menu.hidden=true;
    $('#accountButton')?.setAttribute('aria-expanded','false');
  });
  $('#accountSignOut')?.addEventListener('click',async()=>{
    const button=$('#accountSignOut');button.disabled=true;
    try {
      try{await call('logout','POST',{});}catch(_){}
      if(window.HendyNeon){try{await window.HendyNeon.signOut();}catch(_){}}
      state.user=null;renderAccount();notify('Bạn đã đăng xuất.');
    } catch(error) {notify(error.message||'Không thể đăng xuất.');}
    finally {button.disabled=false;}
  });
  $('#googleSignInButton')?.addEventListener('click',async()=>{
    const button=$('#googleSignInButton'), message=$('#authMessage');
    if(!window.HendyNeon){message.textContent='Thành phần Neon Auth chưa sẵn sàng.';return;}
    button.disabled=true;message.textContent='Đang chuyển đến Google…';
    try {
      await window.HendyNeon.signInGoogle(new URL('/',window.location.origin).toString());
      message.textContent='Đang hoàn tất đăng nhập…';
    } catch(error) {
      message.textContent=error.message||'Đăng nhập Google thất bại.';
      button.disabled=false;
    }
  });
  $('#closeAuth')?.addEventListener('click',()=>$('#authDialog').close());
  all('.auth-tab').forEach(button=>button.addEventListener('click',()=>authMode(button.dataset.authMode)));
  $('#authForm')?.addEventListener('submit',async event=>{
    event.preventDefault();
    const form=event.currentTarget, mode=$('.auth-tab.active')?.dataset.authMode||'login';
    const body=Object.fromEntries(new FormData(form).entries()); $('#authMessage').textContent='Đang xử lý…';
    try {
      if (state.neonAuthEnabled && window.HendyNeon) {
        if (mode === 'register') {
          await window.HendyNeon.signUp(body.name, body.email, body.password);
          const session = await window.HendyNeon.getSession();
          if (!session?.user) {
            $('#authMessage').textContent = 'Tài khoản đã được tạo. Hãy kiểm tra email và xác minh địa chỉ trước khi đăng nhập.';
            form.reset();
            return;
          }
        } else {
          await window.HendyNeon.signIn(body.email, body.password);
        }
        const refreshed = await call('bootstrap');
        state.user = refreshed.data.user || null;
        state.csrf = refreshed.data.csrfToken || state.csrf;
        if (!state.user) {
          throw new Error('Đăng nhập Neon Auth thành công nhưng API chưa xác thực được JWT. Kiểm tra NEON_AUTH_JWKS_URL, NEON_AUTH_BASE_URL và issuer của Neon Auth.');
        }
      } else {
        const result=await call(mode,'POST',body);
        state.user=result.data.user;
        state.csrf=result.data.csrfToken||state.csrf;
      }
      renderAccount();
      $('#authDialog').close();notify(mode==='login'?'Đăng nhập thành công.':'Tài khoản đã được tạo.');
    } catch(error) {$('#authMessage').textContent=error.message;}
  });
  if (!window.__hendyRealtimeBound) {
    window.__hendyRealtimeBound = true;
    window.addEventListener('hendy:catalog-live', () => {
      if ($('#appGrid')) loadProducts();
    });
    window.addEventListener('hendy:catalog-live-error', () => {
      // REST listing remains available when a Realtime connection or renewal ends.
      if ($('#appGrid')) loadProducts();
    });
  }
  document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();$('#searchInput')?.focus();}});
  const year=$('#yearNow'); if(year)year.textContent=String(new Date().getFullYear());
}
document.addEventListener('DOMContentLoaded',()=>{bindEvents();loadStore();});
})();
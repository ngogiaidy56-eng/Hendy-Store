(() => {
'use strict';
const $ = (s,root=document)=>root.querySelector(s);
const apiUrl = new URL('/api.php',window.location.origin);
let csrf = '', products = [], selected = null;
const esc = value => String(value ?? '').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
async function call(action,method='GET',body=null,retryCsrf=true) {
  const url = new URL(apiUrl); url.searchParams.set('action',action);
  const headers={Accept:'application/json'};
  if(method!=='GET'){headers['Content-Type']='application/json';if(csrf)headers['X-CSRF-Token']=csrf;}
  try{if(window.HendyNeon?.getToken){const token=await window.HendyNeon.getToken();if(token)headers.Authorization='Bearer '+token;}}catch(_){}
  const response=await fetch(url,{method,credentials:'same-origin',headers,body:body?JSON.stringify(body):undefined});
  const data=await response.json();
  if(response.status===419&&method!=='GET'&&retryCsrf){
    const refreshed=await call('me','GET',null,false);csrf=refreshed.data?.csrfToken||'';
    return call(action,method,body,false);
  }
  if(!response.ok||!data.ok)throw new Error(data.error||'Yêu cầu thất bại.');
  if(data.data?.csrfToken)csrf=data.data.csrfToken;
  return data;
}
async function upload(kind,file,retryCsrf=true) {
  const url=new URL(apiUrl);url.searchParams.set('action','admin_upload');
  const form=new FormData();form.append('kind',kind);form.append('file',file);
  const headers={};if(csrf)headers['X-CSRF-Token']=csrf;
  try{if(window.HendyNeon?.getToken){const token=await window.HendyNeon.getToken();if(token)headers.Authorization='Bearer '+token;}}catch(_){}
  const response=await fetch(url,{method:'POST',credentials:'same-origin',headers,body:form});
  const data=await response.json();
  if(response.status===419&&retryCsrf){const refreshed=await call('me','GET',null,false);csrf=refreshed.data?.csrfToken||'';return upload(kind,file,false);}
  if(!response.ok||!data.ok)throw new Error(data.error||'Tải tệp thất bại.');
  return data.data;
}
function status(id,message){const node=$('#'+id);if(node)node.textContent=message;}
async function loadProductData() {
  selected=Number($('#productSelect').value||0);if(!selected)return;
  await Promise.all([loadVersions(),loadScreenshots(),loadSpecs()]);
}
async function loadVersions(){
  const data=await call('store_admin_versions');const items=data.data.filter(v=>Number(v.product_id)===selected);
  $('#versionList').innerHTML=items.length?items.map(v=>'<article class="store-version"><div class="store-version-head"><strong>v'+esc(v.version)+(Number(v.is_current)===1?' · BẢN MỚI NHẤT':'')+'</strong><span>'+esc(v.platform)+' / '+esc(v.architecture)+' · '+Number(v.package_size_bytes).toLocaleString('vi-VN')+' byte</span></div><p>'+esc(v.release_notes)+'</p><small>'+esc(v.created_at)+'</small></article>').join(''):'<p class="store-muted">Chưa có phiên bản bổ sung. Sản phẩm vẫn có thể dùng gói tải cũ.</p>';
}
async function loadScreenshots(){
  const data=await call('store_admin_screenshots');const items=data.data.filter(s=>Number(s.product_id)===selected);
  $('#screenshotList').innerHTML=items.map(s=>'<div class="store-admin-shot"><span>'+esc(s.caption||s.image_url)+'</span><button class="button button-soft" data-delete-shot="'+s.id+'" type="button">Xóa</button></div>').join('')||'<p class="store-muted">Chưa có ảnh chụp màn hình.</p>';
}
async function loadSpecs(){
  const url=new URL(apiUrl);url.searchParams.set('action','store_admin_specs');url.searchParams.set('product_id',String(selected));
  const headers={};if(csrf)headers['X-CSRF-Token']=csrf;
  try{if(window.HendyNeon?.getToken){const token=await window.HendyNeon.getToken();if(token)headers.Authorization='Bearer '+token;}}catch(_){}
  const response=await fetch(url,{credentials:'same-origin',headers});const data=await response.json();
  if(!response.ok||!data.ok)throw new Error(data.error||'Không tải được thông tin ứng dụng.');
  const specs=data.data||{},form=$('#specsForm');
  form.elements.video_url.value=specs.video_url||'';
  form.elements.minimum_requirements.value=specs.minimum_requirements||'';
  form.elements.privacy_details.value=specs.privacy_details||'';
}
async function loadReviews(){
  const data=await call('store_admin_reviews');
  const items=data.data;
  $('#reviewModeration').innerHTML=items.length?items.map(r=>'<article class="store-admin-review"><div><strong>'+esc(r.product_title)+'</strong> · '+esc(r.author_name)+' · '+Number(r.rating)+'/5</div><p>'+esc(r.review_text)+'</p><small>Trạng thái: '+esc(r.status)+'</small><div class="store-admin-actions">'+(r.status!=='approved'?'<button class="button button-soft" data-review-approve="'+r.id+'">Duyệt</button>':'')+'<button class="button button-soft" data-review-reject="'+r.id+'">Ẩn</button></div><form data-reply-form="'+r.id+'"><input class="admin-input" name="reply" maxlength="2000" placeholder="Phản hồi nhà phát triển" value="'+esc(r.developer_reply||'')+'"><button class="button button-primary" type="submit">Gửi phản hồi</button></form></article>').join(''):'<p class="store-muted">Chưa có đánh giá.</p>';
}
async function boot(){
  try{
    const me=await call('me');csrf=me.data.csrfToken||'';
    if(me.data.user?.role!=='admin')throw new Error('Bạn cần đăng nhập bằng tài khoản quản trị.');
    const result=await call('admin_products');
    products=result.data||[];
    $('#adminStatus').textContent='Đã xác thực quản trị';
    $('#featureAdmin').hidden=false;
    $('#productSelect').innerHTML=products.map(p=>'<option value="'+p.id+'">'+esc(p.title)+' · '+esc(p.slug)+'</option>').join('');
    if(!products.length)throw new Error('Chưa có sản phẩm nào để quản lý.');
    selected=Number($('#productSelect').value);
    await Promise.all([loadProductData(),loadReviews()]);
  }catch(error){
    $('#adminStatus').textContent='Chưa được cấp quyền';
    $('#accessDenied').hidden=false;
    status('accessDenied',error.message);
    const host=$('#accessDenied');host.querySelector('p').textContent=error.message;
  }
}
$('#productSelect').addEventListener('change',()=>loadProductData().catch(e=>status('versionStatus',e.message)));
$('#versionForm').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,data=Object.fromEntries(new FormData(form).entries());
  data.product_id=selected;data.is_current=form.elements.is_current.checked;data.package_size_bytes=Number(data.package_size_bytes||0);
  const file=$('#versionPackage').files?.[0];
  try{
    if(file){status('versionStatus','กำng tải gói lên Object Storage…');const result=await upload('package',file);data.package_url=result.path;if(!data.package_size_bytes)data.package_size_bytes=result.size;}
    await call('store_admin_versions','POST',data);status('versionStatus','Đã phát hành phiên bản và tạo thông báo cho thư viện người dùng.');
    form.reset();form.elements.is_current.checked=true;await loadVersions();
  }catch(error){status('versionStatus',error.message);}
});
$('#screenshotForm').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,file=$('#screenshotFile').files?.[0];if(!file)return;
  try{status('screenshotStatus','Đang tải ảnh lên…');const uploaded=await upload('media',file);
    await call('store_admin_screenshots','POST',{product_id:selected,image_url:uploaded.path,caption:form.elements.caption.value});
    status('screenshotStatus','Đã thêm ảnh.');form.reset();await loadScreenshots();
  }catch(error){status('screenshotStatus',error.message);}
});
$('#specsForm').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,data=Object.fromEntries(new FormData(form).entries());data.product_id=selected;
  try{await call('store_admin_specs','POST',data);status('specsStatus','Đã lưu cấu hình, video và quyền riêng tư.');}
  catch(error){status('specsStatus',error.message);}
});
$('#screenshotList').addEventListener('click',async event=>{
  const button=event.target.closest('[data-delete-shot]');if(!button)return;
  if(!confirm('Xóa ảnh này khỏi trang ứng dụng?'))return;
  try{await call('store_admin_screenshots','POST',{delete:true,id:Number(button.dataset.deleteShot)});await loadScreenshots();}
  catch(error){status('screenshotStatus',error.message);}
});
$('#reviewModeration').addEventListener('click',async event=>{
  const approve=event.target.closest('[data-review-approve]'),reject=event.target.closest('[data-review-reject]');
  const button=approve||reject;if(!button)return;
  try{await call('store_review_moderate','POST',{review_id:Number(button.dataset.reviewApprove||button.dataset.reviewReject),status:approve?'approved':'rejected'});await loadReviews();}
  catch(error){alert(error.message);}
});
$('#reviewModeration').addEventListener('submit',async event=>{
  const form=event.target.closest('[data-reply-form]');if(!form)return;event.preventDefault();
  try{await call('store_review_reply','POST',{review_id:Number(form.dataset.replyForm),reply:form.elements.reply.value});await loadReviews();}
  catch(error){alert(error.message);}
});
document.addEventListener('DOMContentLoaded',boot);
})();
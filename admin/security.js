(() => {
'use strict';
const $ = (selector) => document.querySelector(selector);
const api = new URL('/api.php', window.location.origin);
const state = { csrf: '' };
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function notify(message) {
  const toast = $('#toast'); if (!toast) return;
  toast.textContent = message; toast.classList.add('show');
  window.setTimeout(() => toast.classList.remove('show'), 3000);
}
async function call(action, method = 'GET', body = null, retryCsrf = true) {
  const url = new URL(api); url.searchParams.set('action', action);
  const headers = { Accept: 'application/json' };
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
  }
  try {
    const token = window.HendyNeon ? await window.HendyNeon.getToken() : null;
    if (token) headers.Authorization = 'Bearer ' + token;
  } catch (_) {}
  const response = await fetch(url, { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined });
  let result; try { result = await response.json(); } catch (_) { throw new Error('Dữ liệu API không hợp lệ.'); }
  if (response.status === 419 && method !== 'GET' && retryCsrf) {
    const refreshed = await call('me', 'GET', null, false);
    state.csrf = refreshed.data?.csrfToken || '';
    return call(action, method, body, false);
  }
  if (!response.ok || !result.ok) throw new Error(result.error || 'Yêu cầu không thành công.');
  if (result.data?.csrfToken) state.csrf = result.data.csrfToken;
  return result;
}
const badge = (value) => '<span class="sec-badge ' + esc(value) + '">' + esc(value) + '</span>';

function renderDevelopers(items) {
  const target = $('#developerQueue');
  if (!items.length) { target.innerHTML = '<div class="sec-state">Không có hồ sơ nhà phát triển đang chờ duyệt.</div>'; return; }
  target.innerHTML = items.map((item) => '<article class="sec-card"><div class="sec-top"><div><strong>' + esc(item.display_name) + '</strong><div class="sec-meta">' +
    esc(item.account_name) + ' · ' + esc(item.account_email) + '<br>' + esc(item.support_email) + (item.website_url ? ' · ' + esc(item.website_url) : '') +
    '<br>Gửi lúc: ' + esc(item.created_at) + '</div></div>' + badge(item.status) + '</div>' +
    '<input class="sec-note" type="text" maxlength="2000" placeholder="Ghi chú phản hồi cho nhà phát triển" data-developer-note="' + Number(item.user_id) + '">' +
    '<div class="sec-actions"><button class="sec-btn" data-developer-action="approved" data-user-id="' + Number(item.user_id) + '">Duyệt hồ sơ</button><button class="sec-btn danger" data-developer-action="rejected" data-user-id="' + Number(item.user_id) + '">Từ chối</button></div></article>').join('');
}

function renderReleases(items) {
  const target = $('#releaseQueue');
  if (!items.length) { target.innerHTML = '<div class="sec-state">Không có ứng dụng đang chờ kiểm duyệt.</div>'; return; }
  target.innerHTML = items.map((item) => '<article class="sec-card"><div class="sec-top"><div><strong>' + esc(item.title) + ' · v' + esc(item.version) + '</strong><div class="sec-meta">' +
    'Nhà phát triển: ' + esc(item.developer) + ' · ' + esc(item.submitter_email) + '<br>Danh mục: ' + esc(item.category) + ' · Kênh: ' + esc(item.release_track) +
    '<br>Dung lượng: ' + Number(item.package_size_bytes || 0).toLocaleString('vi-VN') + ' bytes<br>SHA-256: ' + esc(item.sha256 || 'Chưa có') +
    '<br>Object: ' + esc(item.object_key || 'External URL — không tự động quét') + '</div></div><div>' + badge(item.moderation_status) + ' ' + badge(item.scan_status) + '</div></div>' +
    (item.review_note ? '<div class="sec-meta">Ghi chú: ' + esc(item.review_note) + '</div>' : '') +
    '<input class="sec-note" type="text" maxlength="2000" placeholder="Ghi chú kiểm duyệt" data-release-note="' + Number(item.product_id) + '">' +
    '<div class="sec-actions"><button class="sec-btn alt" data-scan="' + Number(item.product_id) + '">Quét / làm mới kết quả</button>' +
    '<button class="sec-btn" data-release-action="approved" data-product-id="' + Number(item.product_id) + '" ' + (item.scan_status === 'clean' && item.sha256 ? '' : 'disabled') + '>Duyệt xuất bản</button>' +
    '<button class="sec-btn danger" data-release-action="rejected" data-product-id="' + Number(item.product_id) + '">Từ chối</button></div></article>').join('');
}

async function loadQueue() {
  const me = await call('me');
  state.csrf = me.data.csrfToken || '';
  if (!me.data.user || me.data.user.role !== 'admin') {
    $('#securityLogin').hidden = false;
    $('#developerQueue').innerHTML = '';
    $('#releaseQueue').innerHTML = '';
    return;
  }
  $('#securityLogin').hidden = true;
  const result = await call('trust_queue');
  renderDevelopers(result.data.developers || []);
  renderReleases(result.data.releases || []);
}

async function reviewDeveloper(userId, status) {
  const note = $('[data-developer-note="' + userId + '"]')?.value || '';
  await call('trust_developer_review', 'POST', { user_id: userId, status, note });
  notify(status === 'approved' ? 'Đã duyệt hồ sơ nhà phát triển.' : 'Đã từ chối hồ sơ.');
  await loadQueue();
}
async function reviewRelease(productId, status) {
  const note = $('[data-release-note="' + productId + '"]')?.value || '';
  await call('trust_release_review', 'POST', { product_id: productId, status, note });
  notify(status === 'approved' ? 'Ứng dụng đã được xuất bản.' : 'Đã từ chối phát hành.');
  await loadQueue();
}
async function refreshScan(productId, button) {
  button.disabled = true;
  button.textContent = 'Đang quét…';
  try {
    const result = await call('trust_scan_refresh', 'POST', { product_id: productId });
    notify('Kết quả quét: ' + (result.data.scan?.status || 'unknown'));
    await loadQueue();
  } catch (error) {
    notify(error.message);
  } finally { button.disabled = false; }
}
document.addEventListener('DOMContentLoaded', async () => {
  document.addEventListener('click', async (event) => {
    const developer = event.target.closest('[data-developer-action]');
    const release = event.target.closest('[data-release-action]');
    const scan = event.target.closest('[data-scan]');
    try {
      if (developer) {
        developer.disabled = true;
        await reviewDeveloper(Number(developer.dataset.userId), developer.dataset.developerAction);
      } else if (release) {
        release.disabled = true;
        await reviewRelease(Number(release.dataset.productId), release.dataset.releaseAction);
      } else if (scan) {
        await refreshScan(Number(scan.dataset.scan), scan);
      }
    } catch (error) {
      notify(error.message);
      if (developer) developer.disabled = false;
      if (release) release.disabled = false;
    }
  });
  try { await loadQueue(); }
  catch (error) {
    $('#securityLogin').hidden = false;
    $('#securityLogin').innerHTML = '<h2>Không tải được hàng chờ</h2><p>' + esc(error.message) + '</p><a class="button button-primary" href="./">Đến Admin Console</a>';
  }
});
})();
(() => {
'use strict';
const $ = selector => document.querySelector(selector);
const apiBase = new URL('/api.php', window.location.origin);
let csrfToken = '';

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
  '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
}[char]));

async function call(action, method='GET', body=null, retryCsrf=true) {
  const url = new URL(apiBase);
  url.searchParams.set('action', action);
  const headers = {Accept:'application/json'};
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  }
  try {
    if (window.HendyNeon?.getToken) {
      const token = await window.HendyNeon.getToken();
      if (token) headers.Authorization = 'Bearer ' + token;
    }
  } catch (_) {}
  const response = await fetch(url, {
    method, credentials:'same-origin', headers,
    body: body ? JSON.stringify(body) : undefined
  });
  let data;
  try { data = await response.json(); }
  catch (_) { throw new Error('Máy chủ trả về dữ liệu không hợp lệ.'); }
  if (response.status === 419 && method !== 'GET' && retryCsrf) {
    const refreshed = await call('me', 'GET', null, false);
    csrfToken = refreshed.data?.csrfToken || '';
    return call(action, method, body, false);
  }
  if (!response.ok || !data.ok) throw new Error(data.error || 'Yêu cầu thất bại.');
  if (data.data?.csrfToken) csrfToken = data.data.csrfToken;
  return data;
}
function imageUrl(value) {
  const source = String(value || '');
  if (source.startsWith('media:')) return '/api.php?action=media&file=' + encodeURIComponent(source.slice(6));
  if (/^https:\/\//i.test(source)) return source;
  return '../' + (source || 'images/dummy.png').replace(/^\/+/, '');
}
async function load() {
  try {
    const me = await call('me');
    csrfToken = me.data.csrfToken || '';
    const results = await Promise.all([
      call('store_library'),
      call('store_notifications'),
      call('store_download_history')
    ]);
    $('#libraryIdentity').textContent = me.data.user?.name || 'Tài khoản';
    $('#libraryContent').hidden = false;

    const items = results[0].data || [];
    $('#libraryItems').innerHTML = items.length ? items.map(item => (
      '<article class="store-library-item"><img src="' + escapeHtml(imageUrl(item.icon)) + '" alt="">' +
      '<div><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.developer) +
      '</small><p>Đã tải: ' + escapeHtml(item.downloaded_version || 'Chưa ghi nhận') +
      ' · Hiện tại: ' + escapeHtml(item.latest_version || item.current_version) + '</p>' +
      (item.update_available ? '<b class="store-update">Có phiên bản mới cho ' + escapeHtml(item.last_platform) + '</b>' : '') +
      '</div><a class="button button-soft" href="../apps/' + encodeURIComponent(item.slug) + '">' +
      (item.update_available ? 'Cập nhật' : 'Mở ứng dụng') + '</a></article>'
    )).join('') : '<p class="store-muted">Chưa có ứng dụng trong thư viện. Đăng nhập trước khi tải ứng dụng miễn phí để tự động lưu vào thư viện.</p>';

    const notices = results[1].data || [];
    $('#libraryNotifications').innerHTML = notices.length ? notices.map(item => (
      '<article class="store-notice ' + (item.read_at ? '' : 'unread') + '"><div><strong>' +
      escapeHtml(item.title) + '</strong><p>' + escapeHtml(item.message) + '</p><small>' +
      escapeHtml(item.created_at) + '</small></div>' +
      (item.read_at ? '' : '<button class="button button-soft" data-read="' + Number(item.id) + '" type="button">Đã đọc</button>') +
      '</article>'
    )).join('') : '<p class="store-muted">Chưa có thông báo mới.</p>';

    const history = results[2].data || [];
    $('#libraryHistory').innerHTML = history.length ? '<div class="store-history-list">' +
      history.map(item => '<div><span><b>' + escapeHtml(item.title) + '</b><small>' +
        escapeHtml(item.platform) + ' · ' + escapeHtml(item.version || 'Bản hiện tại') +
        '</small></span><small>' + escapeHtml(item.created_at) + '</small></div>').join('') +
      '</div>' : '<p class="store-muted">Chưa có lịch sử tải xuống.</p>';
  } catch (error) {
    $('#libraryIdentity').textContent = 'Chưa đăng nhập';
    $('#libraryLogin').hidden = false;
    const message = $('#libraryLogin').querySelector('p');
    if (message) message.textContent = error.message;
  }
}

$('#libraryNotifications').addEventListener('click', async event => {
  const button = event.target.closest('[data-read]');
  if (!button) return;
  try {
    await call('store_notification_read', 'POST', {id:Number(button.dataset.read)});
    await load();
  } catch (error) { alert(error.message); }
});
$('#readAll').addEventListener('click', async () => {
  try {
    await call('store_notification_read', 'POST', {});
    await load();
  } catch (error) { alert(error.message); }
});
document.addEventListener('DOMContentLoaded', load);
})();
(() => {
'use strict';
const $ = (selector) => document.querySelector(selector);
const api = new URL('../api.php', document.baseURI);
const state = { csrf: '', user: null, profile: null, apps: [], packageToken: '', scan: null };
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

function notify(message) {
  const toast = $('#toast');
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add('show');
  window.setTimeout(() => toast.classList.remove('show'), 3000);
}

async function call(action, method = 'GET', body = null, extra = {}, retryCsrf = true) {
  const url = new URL(api);
  url.searchParams.set('action', action);
  if (extra.query) Object.entries(extra.query).forEach(([key, value]) => url.searchParams.set(key, String(value)));
  const headers = { Accept: 'application/json' };
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
  }
  try {
    const token = window.HendyNeon ? await window.HendyNeon.getToken() : null;
    if (token) headers.Authorization = 'Bearer ' + token;
  } catch (_) {}
  const response = await fetch(url, {
    method, credentials: 'same-origin', headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  let result;
  try { result = await response.json(); }
  catch (_) { throw new Error('Máy chủ trả về dữ liệu không hợp lệ.'); }
  if (response.status === 419 && method !== 'GET' && retryCsrf) {
    const refreshed = await call('bootstrap', 'GET', null, {}, false);
    state.csrf = refreshed.data?.csrfToken || '';
    state.user = refreshed.data?.user || state.user;
    return call(action, method, body, extra, false);
  }
  if (!response.ok || !result.ok) throw new Error(result.error || 'Yêu cầu không thành công.');
  if (result.data?.csrfToken) state.csrf = result.data.csrfToken;
  return result;
}

function setState(element, message, tone = '') {
  if (!element) return;
  element.textContent = message;
  element.className = 'dev-state' + (tone ? ' ' + tone : '');
}

function fillProfile(profile) {
  state.profile = profile;
  const form = $('#developerProfileForm');
  if (!form) return;
  if (profile) {
    form.elements.display_name.value = profile.display_name || '';
    form.elements.support_email.value = profile.support_email || state.user?.email || '';
    form.elements.website_url.value = profile.website_url || '';
    const label = profile.status === 'approved' ? 'Đã được duyệt' : (profile.status === 'rejected' ? 'Bị từ chối' : 'Đang chờ duyệt');
    setState($('#profileStatus'), label + (profile.review_note ? '\nGhi chú: ' + profile.review_note : ''), profile.status === 'approved' ? 'success' : '');
    $('#releaseCard').hidden = profile.status !== 'approved';
  } else {
    form.elements.support_email.value = state.user?.email || '';
    setState($('#profileStatus'), 'Bạn chưa gửi hồ sơ. Hãy điền thông tin và gửi xét duyệt.');
    $('#releaseCard').hidden = true;
  }
}

function renderApps() {
  const target = $('#developerApps');
  if (!state.apps.length) {
    target.innerHTML = '<div class="dev-state">Chưa có ứng dụng do hồ sơ này gửi lên.</div>';
    return;
  }
  target.innerHTML = state.apps.map((app) => {
    const mod = app.moderation_status || app.status || 'pending';
    const scan = app.scan_status || 'not_scanned';
    return '<article class="dev-row"><div><strong>' + esc(app.title) + ' · v' + esc(app.version) + '</strong><small>' +
      'Lượt tải: ' + Number(app.downloads || 0).toLocaleString('vi-VN') +
      ' · Kênh: ' + esc(app.release_track || 'production') +
      ' · SHA-256: ' + esc(app.sha256 || 'Chưa có') +
      (app.review_note ? '<br>Ghi chú kiểm duyệt: ' + esc(app.review_note) : '') +
      '</small></div><div><span class="dev-badge ' + esc(mod) + '">' + esc(mod) + '</span> <span class="dev-badge ' + esc(scan) + '">' + esc(scan) + '</span> ' +
      '<button class="dev-button subtle" type="button" data-versions="' + Number(app.id) + '">Phiên bản</button> <button class="dev-button subtle" type="button" data-analytics="' + Number(app.id) + '">Thống kê</button></div><div id="versions-' + Number(app.id) + '" class="dev-list"></div></article>';
  }).join('');
}

async function loadVersions(productId) {
  const target = $('#versions-' + productId);
  if (!target) return;
  target.textContent = 'Đang tải lịch sử phiên bản…';
  try {
    const result = await call('developer_versions', 'GET', null, { query: { product_id: productId } });
    const versions = result.data || [];
    if (!versions.length) {
      target.innerHTML = '<div class="dev-state">Chưa có phiên bản Production nào được duyệt.</div>';
      return;
    }
    target.innerHTML = versions.map((version) => '<article class="dev-row"><div><strong>v' + esc(version.version) + ' · ' + esc(version.platform) + '</strong><small>' +
      'Dung lượng: ' + Number(version.package_size_bytes || 0).toLocaleString('vi-VN') + ' bytes<br>SHA-256: ' + esc(version.sha256) +
      '<br>Phát hành: ' + esc(version.created_at) + '</small></div><div>' +
      (Number(version.is_current) === 1 ? '<span class="dev-badge approved">Đang sử dụng</span>' :
        '<button class="dev-button subtle" type="button" data-rollback-product="' + productId + '" data-rollback-version="' + Number(version.id) + '">Khôi phục bản này</button>') +
      '</div></article>').join('');
  } catch (error) {
    setState(target, error.message, 'error');
  }
}

async function rollbackVersion(productId, versionId) {
  if (!window.confirm('Khôi phục phiên bản Production này? Phiên bản sẽ trở thành bản tải mặc định cho nền tảng tương ứng.')) return;
  await call('developer_rollback', 'POST', { product_id: productId, version_id: versionId });
  notify('Đã khôi phục phiên bản Production.');
  await loadWorkspace();
  await loadVersions(productId);
}

async function loadAnalytics(productId) {
  const target = $('#analyticsContent');
  target.textContent = 'Đang tải số liệu…';
  try {
    const result = await call('developer_analytics', 'GET', null, { query: { product_id: productId } });
    const data = result.data;
    const eventRows = data.events.map((event) => '<article class="dev-row"><div><strong>' + esc(event.event_type) + '</strong><small>' + esc(event.platform) + '</small></div><b>' + Number(event.total).toLocaleString('vi-VN') + '</b></article>').join('');
    const dayTotals = new Map();
    (data.daily || []).forEach((item) => dayTotals.set(item.day, (dayTotals.get(item.day) || 0) + Number(item.total || 0)));
    const maxDaily = Math.max(1, ...dayTotals.values());
    const dailyRows = [...dayTotals.entries()].map(([day, total]) => '<div class="dev-analytics-row"><span>' + esc(day) + '</span><div class="dev-analytics-track"><i style="width:' + Math.max(2, Math.round(total / maxDaily * 100)) + '%"></i></div><b>' + total.toLocaleString('vi-VN') + '</b></div>').join('');
    const crashRows = data.crashes.map((crash) => '<article class="dev-row"><div><strong>' + esc(crash.version || 'Không rõ phiên bản') + ' · ' + esc(crash.platform) + '</strong><small>' + esc(crash.error_message) + '</small></div><span class="dev-badge">' + esc(crash.status) + '</span></article>').join('');
    target.innerHTML = '<div class="dev-state">Sản phẩm: ' + esc(data.product.title) + ' · Tổng lượt tải hoàn tất: ' + Number(data.product.downloads).toLocaleString('vi-VN') + '</div>' +
      '<h3>Hoạt động 30 ngày gần đây</h3>' + (dailyRows || '<p>Chưa có dữ liệu trong 30 ngày.</p>') +
      '<h3>Tổng hợp sự kiện theo nền tảng</h3>' + (eventRows || '<p>Chưa có sự kiện.</p>') +
      '<h3>Báo cáo lỗi</h3>' + (crashRows || '<p>Chưa có crash report.</p>');
  } catch (error) {
    setState(target, error.message, 'error');
  }
}

async function loadDeveloperReviews() {
  const target = $('#developerReviews');
  if (!target) return;
  try {
    const result = await call('developer_reviews');
    const reviews = result.data || [];
    if (!reviews.length) {
      target.innerHTML = '<div class="dev-state">Chưa có đánh giá đã duyệt cho ứng dụng của bạn.</div>';
      return;
    }
    target.innerHTML = reviews.map((review) => {
      const replyBox = review.status === 'approved'
        ? '<form class="dev-review-reply" data-review-id="' + Number(review.id) + '"><label class="dev-field">Phản hồi của nhà phát triển<textarea name="reply" maxlength="2000" required>' + esc(review.developer_reply || '') + '</textarea></label><button class="dev-button subtle" type="submit">Lưu phản hồi</button></form>'
        : '<span class="dev-badge pending">Đánh giá đang chờ kiểm duyệt</span>';
      return '<article class="dev-card"><strong>' + esc(review.product_title) + ' · ' + esc(review.author_name) + '</strong><div class="dev-meta">Đánh giá: ' + '★'.repeat(Number(review.rating)) + '☆'.repeat(5-Number(review.rating)) + ' · ' + esc(review.created_at) + '</div><p>' + esc(review.review_text) + '</p>' + replyBox + '</article>';
    }).join('');
  } catch (error) {
    setState(target, error.message, 'error');
  }
}

async function submitDeveloperReply(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    const result = await call('developer_review_reply', 'POST', {
      review_id: Number(form.dataset.reviewId),
      reply: form.elements.reply.value,
    });
    notify('Đã lưu phản hồi đánh giá.');
    await loadDeveloperReviews();
  } catch (error) {
    notify(error.message);
    button.disabled = false;
  }
}

async function loadWorkspace() {
  const bootstrap = await call('bootstrap');
  state.csrf = bootstrap.data.csrfToken || '';
  state.user = bootstrap.data.user || null;
  if (!state.user) {
    $('#developerIdentity').textContent = 'Chưa đăng nhập';
    $('#developerGate').hidden = false;
    $('#developerWorkspace').hidden = true;
    return;
  }
  $('#developerIdentity').textContent = state.user.name + ' · ' + state.user.email;
  $('#developerGate').hidden = true;
  $('#developerWorkspace').hidden = false;
  const profile = await call('developer_profile');
  fillProfile(profile.data.profile);
  if (profile.data.profile?.status === 'approved') {
    const apps = await call('developer_apps');
    state.apps = apps.data || [];
    renderApps();
    await loadDeveloperReviews();
  } else {
    $('#developerApps').innerHTML = '<div class="dev-state">Hồ sơ nhà phát triển phải được duyệt trước khi quản lý phát hành.</div>';
  }
  setState($('#signingStatus'), 'Chưa cấu hình dịch vụ ký số ngoài hệ thống. Không tải tệp khóa riêng tư lên đây.');
}

async function submitProfile(event) {
  event.preventDefault();
  const button = event.currentTarget.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    const form = Object.fromEntries(new FormData(event.currentTarget).entries());
    const result = await call('developer_profile_save', 'POST', form);
    setState($('#profileStatus'), 'Đã gửi hồ sơ. Trạng thái: ' + result.data.status + '. Chờ quản trị viên xét duyệt.');
    notify('Hồ sơ nhà phát triển đã được gửi.');
    await loadWorkspace();
  } catch (error) {
    setState($('#profileStatus'), error.message, 'error');
  } finally { button.disabled = false; }
}

async function waitForScan(token) {
  const output = $('#uploadStatus');
  for (let attempt = 0; attempt < 10; attempt += 1) {
    const refreshed = await call('developer_scan_refresh', 'POST', { object_token: token });
    const scan = refreshed.data;
    state.scan = scan;
    const details = 'Trạng thái quét: ' + scan.status + '\nSHA-256: ' + (scan.sha256 || 'Đang tính toán') +
      '\nDung lượng: ' + Number(scan.size || 0).toLocaleString('vi-VN') + ' bytes\n' + (scan.scan_summary || '');
    setState(output, details, scan.status === 'clean' ? 'success' : (['malicious','integrity_error','error'].includes(scan.status) ? 'error' : ''));
    if (scan.status === 'clean' || ['malicious','integrity_error','error','missing_config','unavailable'].includes(scan.status)) return scan;
    await wait(8000);
  }
  return state.scan || { status: 'pending' };
}

async function uploadPackage(file) {
  const intent = await call('developer_upload_intent', 'POST', {
    file_name: file.name,
    size: file.size,
    content_type: file.type || 'application/octet-stream',
  });
  const upload = intent.data;
  const put = await fetch(upload.upload_url, {
    method: 'PUT',
    headers: { 'Content-Type': upload.content_type },
    body: file,
    credentials: 'omit',
  });
  if (!put.ok) {
    throw new Error('Cloud storage từ chối upload (HTTP ' + put.status + '). Kiểm tra CORS bucket và header Content-Type.');
  }
  state.packageToken = upload.object_token;
  // Server downloads the private object once to calculate authoritative SHA-256
  // and scan it. No large file is posted through the PHP application server.
  const finalized = await call('developer_upload_finalize', 'POST', { object_token: state.packageToken });
  const scan = finalized.data.scan || {};
  setState($('#uploadStatus'), 'Trạng thái quét: ' + (scan.status || 'pending') +
    '\nSHA-256: ' + (finalized.data.sha256 || 'Đang chờ') +
    '\n' + (scan.summary || ''), scan.status === 'clean' ? 'success' : '');
  if (scan.status === 'pending') return waitForScan(state.packageToken);
  return scan;
}

async function submitReleaseRecord(formElement) {
  const form = Object.fromEntries(new FormData(formElement).entries());
  const payload = {
    title: form.title,
    slug: form.slug,
    category: form.category,
    shortDescription: form.short_description,
    description: form.description,
    version: form.version,
    platform: form.platform,
    release_platform: form.release_platform,
    release_track: form.release_track,
    release_notes: form.release_notes,
    minimum_requirements: form.minimum_requirements,
    privacy_details: form.privacy_details,
    video_url: form.video_url,
    object_token: state.packageToken,
    icon: 'images/dummy.png',
    cover: 'images/dummy.png',
    screenshots: String(form.screenshots_urls || '').split(/\r?\n/).map((value) => value.trim()).filter(Boolean).slice(0, 12),
  };
  const created = await call('developer_submit', 'POST', payload);
  setState($('#releaseResult'), 'Đã gửi phát hành vào hàng chờ kiểm duyệt.\nMã ứng dụng: #' + created.data.product_id +
    '\nTrạng thái: pending · Kênh: ' + form.release_track, 'success');
  notify('Gói đã được gửi kiểm duyệt.');
  formElement.reset();
  $('#releasePackage').value = '';
  $('#refreshPackageScan').hidden = true;
  state.packageToken = '';
  await loadWorkspace();
}

async function refreshAndSubmitScannedPackage() {
  const button = $('#refreshPackageScan');
  button.disabled = true;
  try {
    if (!state.packageToken) throw new Error('Không còn mã gói tải trong phiên hiện tại. Hãy tải gói lại.');
    const result = await call('developer_scan_refresh', 'POST', { object_token: state.packageToken });
    const scan = result.data || {};
    setState($('#uploadStatus'), 'Trạng thái quét: ' + (scan.status || 'unknown') +
      '\nSHA-256: ' + (scan.sha256 || '') + '\n' + (scan.scan_summary || ''),
      scan.status === 'clean' ? 'success' : (['malicious','integrity_error','error'].includes(scan.status) ? 'error' : ''));
    if (scan.status === 'clean') {
      button.hidden = true;
      await submitReleaseRecord($('#releaseForm'));
    } else {
      button.hidden = false;
      notify('Gói chưa sẵn sàng. Trạng thái: ' + (scan.status || 'unknown'));
    }
  } catch (error) {
    setState($('#releaseResult'), error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

async function submitRelease(event) {
  event.preventDefault();
  const formElement = event.currentTarget;
  const button = $('#submitRelease');
  const file = $('#releasePackage').files?.[0];
  if (!file) { setState($('#releaseResult'), 'Vui lòng chọn tệp cài đặt.', 'error'); return; }
  button.disabled = true;
  $('#refreshPackageScan').hidden = true;
  setState($('#releaseResult'), 'Đang tải trực tiếp lên kho cloud…');
  try {
    const scan = await uploadPackage(file);
    if (scan.status !== 'clean') {
      setState($('#releaseResult'), 'Gói chưa đủ điều kiện gửi duyệt. Trạng thái quét: ' + scan.status +
        '\nKhông thể xuất bản khi chưa có kết quả CLEAN từ VirusTotal và SHA-256 hợp lệ. Có thể kiểm tra lại sau.');
      $('#refreshPackageScan').hidden = false;
      return;
    }
    await submitReleaseRecord(formElement);
  } catch (error) {
    setState($('#releaseResult'), error.message, 'error');
    if (state.packageToken) $('#refreshPackageScan').hidden = false;
  } finally { button.disabled = false; }
}
function bindEvents() {
  $('#developerProfileForm')?.addEventListener('submit', submitProfile);
  $('#releaseForm')?.addEventListener('submit', submitRelease);
  $('#refreshPackageScan')?.addEventListener('click', refreshAndSubmitScannedPackage);
  $('#developerReviews')?.addEventListener('submit', (event) => {
    if (event.target.matches('.dev-review-reply')) submitDeveloperReply(event);
  });
  $('#developerApps')?.addEventListener('click', (event) => {
    const versionsButton = event.target.closest('[data-versions]');
    const analyticsButton = event.target.closest('[data-analytics]');
    const rollbackButton = event.target.closest('[data-rollback-version]');
    if (versionsButton) loadVersions(Number(versionsButton.dataset.versions));
    if (analyticsButton) loadAnalytics(Number(analyticsButton.dataset.analytics));
    if (rollbackButton) rollbackVersion(Number(rollbackButton.dataset.rollbackProduct), Number(rollbackButton.dataset.rollbackVersion))
      .catch((error) => notify(error.message));
  });
}

document.addEventListener('DOMContentLoaded', async () => {
  bindEvents();
  try { await loadWorkspace(); }
  catch (error) {
    $('#developerIdentity').textContent = 'Không thể tải hồ sơ';
    setState($('#profileStatus'), error.message, 'error');
  }
});
})();
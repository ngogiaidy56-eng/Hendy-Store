(() => {
'use strict';
const slug = document.querySelector('#storeEcosystem')?.dataset.slug || '';
const apiUrl = new URL('../api.php', document.baseURI);
const $ = (selector, root = document) => root.querySelector(selector);
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const state = { csrf:'', user:null, platform:'web' };
function detectPlatform() {
  const ua = navigator.userAgent || '';
  const platform = navigator.userAgentData?.platform || navigator.platform || '';
  if (/Android/i.test(ua)) return 'android';
  if (/iPhone|iPad|iPod/i.test(ua) || (/Mac/i.test(platform) && navigator.maxTouchPoints > 1)) return 'ios';
  if (/Win/i.test(platform) || /Windows/i.test(ua)) return 'windows';
  if (/Mac/i.test(platform) || /Macintosh/i.test(ua)) return 'macos';
  if (/Linux/i.test(platform) || /Linux/i.test(ua)) return 'linux';
  return 'web';
}
function platformLabel(value) {
  return ({windows:'Windows',macos:'macOS',android:'Android',ios:'iOS',linux:'Linux',web:'Web',universal:'Mọi nền tảng'})[value] || value;
}
function formatBytes(value) {
  const bytes = Number(value || 0);
  if (!bytes) return 'Chưa cung cấp';
  const units = ['B','KB','MB','GB'];
  const index = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024)));
  return (bytes / Math.pow(1024,index)).toFixed(index ? 1 : 0) + ' ' + units[index];
}
async function request(action, method='GET', body=null) {
  const url = new URL(apiUrl);
  url.searchParams.set('action', action);
  if (method === 'GET' && slug) url.searchParams.set('slug', slug);
  const headers = {Accept:'application/json'};
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
  }
  try {
    if (window.HendyNeon?.getToken) {
      const token = await window.HendyNeon.getToken();
      if (token) headers.Authorization = 'Bearer ' + token;
    }
  } catch (_) {}
  const response = await fetch(url, {method, credentials:'same-origin', headers, body:body ? JSON.stringify(body) : undefined});
  let json;
  try { json = await response.json(); } catch (_) { throw new Error('Máy chủ trả về dữ liệu không hợp lệ.'); }
  if (!response.ok || !json.ok) throw new Error(json.error || 'Yêu cầu thất bại.');
  return json;
}
function chooseVersion(versions, platform) {
  const matches = versions.filter(item => item.platform === platform);
  const universal = versions.filter(item => item.platform === 'universal');
  return matches.find(item => Number(item.is_current) === 1) || matches[0]
    || universal.find(item => Number(item.is_current) === 1) || universal[0] || null;
}
function renderGallery(shots) {
  const host = $('#storeScreenshots');
  if (!host) return;
  if (!shots.length) { host.innerHTML = '<p class="store-muted">Nhà phát triển chưa cung cấp ảnh chụp màn hình.</p>'; return; }
  host.innerHTML = '<div class="store-gallery">' + shots.map((shot, index) => {
    const source = String(shot.image_url || '');
    const src = source.startsWith('media:') ? '../api.php?action=media&file=' + encodeURIComponent(source.slice(6)) : source;
    return '<figure><img src="' + escapeHtml(src) + '" alt="' + escapeHtml(shot.caption || ('Ảnh chụp màn hình ' + (index + 1))) + '" loading="lazy"><figcaption>' + escapeHtml(shot.caption || '') + '</figcaption></figure>';
  }).join('') + '</div>';
}
function renderVersions(versions) {
  const host = $('#storeChangelog');
  if (!host) return;
  if (!versions.length) { host.innerHTML = '<p class="store-muted">Chưa có nhật ký thay đổi.</p>'; return; }
  host.innerHTML = '<div class="store-version-list">' + versions.map(item =>
    '<article class="store-version"><div class="store-version-head"><strong>Phiên bản ' + escapeHtml(item.version) + '</strong><span>' +
    escapeHtml(platformLabel(item.platform)) + ' · ' + formatBytes(item.package_size_bytes) + '</span></div><div class="store-muted">Yêu cầu tối thiểu: ' +
    escapeHtml(item.min_os || 'Chưa cung cấp') + '</div><p>' + escapeHtml(item.release_notes || 'Không có ghi chú phát hành.') +
    '</p><small>' + escapeHtml(item.created_at || '') + '</small></article>'
  ).join('') + '</div>';
}
function renderIntegrity(integrity) {
  const host = $('#storeIntegrity');
  if (!host) return;
  if (!integrity?.verified || !/^[a-f0-9]{64}$/.test(String(integrity.sha256 || ''))) {
    host.textContent = 'Chưa có dữ liệu checksum công khai đã được duyệt.';
    return;
  }
  host.textContent = 'SHA-256: ' + integrity.sha256 + '\nDung lượng đã xác minh: ' + formatBytes(integrity.size) + '\nGói đã vượt qua kiểm tra tự động và kiểm duyệt thủ công.';
}
function renderSpecs(specs) {
  const host = $('#storeRequirements');
  const privacy = $('#storePrivacy');
  const video = $('#storeVideo');
  if (host) host.textContent = specs.minimum_requirements || 'Nhà phát triển chưa cung cấp yêu cầu cấu hình tối thiểu.';
  if (privacy) privacy.textContent = specs.privacy_details || 'Nhà phát triển chưa cung cấp thông tin quyền riêng tư riêng cho ứng dụng này. Xem thêm chính sách quyền riêng tư của cửa hàng.';
  if (video) video.innerHTML = specs.video_url ? '<a href="' + escapeHtml(specs.video_url) + '" target="_blank" rel="noopener noreferrer">▶ Xem video giới thiệu</a>' : '<span class="store-muted">Chưa có video giới thiệu.</span>';
}
function renderDownload(versions, product) {
  const select = $('#storePlatform');
  const button = $('#storeDownload');
  const message = $('#storeBuildMessage');
  if (!select || !button) return;
  let selectedBuild = null;
  const available = [...new Set(versions.map(item => item.platform))];
  const options = [...new Set([state.platform, ...available, 'universal'])];
  select.innerHTML = options.map(os => '<option value="' + os + '">' + platformLabel(os) + (os === state.platform ? ' · thiết bị hiện tại' : '') + '</option>').join('');
  select.value = state.platform;
  const selectBuild = () => {
    const platform = select.value;
    const chosen = chooseVersion(versions, platform);
    if (platform === 'ios') {
      selectedBuild = null;
      button.href = '#';
      button.setAttribute('aria-disabled','true');
      button.classList.add('store-disabled');
      message.textContent = 'iOS: ứng dụng cần phân phối qua App Store, TestFlight hoặc MDM. Hendy Store không sideload IPA trực tiếp.';
      button.textContent = 'Xem hướng dẫn iOS';
      return;
    }
    if (!chosen) {
      selectedBuild = null;
      button.href = '#';
      button.setAttribute('aria-disabled','true');
      button.classList.add('store-disabled');
      message.textContent = 'Chưa có gói cài đặt dành cho ' + platformLabel(platform) + '.';
      return;
    }
    selectedBuild = chosen;
    button.classList.remove('store-disabled');
    button.removeAttribute('aria-disabled');
    button.href = '#';
    const minimum = chosen.min_os ? ' · Yêu cầu ' + chosen.min_os : '';
    message.textContent = platformLabel(platform) + ' · Phiên bản ' + chosen.version + ' · ' +
      formatBytes(chosen.package_size_bytes) + minimum;
    button.textContent = 'Tải xuống miễn phí ↓';
  };
  select.addEventListener('change', selectBuild);
  selectBuild();
  button.addEventListener('click', async event => {
    event.preventDefault();
    if (!selectedBuild) return;
    const originalLabel = button.textContent;
    button.textContent = 'Đang chuẩn bị tải…';
    button.classList.add('store-disabled');
    message.textContent = 'Đang xác thực gói tải an toàn…';
    try {
      const result = await request('store_download_prepare', 'POST', {slug:slug,version_id:Number(selectedBuild.id||0),platform:select.value});
      window.location.assign(result.data.url);
    } catch (error) {
      message.textContent = error.message;
      button.textContent = originalLabel;
      button.classList.remove('store-disabled');
    }
  });
}
function renderRelated(products) {
  const host = $('#storeRelated');
  if (!host) return;
  if (!products.length) {
    host.innerHTML = '<p class="store-muted">Chưa có ứng dụng cùng danh mục để gợi ý.</p>';
    return;
  }
  host.innerHTML = products.map(item => {
    const icon = String(item.icon || '');
    const image = icon.startsWith('media:')
      ? '../api.php?action=media&file=' + encodeURIComponent(icon.slice(6))
      : (/^https:\/\//i.test(icon) ? icon : '../' + icon.replace(/^\/+/, ''));
    const href = '../apps/' + encodeURIComponent(item.slug);
    const price = 'Miễn phí';
    return '<a class="app-card store-related-card" href="' + escapeHtml(href) + '"><div class="app-card-top"><img class="app-icon" src="' +
      escapeHtml(image) + '" alt="" loading="lazy"><div><h3>' + escapeHtml(item.title) + '</h3><div class="app-developer">' +
      escapeHtml(item.developer) + '</div></div></div><p class="app-description">' + escapeHtml(item.shortDescription) +
      '</p><div class="app-meta"><span>★ ' + Number(item.rating || 0).toFixed(1) + ' · ' +
      Number(item.downloads || 0).toLocaleString('vi-VN') + ' lượt tải</span><span class="app-price">' + price + '</span></div></a>';
  }).join('');
}
function renderReviewStats(stats) {
  const host = $('#storeReviewStats');
  if (!host) return;
  host.innerHTML = '<strong>' + Number(stats.average_rating || 0).toFixed(1) + ' / 5 ★</strong><span>' +
    Number(stats.review_count || 0) + ' đánh giá đã duyệt</span>';
}
function renderReviews(reviews) {
  const host = $('#storeReviews');
  if (!host) return;
  if (!reviews.length) { host.innerHTML = '<p class="store-muted">Chưa có đánh giá công khai.</p>'; return; }
  host.innerHTML = reviews.map(item => '<article class="store-review"><div class="store-review-head"><strong>' +
    escapeHtml(item.author_name) + '</strong><span>' + '★'.repeat(Number(item.rating)) + '☆'.repeat(5-Number(item.rating)) +
    '</span></div><p>' + escapeHtml(item.review_text) + '</p><small>' + escapeHtml(item.created_at || '') + '</small>' +
    (item.developer_reply ? '<blockquote><b>Phản hồi từ nhà phát triển</b><p>' + escapeHtml(item.developer_reply) + '</p></blockquote>' : '') +
    '</article>').join('');
}
async function init() {
  const root = $('#storeEcosystem');
  if (!root) return;
  state.platform = detectPlatform();
  try {
    const results = await Promise.all([
      request('me').catch(() => ({data:{user:null,csrfToken:''}})),
      request('store_product_extras'),
      request('store_reviews'),
      request('product')
    ]);
    state.user = results[0].data?.user || null;
    state.csrf = results[0].data?.csrfToken || '';
    const extras = results[1].data;
    renderGallery(extras.screenshots || []);
    renderVersions(extras.versions || []);
    renderSpecs(extras.specs || {});
    renderIntegrity(extras.integrity || null);
    renderDownload(extras.versions || [], results[3].data || {});
    renderReviewStats(extras.reviewStats || {});
    renderReviews(results[2].data || []);
    renderRelated(results[3].related || []);
    const form = $('#storeReviewForm');
    if (form) {
      if (!state.user) {
        form.innerHTML = '<p class="store-muted">Vui lòng <a href="../">đăng nhập</a> để gửi đánh giá. Nhận xét sẽ được duyệt trước khi hiển thị công khai.</p>';
      } else {
        form.addEventListener('submit', async event => {
          event.preventDefault();
          const data = new FormData(form);
          const message = $('#storeReviewMessage');
          message.textContent = 'Đang gửi đánh giá…';
          try {
            await request('store_review','POST',{slug:slug,rating:Number(data.get('rating')),review_text:String(data.get('review_text') || '')});
            form.reset();
            message.textContent = 'Đã gửi đánh giá. Nội dung sẽ xuất hiện sau khi được duyệt.';
          } catch (error) { message.textContent = error.message; }
        });
      }
    }
  } catch (error) {
    root.innerHTML = '<p class="store-error">' + escapeHtml(error.message) + '</p>';
  }
}
document.addEventListener('DOMContentLoaded', init);
})();
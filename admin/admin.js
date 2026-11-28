(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  let csrf = '';
  let products = [];
  let editingId = null;
  const apiUrl = new URL('/api.php', window.location.origin);

  async function call(action, method = 'GET', body = null, retryCsrf = true) {
    const url = new URL(apiUrl);
    url.searchParams.set('action', action);
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      if (csrf) headers['X-CSRF-Token'] = csrf;
    }
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body ? JSON.stringify(body) : undefined
    });
    let result;
    try { result = await response.json(); } catch { throw new Error('Máy chủ trả về dữ liệu không hợp lệ.'); }
    if (response.status === 419 && method !== 'GET' && retryCsrf) {
      const refreshed = await call('bootstrap', 'GET', null, false);
      csrf = refreshed.data?.csrfToken || '';
      return call(action, method, body, false);
    }
    if (!response.ok || !result.ok) throw new Error(result.error || 'Yêu cầu thất bại.');
    if (result.data?.csrfToken) csrf = result.data.csrfToken;
    return result;
  }

  async function upload(kind, file, retryCsrf = true) {
    const url = new URL(apiUrl);
    url.searchParams.set('action', 'admin_upload');
    const data = new FormData();
    data.append('kind', kind);
    data.append('file', file);
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
      body: data
    });
    let result;
    try { result = await response.json(); } catch { throw new Error('Máy chủ trả về dữ liệu không hợp lệ.'); }
    if (response.status === 419 && retryCsrf) {
      const refreshed = await call('bootstrap', 'GET', null, false);
      csrf = refreshed.data?.csrfToken || '';
      return upload(kind, file, false);
    }
    if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể tải tệp lên.');
    return result.data;
  }

  function toast(message) {
    const el = $('#toast');
    el.textContent = message;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 3000);
  }

  function showDashboard(user) {
    $('#adminIdentity').textContent = `${user.name} · ADMIN`;
    $('#adminLogin').classList.add('hidden');
    $('#adminDashboard').classList.remove('hidden');
    loadProducts();
  }

  async function boot() {
    try {
      const result = await call('bootstrap');
      csrf = result.data.csrfToken || '';
      if (result.data.user?.role === 'admin') showDashboard(result.data.user);
    } catch (error) {
      $('#loginStatus').textContent = error.message;
    }
  }

  async function loadProducts() {
    try {
      const result = await call('admin_products');
      products = result.data || [];
      $('#productRows').innerHTML = products.map((product) => `
        <tr>
          <td><strong>${escapeHtml(product.title)}</strong><br><span>${escapeHtml(product.developer)}</span></td>
          <td>${escapeHtml(product.category)}</td>
          <td>${Number(product.downloads).toLocaleString('vi-VN')}</td>
          <td>${product.status === 'draft' ? 'Bản nháp' : 'Công khai'}</td>
          <td><button type="button" data-edit="${product.id}">Sửa</button><button type="button" data-delete="${product.id}">Xóa</button></td>
        </tr>`).join('') || '<tr><td colspan="5">Chưa có ứng dụng.</td></tr>';
    } catch (error) {
      $('#productRows').innerHTML = `<tr><td colspan="5">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  }

  function formValues() {
    const form = $('#productForm');
    const data = Object.fromEntries(new FormData(form).entries());
    try { data.screenshots = JSON.parse(data.screenshotsJson || '[]'); } catch { data.screenshots = []; }
    delete data.screenshotsJson;
    data.id = editingId;
    data.featured = form.elements.featured.checked;
    return data;
  }

  function resetForm() {
    editingId = null;
    $('#productForm').reset();
    $('#productForm').elements.id.value = '';
    $('#editorTitle').textContent = 'Thêm ứng dụng mới';
    $('#editorStatus').textContent = '';
  }

  async function bindUpload(inputSelector, kind, targetName) {
    const input = $(inputSelector);
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      input.disabled = true;
      $('#editorStatus').textContent = 'Đang tải tệp lên vùng lưu trữ riêng…';
      try {
        const result = await upload(kind, file);
        $('#productForm').elements.namedItem(targetName).value = result.path;
        const detail = result.sha256 ? ` SHA-256: ${result.sha256}` : '';
        $('#editorStatus').textContent = `Đã tải tệp lên thành công.${detail}`;
        toast('Đã tải tệp lên. Hãy lưu ứng dụng để cập nhật.');
      } catch (error) {
        $('#editorStatus').textContent = error.message;
      } finally {
        input.disabled = false;
        input.value = '';
      }
    });
  }

  $('#adminLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      const result = await call('login', 'POST', data);
      csrf = result.data.csrfToken || csrf;
      if (result.data.user.role !== 'admin') throw new Error('Tài khoản này không có quyền quản trị.');
      showDashboard(result.data.user);
    } catch (error) {
      $('#loginStatus').textContent = error.message;
    }
  });

  $('#generateDescription').addEventListener('click', async () => {
    const form = $('#productForm');
    const button = $('#generateDescription');
    button.disabled = true;
    $('#editorStatus').textContent = 'Neon AI Gateway đang soạn mô tả…';
    try {
      const result = await call('ai_product_copy', 'POST', {
        title: form.elements.title.value,
        developer: form.elements.developer.value,
        category: form.elements.category.value,
        shortDescription: form.elements.shortDescription.value
      });
      form.elements.description.value = result.data.description;
      $('#editorStatus').textContent = 'Đã tạo mô tả. Hãy đọc và kiểm chứng nội dung trước khi lưu.';
    } catch (error) {
      $('#editorStatus').textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  $('#productForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = formValues();
    try {
      await call('admin_products', editingId ? 'PUT' : 'POST', data);
      $('#editorStatus').textContent = 'Đã lưu ứng dụng.';
      toast('Đã lưu thay đổi.');
      resetForm();
      loadProducts();
    } catch (error) {
      $('#editorStatus').textContent = error.message;
    }
  });

  $('#productRows').addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit]');
    const del = event.target.closest('[data-delete]');
    if (edit) {
      const product = products.find((item) => item.id === Number(edit.dataset.edit));
      if (!product) return;
      editingId = product.id;
      const form = $('#productForm');
      const values = { ...product, downloadUrl: product.downloadUrl || '' };
      delete values.screenshots;
      Object.entries(values).forEach(([key, value]) => {
        const field = form.elements.namedItem(key);
        if (field && typeof field.value !== 'undefined') field.value = value ?? '';
      });
      form.elements.namedItem('screenshotsJson').value = JSON.stringify(Array.isArray(product.screenshots) ? product.screenshots : []);
      form.elements.featured.checked = Boolean(product.featured);
      $('#editorTitle').textContent = `Chỉnh sửa: ${product.title}`;
      $('#editorStatus').textContent = 'Tệp đã tải lên được giữ trong vùng lưu trữ riêng.';
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    }
    if (del && window.confirm('Xóa ứng dụng này khỏi cửa hàng?')) {
      try {
        await call('admin_products', 'DELETE', { id: Number(del.dataset.delete) });
        toast('Đã xóa ứng dụng.');
        loadProducts();
      } catch (error) {
        toast(error.message);
      }
    }
  });

  $('#newProduct').addEventListener('click', resetForm);
  $('#resetForm').addEventListener('click', resetForm);
  $('#adminLogout').addEventListener('click', async () => {
    try { await call('logout', 'POST', {}); window.location.reload(); }
    catch (error) { toast(error.message); }
  });

  bindUpload('#iconFile', 'media', 'icon');
  bindUpload('#coverFile', 'media', 'cover');
  bindUpload('#packageFile', 'package', 'downloadUrl');
  document.addEventListener('DOMContentLoaded', boot);
})();

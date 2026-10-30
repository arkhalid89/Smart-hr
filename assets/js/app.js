/* =====================================================================
   Smart HR System - global front-end behaviour
   ===================================================================== */
(function ($) {
  'use strict';

  const BASE_URL = document.querySelector('meta[name="base-url"]').content;
  const CSRF     = document.querySelector('meta[name="csrf-token"]').content;
  window.HR = window.HR || {};
  HR.url = (p) => BASE_URL + '/' + String(p).replace(/^\/+/, '');
  HR.csrf = CSRF;

  /* Every AJAX request carries the CSRF token + XHR header */
  $.ajaxSetup({
    headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
  });

  /* ---------------- Toast helper ---------------- */
  HR.toast = function (message, type = 'success', delay = 3500) {
    let wrap = document.getElementById('toastWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'toastWrap';
      wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
      wrap.style.zIndex = 1080;
      wrap.style.marginTop = '60px';
      document.body.appendChild(wrap);
    }
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + type + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + $('<div>').text(message).html() +
      '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: delay || 3500 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  };

  /* ---------------- Confirm helper (returns Promise<boolean>) ---------------- */
  HR.confirm = function (text) {
    return new Promise((resolve) => {
      const modalEl = document.getElementById('confirmModal');
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      document.getElementById('confirmModalText').textContent = text || 'Are you sure?';
      const ok = document.getElementById('confirmModalOk');
      const onOk = () => { cleanup(); resolve(true); modal.hide(); };
      const onHide = () => { cleanup(); resolve(false); };
      const cleanup = () => { ok.removeEventListener('click', onOk); modalEl.removeEventListener('hidden.bs.modal', onHide); };
      ok.addEventListener('click', onOk);
      modalEl.addEventListener('hidden.bs.modal', onHide);
      modal.show();
    });
  };

  /* ---------------- AJAX POST helper ---------------- */
  HR.post = function (url, data) {
    return $.ajax({ url: HR.url(url), method: 'POST', data: data, dataType: 'json' })
      .fail(function (xhr) {
        let msg = 'Request failed. Please try again.';
        try { msg = xhr.responseJSON.message || msg; } catch (e) {}
        HR.toast(msg, 'danger');
      });
  };

  /* ---------------- Init widgets ---------------- */
  /* ---------------- District -> Tehsil -> Department ----------------
     Every department is chosen through its district and tehsil. The lists
     come from window.HR_GEO (only what the user reaches); a tehsil value of
     0 stands for "district headquarters" — departments with no tehsil.
     A select with [data-geo-locations] in the same form is narrowed to the
     offices of the chosen department (options carry data-department). */
  HR.initGeo = function (scope) {
    const G = window.HR_GEO;
    if (!G) return;
    const $scope = scope ? $(scope) : $(document);

    $scope.find('[data-geo-picker]').each(function () {
      const box = this;
      if (box.dataset.geoReady) return;
      box.dataset.geoReady = '1';
      const filter = box.dataset.mode === 'filter';
      const $d = $(box).find('[data-geo="district"]');
      const $t = $(box).find('[data-geo="tehsil"]');
      const $p = $(box).find('[data-geo="department"]');
      const $form = $(box).closest('form');
      const $locs = $form.find('select[data-geo-locations]');
      const locOptions = $locs.length ? $locs.find('option').clone() : null;

      function fill($sel, items, placeholder, value) {
        $sel.empty();
        if (placeholder !== null) $sel.append(new Option(placeholder, ''));
        items.forEach(it => $sel.append(new Option(it.label, String(it.value))));
        $sel.val(value !== undefined && value !== null && $sel.find('option[value="' + value + '"]').length ? String(value) : '');
      }

      /* 41 districts read far better grouped by division */
      function districts(sel) {
        $d.empty();
        const single = G.districts.length === 1;
        if (filter) $d.append(new Option('All districts', ''));
        else if (!single) $d.append(new Option('Choose district…', ''));
        let group = null, current = null;
        G.districts.forEach(x => {
          if (x.division && x.division !== current) {
            current = x.division;
            group = $('<optgroup>').attr('label', x.division + ' Division').appendTo($d);
          }
          (x.division ? group : $d).append(new Option(x.name, String(x.id)));
        });
        if (sel && $d.find('option[value="' + sel + '"]').length) $d.val(String(sel));
        else if (!filter && single) $d.val(String(G.districts[0].id));
        else $d.val('');
      }

      function tehsils(sel) {
        const did = parseInt($d.val(), 10) || 0;
        const hasHq = G.departments.some(x => x.district_id === did && x.tehsil_id === null);
        const items = [];
        if (did && hasHq) items.push({ value: 0, label: '— District headquarters —' });
        G.tehsils.filter(t => t.district_id === did).forEach(t => items.push({ value: t.id, label: t.name }));
        fill($t, items, filter ? (did ? 'All tehsils' : 'All tehsils') : (did ? 'Choose tehsil…' : 'Choose a district first'), sel);
        $t.prop('disabled', !did);
      }

      function departments(sel) {
        const did = parseInt($d.val(), 10) || 0;
        const tv = $t.val();
        const tid = tv === '' ? null : parseInt(tv, 10);
        let list = G.departments.filter(x => !did || x.district_id === did);
        if (tid === 0) list = list.filter(x => x.tehsil_id === null);
        else if (tid) list = list.filter(x => x.tehsil_id === tid);
        else if (!filter) list = [];        // a form needs the tehsil first
        const tag = { district: ' · district level', tehsil: ' · tehsil level', department: '' };
        const items = list.map(x => ({ value: x.id, label: x.name + (tag[x.level] || '') }));
        const ph = filter ? 'All departments' : (tv === '' ? 'Choose a tehsil first' : (items.length ? 'Choose department…' : 'No department here'));
        fill($p, items, ph, sel);
        $p.prop('disabled', !filter && tv === '');
        narrowOffices();
      }

      function narrowOffices() {
        if (!$locs.length) return;
        const dep = $p.val();
        const keep = $locs.val();
        $locs.empty();
        locOptions.each(function () {
          const od = this.getAttribute('data-department');
          if (!this.value || !od || od === dep) $locs.append($(this).clone());
        });
        $locs.val($locs.find('option[value="' + keep + '"]').length ? keep : '').trigger('change.select2');
      }

      districts(box.dataset.selectedDistrict);
      tehsils(box.dataset.selectedTehsil);
      departments(box.dataset.selectedDepartment);

      $d.on('change', () => { tehsils(''); departments(''); });
      $t.on('change', () => departments(''));
      $p.on('change', narrowOffices);
    });
  };

  /* Level + district + tehsil of a department (Master Data):
     a district-level office has no tehsil; a tehsil-level office must have
     one; the tehsil list follows the district. */
  HR.initPlace = function (scope) {
    const $scope = scope ? $(scope) : $(document);
    $scope.find('.place-field').each(function () {
      if (this.dataset.placeReady) return;
      this.dataset.placeReady = '1';
      const lv = this.querySelector('[data-place="level"]');
      const ds = this.querySelector('[data-place="district"]');
      const th = this.querySelector('[data-place="tehsil"]');
      const wrap = this.querySelector('[data-place-tehsil-wrap]');
      const req = this.querySelector('[data-place-tehsil-req]');
      const all = Array.prototype.slice.call(th.options);
      function sync() {
        let keep = th.value;
        th.innerHTML = '';
        all.forEach(o => { if (!o.value || o.getAttribute('data-district') === ds.value) th.appendChild(o); });
        if (!Array.prototype.some.call(th.options, o => o.value === keep)) keep = '';
        const district = lv.value === 'district';
        if (district) keep = '';
        wrap.style.display = district ? 'none' : '';
        th.options[0].text = lv.value === 'tehsil' ? 'Choose tehsil…' : '— District headquarters —';
        req.style.display = lv.value === 'tehsil' ? '' : 'none';
        th.value = keep;
      }
      lv.addEventListener('change', sync);
      ds.addEventListener('change', sync);
      sync();
    });
  };

  HR.initWidgets = function (scope) {
    const $scope = scope ? $(scope) : $(document);

    // Searchable selects
    $scope.find('select.select2, select[data-select2]').each(function () {
      const $s = $(this);
      if ($s.hasClass('select2-hidden-accessible')) return;
      const inModal = $s.closest('.modal');
      $s.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: $s.data('placeholder') || 'Select…',
        allowClear: !!$s.data('allow-clear'),
        dropdownParent: inModal.length ? inModal : $(document.body)
      });
    });

    // DataTables
    $scope.find('table.datatable').each(function () {
      if ($.fn.dataTable.isDataTable(this)) return;
      const $t = $(this);
      $t.DataTable({
        responsive: true,
        pageLength: parseInt($t.data('page-length') || 25, 10),
        order: $t.data('order') ? JSON.parse($t.data('order')) : [],
        language: { search: '', searchPlaceholder: 'Filter results…', lengthMenu: '_MENU_ rows' },
        columnDefs: [{ targets: 'no-sort', orderable: false }]
      });
    });
  };

  /* ---------------- Sidebar collapse (desktop) ---------------- */
  function initSidebar() {
    const btn = document.getElementById('sidebarToggle');
    if (!btn) return;
    try { if (localStorage.getItem('hr_sidebar') === 'collapsed') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
    btn.addEventListener('click', function () {
      document.body.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem('hr_sidebar', document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); } catch (e) {}
    });
  }

  /* ---------------- Global live employee search ---------------- */
  function initGlobalSearch() {
    const input = document.getElementById('globalSearch');
    const box = document.getElementById('globalSearchResults');
    if (!input || !box) return;
    let timer = null, active = -1, lastQ = '';

    const render = (rows) => {
      active = -1;
      if (!rows.length) { box.innerHTML = '<div class="empty">No employee found.</div>'; box.hidden = false; return; }
      box.innerHTML = rows.map(r =>
        '<a href="' + (r.url || HR.url('employees/view.php?id=' + r.id)) + '">' +
        '<img src="' + r.photo + '" alt="">' +
        '<span><span class="d-block fw-semibold">' + $('<i>').text(r.name).html() + '</span>' +
        '<span class="d-block x-small text-muted">' + $('<i>').text(r.employee_code + ' • ' + r.cnic + ' • ' + r.designation + ' • ' + r.department).html() + '</span></span>' +
        '<span class="ms-auto badge text-bg-' + r.badge + '">' + $('<i>').text(r.status).html() + '</span></a>'
      ).join('');
      box.hidden = false;
    };

    input.addEventListener('input', function () {
      const q = this.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { box.hidden = true; return; }
      timer = setTimeout(() => {
        lastQ = q;
        $.getJSON(HR.url('employees/ajax/search.php'), { q: q }, function (res) {
          if (q !== lastQ) return;
          render(res.results || []);
        });
      }, 250);
    });
    input.addEventListener('keydown', function (e) {
      const items = box.querySelectorAll('a');
      if (box.hidden || !items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); }
      else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) items[active].click(); else items[0].click(); return; }
      else if (e.key === 'Escape') { box.hidden = true; return; }
      items.forEach((a, i) => a.classList.toggle('active', i === active));
    });
    document.addEventListener('click', (e) => { if (!box.contains(e.target) && e.target !== input) box.hidden = true; });
    input.addEventListener('focus', () => { if (box.innerHTML && input.value.trim().length >= 2) box.hidden = false; });
  }

  /* ---------------- data-confirm links / forms ---------------- */
  function initConfirms() {
    $(document).on('click', 'a[data-confirm], button[data-confirm]', async function (e) {
      const el = this;
      if (el.dataset.confirmed === '1') return;
      e.preventDefault();
      const ok = await HR.confirm(el.dataset.confirm);
      if (!ok) return;
      el.dataset.confirmed = '1';
      if (el.tagName === 'A') { window.location.href = el.href; }
      else if (el.form) { el.form.requestSubmit ? el.form.requestSubmit(el) : el.form.submit(); }
      else { el.click(); }
      setTimeout(() => { el.dataset.confirmed = ''; }, 500);
    });
  }

  /* ---------------- Notifications: mark all read ---------------- */
  function initNotifications() {
    $(document).on('click', '#markAllRead', function (e) {
      e.preventDefault();
      HR.post('dashboard/ajax_notifications.php', { action: 'read_all' }).done(() => location.reload());
    });
  }

  /* ---------------- CNIC input mask (#####-#######-#) ---------------- */
  function initCnicMask() {
    $(document).on('input', 'input[data-cnic]', function () {
      let v = this.value.replace(/\D/g, '').slice(0, 13);
      if (v.length > 12) v = v.slice(0, 5) + '-' + v.slice(5, 12) + '-' + v.slice(12);
      else if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
      this.value = v;
    });
  }

  /* ---------------- DOB <-> Age auto fill ---------------- */
  function initDobAge() {
    $(document).on('change', 'input[data-dob]', function () {
      const target = document.querySelector(this.dataset.dob);
      if (!target || !this.value) return;
      const d = new Date(this.value), n = new Date();
      let age = n.getFullYear() - d.getFullYear();
      const m = n.getMonth() - d.getMonth();
      if (m < 0 || (m === 0 && n.getDate() < d.getDate())) age--;
      target.value = age >= 0 ? age : '';
    });
    $(document).on('change', 'input[data-age]', function () {
      const target = document.querySelector(this.dataset.age);
      const age = parseInt(this.value, 10);
      if (!target || isNaN(age) || age < 0) return;
      if (target.value) return;           // do not overwrite an explicit DOB
      const n = new Date();
      target.value = (n.getFullYear() - age) + '-01-01';
      $(target).trigger('change');
    });
  }

  /* ---------------- Date range duration ---------------- */
  function initDuration() {
    $(document).on('change', 'input[data-from], input[data-to]', function () {
      const form = this.closest('form'); if (!form) return;
      const from = form.querySelector('input[data-from]'), to = form.querySelector('input[data-to]'), out = form.querySelector('[data-duration]');
      if (!from || !to || !out || !from.value || !to.value) return;
      const days = Math.round((new Date(to.value) - new Date(from.value)) / 86400000) + 1;
      out.value = days > 0 ? days : '';
    });
  }

  $(function () {
    initSidebar();
    initGlobalSearch();
    initConfirms();
    initNotifications();
    initCnicMask();
    initDobAge();
    initDuration();
    HR.initGeo();
    HR.initPlace();
    HR.initWidgets();
    // auto-dismiss flash alerts
    setTimeout(() => $('.alert.alert-dismissible.auto-hide').alert('close'), 6000);
  });
})(jQuery);

/* ---------------- Theme toggle (light / dark) ---------------- */
(function () {
  const btn = document.getElementById('themeToggle');
  const html = document.documentElement;
  const paint = () => { if (btn) btn.firstElementChild.className = html.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; };
  paint();
  if (btn) btn.addEventListener('click', function () {
    const t = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-bs-theme', t);
    try { localStorage.setItem('hr_theme', t); } catch (e) {}
    paint();
    document.dispatchEvent(new CustomEvent('hr:theme', { detail: t }));
  });
})();

/* ================================================================
   مِحكام — Custom Panel JavaScript v2.0
   ================================================================ */

(function () {
  'use strict';

  /* ── Sidebar Toggle ───────────────────────────────────────── */
  const sidebar  = document.getElementById('mkSidebar');
  const overlay  = document.getElementById('mkOverlay');
  const toggleBtn = document.getElementById('mkToggle');

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  function toggleSidebar() {
    if (sidebar && sidebar.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      // On desktop: just toggle a 'collapsed' class
      if (window.innerWidth > 992) {
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('mk-sb-collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
      } else {
        toggleSidebar();
      }
    });
  }

  if (overlay) {
    overlay.addEventListener('click', closeSidebar);
  }

  // Restore collapsed state on desktop
  if (window.innerWidth > 992 && localStorage.getItem('mk-sb-collapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }

  // Close sidebar on resize to desktop
  window.addEventListener('resize', function () {
    if (window.innerWidth > 992) {
      closeSidebar();
    }
  });

  /* ── User Dropdown ────────────────────────────────────────── */
  const userMenu = document.getElementById('mkUserMenu');
  const userDrop = document.getElementById('mkUserDrop');

  if (userMenu && userDrop) {
    userMenu.addEventListener('click', function (e) {
      e.stopPropagation();
      userDrop.classList.toggle('open');
    });

    document.addEventListener('click', function () {
      if (userDrop) userDrop.classList.remove('open');
    });

    userDrop.addEventListener('click', function (e) {
      e.stopPropagation();
    });
  }

  /* ── Auto-dismiss alerts ──────────────────────────────────── */
  document.querySelectorAll('.alert-dismissible.auto-dismiss').forEach(function (el) {
    setTimeout(function () {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
      if (bsAlert) bsAlert.close();
    }, 4000);
  });

  /* ── File drag-drop areas ─────────────────────────────────── */
  document.querySelectorAll('.mk-dropzone').forEach(function (zone) {
    const input = zone.querySelector('input[type="file"]');

    zone.addEventListener('click', function () {
      if (input) input.click();
    });

    zone.addEventListener('dragover', function (e) {
      e.preventDefault();
      zone.classList.add('dragover');
    });

    zone.addEventListener('dragleave', function () {
      zone.classList.remove('dragover');
    });

    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('dragover');
      if (input && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        updateDropzoneLabel(zone, e.dataTransfer.files);
      }
    });

    if (input) {
      input.addEventListener('change', function () {
        updateDropzoneLabel(zone, input.files);
      });
    }
  });

  function updateDropzoneLabel(zone, files) {
    const label = zone.querySelector('.mk-dropzone-label');
    if (!label) return;
    if (files.length === 1) {
      label.textContent = files[0].name;
      zone.classList.add('has-file');
    } else if (files.length > 1) {
      label.textContent = files.length + ' ملفات محددة';
      zone.classList.add('has-file');
    }
  }

  /* ── حقول رفع عدة ملفات دفعة واحدة (+/-) ──────────────────────
     أضِف الصنف "mk-multi" لأي <input type="file"> ليتحوّل تلقائياً
     إلى صفّ قابل للتكرار: خانة الملف الأولى تبقى كما هي (تحمل نفس
     الاسم والخاصية required إن وُجدت)، وزر «+» يضيف خانات ملف إضافية
     (بنفس الاسم لاحقةً []) يرافق كلاً منها زر «−» للحذف. تُرافق كل
     خانة ملف خانة نصية لاسم الملف (name[baseName]_label[]) تُعبَّأ
     تلقائياً من اسم الملف المختار ويمكن للمستخدم تعديلها — الاسم
     المُدخَل يُستخدَم بدل اسم الملف الأصلي عند الحفظ (مع الحفاظ على
     امتداده الحقيقي). القيمة المُرسَلة للملف تصبح مصفوفة (name[])
     بدل قيمة واحدة. ──────── */
  function mkMultiFileInit(input) {
    if (input.dataset.mkMultiDone) return;
    input.dataset.mkMultiDone = '1';
    const baseName = input.name.replace(/\[\]$/, '');
    input.name = baseName + '[]';

    const wrap = document.createElement('div');
    wrap.className = 'mk-multifile';
    input.parentNode.insertBefore(wrap, input);

    const rows = document.createElement('div');
    rows.className = 'mk-multifile-rows d-flex flex-column gap-2';
    wrap.appendChild(rows);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-secondary mt-2';
    addBtn.innerHTML = '<i class="fas fa-plus"></i> إضافة ملف آخر';
    wrap.appendChild(addBtn);

    function stripExt(name) {
      const i = name.lastIndexOf('.');
      return i > 0 ? name.slice(0, i) : name;
    }

    function makeRow(isFirst) {
      const row = document.createElement('div');
      row.className = 'mk-multifile-row d-flex gap-2 align-items-start';

      const stack = document.createElement('div');
      stack.className = 'flex-grow-1 d-flex flex-column gap-1';

      const fld = isFirst ? input : input.cloneNode(false);
      fld.value = '';
      fld.className = input.className;
      fld.name = baseName + '[]';
      if (!isFirst) fld.removeAttribute('required'); // الإلزام يكفي على الخانة الأولى

      const nameFld = document.createElement('input');
      nameFld.type = 'text';
      nameFld.name = baseName + '_label[]';
      nameFld.className = input.className.replace(/\bmk-multi\b/, '').trim();
      nameFld.placeholder = 'اسم الملف';
      nameFld.dataset.autofilled = '1';

      fld.addEventListener('change', function () {
        if (fld.files && fld.files[0] && (nameFld.dataset.autofilled === '1' || nameFld.value === '')) {
          nameFld.value = stripExt(fld.files[0].name);
          nameFld.dataset.autofilled = '1';
        }
      });
      nameFld.addEventListener('input', function () { nameFld.dataset.autofilled = '0'; });

      stack.appendChild(fld);
      stack.appendChild(nameFld);
      row.appendChild(stack);
      if (!isFirst) {
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
        rm.title = 'إزالة';
        rm.innerHTML = '<i class="fas fa-minus"></i>';
        rm.addEventListener('click', function () { row.remove(); });
        row.appendChild(rm);
      }
      return row;
    }

    rows.appendChild(makeRow(true));
    addBtn.addEventListener('click', function () { rows.appendChild(makeRow(false)); });
  }

  function mkMultiFileScan(root) {
    (root || document).querySelectorAll('input[type="file"].mk-multi').forEach(mkMultiFileInit);
  }
  mkMultiFileScan();
  document.addEventListener('shown.bs.modal', function (e) { mkMultiFileScan(e.target); });

  /* ── Confirm delete ───────────────────────────────────────── */
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      const msg = el.getAttribute('data-confirm') || 'هل أنت متأكد من الحذف؟';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  /* ── Table row click → link ───────────────────────────────── */
  document.querySelectorAll('tr[data-href]').forEach(function (row) {
    row.style.cursor = 'pointer';
    row.addEventListener('click', function (e) {
      if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' ||
          e.target.closest('a') || e.target.closest('button')) return;
      window.location.href = row.dataset.href;
    });
  });

  /* ── Number formatting in inputs ─────────────────────────── */
  document.querySelectorAll('.mk-currency').forEach(function (el) {
    el.addEventListener('blur', function () {
      const val = parseFloat(el.value);
      if (!isNaN(val)) el.value = val.toFixed(2);
    });
  });

  /* ── Invoice item rows (dynamic) ─────────────────────────── */
  const addRowBtn = document.getElementById('addInvRow');
  const invBody   = document.getElementById('invItemsBody');

  if (addRowBtn && invBody) {
    addRowBtn.addEventListener('click', function () {
      const idx = invBody.querySelectorAll('tr').length;
      const row = document.createElement('tr');
      row.innerHTML = `
        <td><input type="text" name="items[${idx}][description]" class="form-control form-control-sm" placeholder="وصف البند" required></td>
        <td style="width:90px"><input type="number" name="items[${idx}][qty]" class="form-control form-control-sm inv-qty" value="1" min="1" step="1"></td>
        <td style="width:110px"><input type="number" name="items[${idx}][price]" class="form-control form-control-sm inv-price mk-currency" value="0" step="0.01"></td>
        <td style="width:110px" class="fw-semibold inv-total">0.00</td>
        <td style="width:46px"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
      `;
      invBody.appendChild(row);
      attachRowListeners(row);
      updateInvTotals();
    });
  }

  function attachRowListeners(row) {
    row.querySelectorAll('.inv-qty,.inv-price').forEach(function (inp) {
      inp.addEventListener('input', updateInvTotals);
    });
    const removeBtn = row.querySelector('.remove-row');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        row.remove();
        updateInvTotals();
      });
    }
  }

  function updateInvTotals() {
    if (!invBody) return;
    let subtotal = 0;
    invBody.querySelectorAll('tr').forEach(function (row) {
      const qty   = parseFloat(row.querySelector('.inv-qty')?.value)   || 0;
      const price = parseFloat(row.querySelector('.inv-price')?.value) || 0;
      const total = qty * price;
      const totalCell = row.querySelector('.inv-total');
      if (totalCell) totalCell.textContent = total.toLocaleString('ar-SA', {minimumFractionDigits:2});
      subtotal += total;
    });

    const subEl  = document.getElementById('invSubtotal');
    const discEl = document.getElementById('invDiscount');
    const discTypeEl = document.getElementById('invDiscountType');
    const discHid = document.getElementById('invDiscountHidden');
    const discShow = document.getElementById('invDiscountResolved');
    const taxEl  = document.getElementById('invTaxRate');
    const taxAmEl= document.getElementById('invTaxAmount');
    const totEl  = document.getElementById('invTotal');
    const subHid = document.getElementById('invSubtotalHidden');
    const taxHid = document.getElementById('invTaxAmountHidden');
    const totHid = document.getElementById('invTotalHidden');

    const discVal  = parseFloat(discEl?.value) || 0;
    const discType = discTypeEl ? discTypeEl.value : 'fixed';
    let disc = discType === 'percent' ? (subtotal * discVal / 100) : discVal;
    disc = Math.max(0, Math.min(disc, subtotal));
    const taxRate = parseFloat(taxEl?.value)  || 0;
    const taxable = Math.max(0, subtotal - disc);
    const tax     = taxable * (taxRate / 100);
    const total   = taxable + tax;

    if (subEl)   subEl.textContent  = subtotal.toLocaleString('ar-SA',{minimumFractionDigits:2});
    if (taxAmEl) taxAmEl.textContent = tax.toLocaleString('ar-SA',{minimumFractionDigits:2});
    if (totEl)   totEl.textContent  = total.toLocaleString('ar-SA',{minimumFractionDigits:2});
    if (discShow) discShow.textContent = discType === 'percent'
        ? ('= ' + disc.toLocaleString('ar-SA',{minimumFractionDigits:2}) + ' ر.س') : '';
    if (subHid)  subHid.value = subtotal.toFixed(2);
    if (discHid) discHid.value = disc.toFixed(2);
    if (taxHid)  taxHid.value = tax.toFixed(2);
    if (totHid)  totHid.value = total.toFixed(2);
  }

  // Init existing rows
  if (invBody) {
    invBody.querySelectorAll('tr').forEach(attachRowListeners);
    updateInvTotals();
    document.getElementById('invDiscount')?.addEventListener('input', updateInvTotals);
    document.getElementById('invDiscountType')?.addEventListener('change', updateInvTotals);
    document.getElementById('invTaxRate')?.addEventListener('input', updateInvTotals);
  }

  /* ── Search highlight ─────────────────────────────────────── */
  const searchInputs = document.querySelectorAll('input[name="q"]');
  searchInputs.forEach(function (inp) {
    inp.addEventListener('keyup', function () {
      const val = inp.value.trim().toLowerCase();
      const table = inp.closest('form')?.parentElement?.querySelector('table tbody');
      if (!table || !val) return;
      table.querySelectorAll('tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
      });
    });
  });

  /* ── DOMContentLoaded init ────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new bootstrap.Tooltip(el, { placement: 'top', trigger: 'hover' });
    });

    // Active link detection (already done server-side via PHP, but just in case)
    const currentPath = window.location.pathname.split('/').pop();
    document.querySelectorAll('.mk-sb-item').forEach(function (link) {
      const href = link.getAttribute('href');
      if (href && (href === currentPath || href.split('?')[0] === currentPath)) {
        link.classList.add('active');
      }
    });
  });

})();

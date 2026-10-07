(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const list = $('itemsList');
  const context = window.ODC_CONTEXT || {};
  let sequence = 0;

  function numberValue(value) {
    const n = Number.parseFloat(String(value ?? '').replace(/,/g, ''));
    return Number.isFinite(n) ? n : 0;
  }

  function currencyCode() {
    const value = $('moneda')?.value || 'MXN';
    return value === 'USD' ? 'USD' : 'MXN';
  }

  function money(value) {
    return new Intl.NumberFormat('es-MX', {
      style: 'currency',
      currency: currencyCode(),
    }).format(numberValue(value));
  }

  function draftKey() {
    const email = String(context.user?.email || 'usuario').toLowerCase();
    return `odc-preview-draft:${email}`;
  }

  function addItem(data = {}) {
    sequence += 1;
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.rowId = String(sequence);
    row.innerHTML = `
      <input class="item-qty" type="number" min="0.01" step="0.01" value="${data.qty ?? 1}" aria-label="Cantidad">
      <input class="item-description" type="text" value="${escapeHtml(data.description ?? '')}" placeholder="Descripción de la compra" aria-label="Descripción">
      <input class="item-price" type="number" min="0" step="0.01" value="${data.price ?? ''}" placeholder="0.00" aria-label="Precio unitario">
      <div class="item-amount" aria-label="Importe">$0.00</div>
      <button class="remove-item" type="button" aria-label="Eliminar partida">×</button>
    `;
    list.appendChild(row);
    row.querySelectorAll('input').forEach((input) => input.addEventListener('input', recalculate));
    row.querySelector('.remove-item').addEventListener('click', () => {
      row.remove();
      if (!list.children.length) addItem();
      recalculate();
    });
    recalculate();
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function itemsData() {
    return [...list.querySelectorAll('.item-row')].map((row) => {
      const qty = numberValue(row.querySelector('.item-qty').value);
      const description = row.querySelector('.item-description').value.trim();
      const price = numberValue(row.querySelector('.item-price').value);
      return { qty, description, price, amount: qty * price };
    });
  }

  function taxValue(checkId, pctId, subtotal) {
    if (!$(checkId).checked) return 0;
    return subtotal * numberValue($(pctId).value) / 100;
  }

  function recalculate() {
    const items = itemsData();
    let subtotal = 0;
    [...list.querySelectorAll('.item-row')].forEach((row, i) => {
      const amount = items[i].amount;
      subtotal += amount;
      row.querySelector('.item-amount').textContent = money(amount);
    });

    const iva = taxValue('aplicaIva', 'ivaPct', subtotal);
    const retIsr = taxValue('aplicaRetIsr', 'retIsrPct', subtotal);
    const retIva = taxValue('aplicaRetIva', 'retIvaPct', subtotal);
    const total = subtotal + iva - retIsr - retIva;

    $('subtotal').textContent = money(subtotal);
    $('iva').textContent = money(iva);
    $('retIsr').textContent = retIsr ? `-${money(retIsr)}` : money(0);
    $('retIva').textContent = retIva ? `-${money(retIva)}` : money(0);
    $('total').textContent = money(total);
    $('formStatus').textContent = subtotal > 0
      ? `Subtotal ${money(subtotal)} · Total ${money(total)}`
      : 'Captura una partida para calcular el total.';

    return { items, subtotal, iva, retIsr, retIva, total };
  }

  function bindTaxToggle(checkId, pctId) {
    const check = $(checkId);
    const pct = $(pctId);
    check.addEventListener('change', () => {
      pct.disabled = !check.checked;
      check.closest('.tax-row').classList.toggle('active-tax', check.checked);
      recalculate();
    });
    pct.addEventListener('input', recalculate);
  }

  function displayDateToIso(value) {
    const match = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!match) return '';
    const day = Number(match[1]);
    const month = Number(match[2]);
    const year = Number(match[3]);
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return '';
    return `${match[3]}-${match[2]}-${match[1]}`;
  }

  function isoToDisplayDate(value) {
    const match = String(value || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : value;
  }

  function syncDateFromDisplay() {
    const display = $('fechaDisplay');
    if (!display) return true;
    const iso = displayDateToIso(display.value);
    if (!iso) {
      display.setCustomValidity('Usa el formato dd/mm/aaaa.');
      return false;
    }
    display.setCustomValidity('');
    $('fecha').value = iso;
    return true;
  }

  function currentData() {
    syncDateFromDisplay();
    const totals = recalculate();
    return {
      itemId: Number(context.itemId || 0),
      folio: String(context.folio || ''),
      empresaCompradora: $('empresaCompradora').value,
      fecha: $('fecha').value,
      proveedor: $('proveedor').value.trim(),
      rfc: $('rfc').value.trim().toUpperCase(),
      telefono: $('telefono').value.trim(),
      domicilio: $('domicilio') ? $('domicilio').value.trim() : '',
      ciudadEstado: $('ciudadEstado').value.trim(),
      condicionPago: $('condicionPago').value.trim(),
      tiempoEntrega: $('tiempoEntrega').value.trim(),
      moneda: $('moneda').value,
      tipoCambio: numberValue($('tipoCambio').value),
      banco: $('banco').value.trim(),
      cuenta: $('cuenta').value.trim(),
      clabe: $('clabe').value.trim(),
      observaciones: $('observaciones') ? $('observaciones').value.trim() : '',
      user: context.user || {},
      ivaPct: $('aplicaIva').checked ? numberValue($('ivaPct').value) : 0,
      retIsrPct: $('aplicaRetIsr').checked ? numberValue($('retIsrPct').value) : 0,
      retIvaPct: $('aplicaRetIva').checked ? numberValue($('retIvaPct').value) : 0,
      ...totals,
    };
  }

  function persistLocal(data) {
    try {
      localStorage.setItem(draftKey(), JSON.stringify(data));
      return true;
    } catch (_) {
      return false;
    }
  }

  async function apiRequest(url, options = {}) {
    const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
    if (context.csrf) headers['X-CSRF-Token'] = context.csrf;
    const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
    let payload = null;
    try { payload = await response.json(); } catch (_) {}
    if (!response.ok) {
      const error = new Error(payload?.error || `HTTP ${response.status}`);
      error.payload = payload;
      error.status = response.status;
      throw error;
    }
    return payload || {};
  }

  function showSchemaMissing(missing) {
    const names = Array.isArray(missing) ? missing : [];
    $('btnPrepareSharepoint').hidden = false;
    $('sharepointStatus').className = 'sharepoint-status warning';
    $('sharepointStatus').textContent = names.length
      ? `SharePoint conectado; faltan ${names.length} columnas para guardar: ${names.join(', ')}.`
      : 'La lista requiere preparación antes de guardar.';
  }

  async function checkSharepoint() {
    if (context.prototype) {
      $('sharepointStatus').textContent = 'Modo local: SharePoint no disponible en esta vista.';
      return;
    }
    try {
      const result = await apiRequest('index.php?action=diagnostico');
      if (result.ready) {
        $('sharepointStatus').className = 'sharepoint-status ok';
        $('sharepointStatus').textContent = 'SharePoint listo · BI_Ordenes_Compra conectada.';
        $('btnPrepareSharepoint').hidden = true;
      } else {
        showSchemaMissing(result.missing);
      }
    } catch (error) {
      $('sharepointStatus').className = 'sharepoint-status error';
      $('sharepointStatus').textContent = `No fue posible validar SharePoint: ${error.message}`;
    }
  }

  async function prepareSharepoint() {
    const button = $('btnPrepareSharepoint');
    button.disabled = true;
    button.textContent = 'Preparando…';
    $('sharepointStatus').textContent = 'Creando columnas faltantes en BI_Ordenes_Compra…';
    try {
      const result = await apiRequest('index.php?action=preparar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      });
      $('sharepointStatus').className = 'sharepoint-status ok';
      $('sharepointStatus').textContent = `Lista preparada correctamente${result.created?.length ? ` · ${result.created.length} columnas creadas` : ''}.`;
      button.hidden = true;
    } catch (error) {
      const missing = error.payload?.missing || [];
      showSchemaMissing(missing);
      const firstError = error.payload?.errors ? Object.values(error.payload.errors)[0] : '';
      if (firstError) $('sharepointStatus').textContent += ` ${firstError}`;
    } finally {
      button.disabled = false;
      button.textContent = 'Preparar lista SharePoint';
    }
  }

  function utf8ToBase64(value) {
    const bytes = new TextEncoder().encode(value);
    let binary = '';
    bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
    return btoa(binary);
  }

  function saveDraft() {
    if (!syncDateFromDisplay()) {
      $('fechaDisplay').reportValidity();
      return;
    }
    const data = currentData();
    persistLocal(data);

    if (context.prototype) {
      $('formStatus').textContent = 'Borrador guardado únicamente en este navegador (modo local).';
      return;
    }

    $('formStatus').textContent = 'Guardando borrador en SharePoint…';
    $('draftPayload').value = utf8ToBase64(JSON.stringify(data));
    $('btnDraft').disabled = true;
    $('btnDraft').textContent = 'Guardando…';
    $('odcForm').submit();
  }

  function validateAttachments() {
    const input = $('attachments');
    if (!input) return true;
    const files = Array.from(input.files || []);
    const total = files.reduce((sum, file) => sum + Number(file.size || 0), 0);
    const errors = [];
    if (files.length > 5) errors.push('Solo se permiten hasta 5 archivos.');
    if (total > 2621440) errors.push('El tamaño combinado no puede superar 2.5 MB.');
    input.setCustomValidity(errors.join(' '));
    return errors.length === 0;
  }

  function sendTestEmail() {
    if (!validateAttachments()) {
      $('attachments').reportValidity();
      $('btnTestEmail').dataset.sending = '0';
      return;
    }
    if (!syncDateFromDisplay()) {
      $('fechaDisplay').reportValidity();
      $('btnTestEmail').dataset.sending = '0';
      return;
    }
    const data = currentData();
    persistLocal(data);

    if (context.prototype) {
      $('formStatus').textContent = 'El correo solo puede enviarse desde el Portal publicado.';
      return;
    }

    if (!context.folio || !context.itemId) {
      $('formStatus').textContent = 'Guarda primero el borrador para obtener un folio antes de enviar el correo.';
      return;
    }

    data.itemId = Number(context.itemId);
    data.folio = String(context.folio);

    $('formStatus').textContent = 'Enviando correo a gabriel.guerra@juanpablo.com.mx…';
    $('draftPayload').value = utf8ToBase64(JSON.stringify(data));

    const actionInput = $('odcForm').querySelector('input[name="form_action"]');
    actionInput.value = 'send_email';

    const button = $('btnTestEmail');
    button.disabled = true;
    button.textContent = 'Enviando…';
    $('odcForm').submit();
  }

  function populateDraft(data) {
    if (!data || typeof data !== 'object') return;
    const ids = ['proveedor','rfc','telefono','domicilio','ciudadEstado','condicionPago','tiempoEntrega','moneda','tipoCambio','banco','cuenta','clabe','observaciones'];
    ids.forEach((id) => {
      if (data[id] !== undefined && $(id)) $(id).value = data[id];
    });
    if (data.fecha && $('fecha')) {
      $('fecha').value = data.fecha;
      if ($('fechaDisplay')) $('fechaDisplay').value = isoToDisplayDate(data.fecha);
    }
    if (!context.itemId) context.itemId = Number(data.itemId || 0);
    if (!context.folio) context.folio = String(data.folio || '');
    if (context.folio) $('folioDisplay').textContent = context.folio;

    list.innerHTML = '';
    (Array.isArray(data.items) && data.items.length ? data.items : [{ qty: 1 }]).forEach(addItem);
    if (Number.isFinite(data.ivaPct)) {
      $('aplicaIva').checked = data.ivaPct > 0;
      $('ivaPct').disabled = !data.ivaPct;
      $('ivaPct').value = data.ivaPct || 16;
    }
    if (Number.isFinite(data.retIsrPct)) {
      $('aplicaRetIsr').checked = data.retIsrPct > 0;
      $('retIsrPct').disabled = !data.retIsrPct;
      $('retIsrPct').value = data.retIsrPct || 0;
    }
    if (Number.isFinite(data.retIvaPct)) {
      $('aplicaRetIva').checked = data.retIvaPct > 0;
      $('retIvaPct').disabled = !data.retIvaPct;
      $('retIvaPct').value = data.retIvaPct || 0;
    }
    document.querySelectorAll('.tax-row').forEach((row) => {
      const checkbox = row.querySelector('input[type="checkbox"]');
      row.classList.toggle('active-tax', checkbox.checked);
    });
    recalculate();
  }

  function preview() {
    const d = currentData();
    const populatedRows = d.items.filter((x) => x.description || x.amount > 0);
    const minimumRows = 12;
    const rows = [...populatedRows];
    while (rows.length < minimumRows) rows.push({ qty: '', description: '', price: '', amount: '' });

    const itemRows = rows.map((x) => `
      <tr>
        <td class="odc-qty">${x.qty || ''}</td>
        <td class="odc-desc">${escapeHtml(x.description || '')}</td>
        <td class="odc-money">${x.price === '' ? '' : money(x.price)}</td>
        <td class="odc-money">${x.amount === '' ? '' : money(x.amount)}</td>
      </tr>`).join('');

    $('previewContent').innerHTML = `
      <div class="odc-sheet">
        <div class="odc-top-grid">
          <div class="odc-brandmark">
            <img src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo">
          </div>

          <div class="odc-title-block">
            <div class="odc-main-title">ORDEN DE COMPRA</div>
            <div class="odc-company-name">Jardines de Juan Pablo</div>
            <div class="odc-company-line">RAZON SOCIAL: MEGUESA&nbsp;&nbsp;&nbsp; RFC: MEG-060608-LQ6</div>
            <div class="odc-company-line">CALLE: CHURUBUSCO NORTE No 217&nbsp;&nbsp; COLONIA: CHURUBUSCO</div>
            <div class="odc-company-line">MONTERREY, NL CP 64590</div>
          </div>

          <div class="odc-number-date">
            <div class="odc-box odc-number">No&nbsp;&nbsp; ${escapeHtml(d.folio || 'PENDIENTE')}</div>
            <div class="odc-box odc-date-label">FECHA</div>
            <div class="odc-box odc-date-value">${formatDate(d.fecha)}</div>
          </div>
        </div>

        <table class="odc-provider-table">
          <colgroup>
            <col class="odc-label-col"><col class="odc-value-col"><col class="odc-right-label-col"><col class="odc-right-value-col">
          </colgroup>
          <tbody>
            <tr><th>EMPRESA:</th><td>${escapeHtml(d.proveedor || '')}</td><th></th><td></td></tr>
            <tr><th>DOMICILIO:</th><td>${escapeHtml(d.domicilio || '')}</td><th>TELEFONO:</th><td>${escapeHtml(d.telefono || '')}</td></tr>
            <tr><th>CIUDAD Y ESTADO:</th><td>${escapeHtml(d.ciudadEstado || '')}</td><th>T/ENTREGA:</th><td>${escapeHtml(d.tiempoEntrega || '')}</td></tr>
            <tr><th>COND. DE PAGO:</th><td>${escapeHtml(d.condicionPago || '')}</td><th>T. CAMBIO:</th><td>${escapeHtml(String(d.tipoCambio || 1))}</td></tr>
            <tr><th>MONEDA:</th><td>${escapeHtml(d.moneda || 'MXN')}</td><th>RFC:</th><td>${escapeHtml(d.rfc || '')}</td></tr>
          </tbody>
        </table>

        <table class="odc-items-table">
          <colgroup><col class="qty"><col class="desc"><col class="unit"><col class="amount"></colgroup>
          <thead><tr><th>CANTIDAD</th><th>DESCRIPCIÓN</th><th>PRECIO<br>UNITARIO</th><th>IMPORTE</th></tr></thead>
          <tbody>${itemRows}</tbody>
        </table>

        <div class="odc-bottom-grid">
          <div class="odc-confirmation">
            <div class="odc-confirm-banner">FAVOR DE CONFIRMAR RECEPCION DE OC</div>
            <div class="odc-confirm-title">CONFIRMACION DE REQUISICION</div>
            <div class="odc-line-field"><span>NOMBRE:</span><strong>${escapeHtml(d.user.name || 'Usuario')}</strong></div>
            <div class="odc-line-field"><span>PUESTO:</span><strong></strong></div>
            <div class="odc-line-field odc-signature"><span>FIRMA:</span><strong></strong></div>
          </div>

          <div class="odc-financials">
            <table class="odc-totals-table">
              <tbody>
                <tr><th>SUBTOTAL:</th><td>${money(d.subtotal)}</td></tr>
                <tr><th>I.V.A.</th><td>${money(d.iva)}</td></tr>
                <tr><th>retención ISR</th><td>${d.retIsr ? '-' + money(d.retIsr) : money(0)}</td></tr>
                <tr><th>Retención IVA</th><td>${d.retIva ? '-' + money(d.retIva) : money(0)}</td></tr>
                <tr class="odc-total-row"><th>TOTAL</th><td>${money(d.total)}</td></tr>
              </tbody>
            </table>

            <table class="odc-bank-table">
              <tbody>
                <tr><th>No. Cuenta.</th><td>${escapeHtml(d.cuenta || '')}</td></tr>
                <tr><th>No. Clabe</th><td>${escapeHtml(d.clabe || '')}</td></tr>
                <tr><th>Banco</th><td>${escapeHtml(d.banco || '')}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>`;

    $('previewModal').hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function formatDate(iso) {
    if (!iso) return '';
    const parts = iso.split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : iso;
  }

  function closeModal() {
    $('previewModal').hidden = true;
    document.body.style.overflow = '';
  }

  $('btnAddItem').addEventListener('click', () => addItem());
  $('btnDraft').addEventListener('click', saveDraft);
  $('btnPreview').addEventListener('click', preview);
  $('btnTestEmail').addEventListener('click', (event) => {
    event.preventDefault();
    if ($('btnTestEmail').dataset.sending === '1') return;
    $('btnTestEmail').dataset.sending = '1';
    sendTestEmail();
  });
  $('btnPrepareSharepoint').addEventListener('click', prepareSharepoint);
  document.querySelectorAll('[data-close-modal]').forEach((el) => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !$('previewModal').hidden) closeModal(); });

  bindTaxToggle('aplicaIva', 'ivaPct');
  bindTaxToggle('aplicaRetIsr', 'retIsrPct');
  bindTaxToggle('aplicaRetIva', 'retIvaPct');
  if ($('attachments') && $('attachmentList')) {
    $('attachments').addEventListener('change', () => {
      const count = $('attachments').files ? $('attachments').files.length : 0;
      $('attachmentList').textContent = count ? count + ' archivo(s) seleccionado(s).' : 'Sin archivos seleccionados.';
      validateAttachments();
    });
  }
  $('moneda').addEventListener('change', recalculate);
  if ($('fechaPickerButton') && $('fecha')) {
    $('fechaPickerButton').addEventListener('click', () => {
      if (typeof $('fecha').showPicker === 'function') {
        $('fecha').showPicker();
      } else {
        $('fecha').focus();
        $('fecha').click();
      }
    });
    $('fecha').addEventListener('change', () => {
      if ($('fechaDisplay')) {
        $('fechaDisplay').value = isoToDisplayDate($('fecha').value);
        $('fechaDisplay').setCustomValidity('');
      }
    });
  }

  if ($('fechaDisplay')) {
    $('fechaDisplay').addEventListener('input', () => {
      let digits = $('fechaDisplay').value.replace(/\D/g, '').slice(0, 8);
      if (digits.length > 4) digits = digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
      else if (digits.length > 2) digits = digits.slice(0, 2) + '/' + digits.slice(2);
      $('fechaDisplay').value = digits;
      syncDateFromDisplay();
    });
    $('fechaDisplay').addEventListener('blur', syncDateFromDisplay);
  }

  let restored = null;
  try { restored = JSON.parse(localStorage.getItem(draftKey()) || 'null'); } catch (_) {}
  if (restored) populateDraft(restored); else addItem();

  if (context.saveOk && context.folio) {
    const saved = currentData();
    saved.itemId = context.itemId;
    saved.folio = context.folio;
    persistLocal(saved);
    $('folioDisplay').textContent = context.folio;
    $('formStatus').textContent = context.folio + ' guardado en BI_Ordenes_Compra como BORRADOR.';
  } else if (context.saveError) {
    $('formStatus').textContent = 'No se guardó en SharePoint: ' + context.saveError + '. Se conservó una copia local.';
  }

  if (context.mailOk) {
    if (context.mailFolio) context.folio = context.mailFolio;
    if (context.mailItemId) context.itemId = Number(context.mailItemId);
    if (context.folio) $('folioDisplay').textContent = context.folio;
    $('formStatus').textContent = 'Orden de Compra enviada correctamente a ' + context.mailRecipient + '.';
  } else if (context.mailError) {
    $('formStatus').textContent = 'No se pudo enviar el correo: ' + context.mailError;
  }

  checkSharepoint();
})();

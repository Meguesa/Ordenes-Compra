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

  function currentData() {
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

  async function saveDraft() {
    const button = $('btnDraft');
    const data = currentData();
    persistLocal(data);

    if (context.prototype) {
      $('formStatus').textContent = 'Borrador guardado únicamente en este navegador (modo local).';
      return;
    }

    button.disabled = true;
    button.textContent = 'Guardando…';
    $('formStatus').textContent = 'Guardando borrador en SharePoint…';
    try {
      const result = await apiRequest('index.php?action=guardar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      context.itemId = result.itemId;
      context.folio = result.folio;
      $('folioDisplay').textContent = result.folio || 'PENDIENTE';
      const saved = currentData();
      persistLocal(saved);
      $('formStatus').textContent = `${result.folio} guardado en BI_Ordenes_Compra como BORRADOR.`;
      $('sharepointStatus').className = 'sharepoint-status ok';
      $('sharepointStatus').textContent = 'SharePoint listo · último borrador guardado correctamente.';
    } catch (error) {
      if (error.payload?.code === 'SCHEMA_MISSING') showSchemaMissing(error.payload.missing);
      $('formStatus').textContent = `No se guardó en SharePoint: ${error.message}. Se conservó una copia local.`;
    } finally {
      button.disabled = false;
      button.textContent = 'Guardar borrador';
    }
  }

  function populateDraft(data) {
    if (!data || typeof data !== 'object') return;
    const ids = ['fecha','proveedor','rfc','telefono','domicilio','ciudadEstado','condicionPago','tiempoEntrega','moneda','tipoCambio','banco','cuenta','clabe'];
    ids.forEach((id) => {
      if (data[id] !== undefined && $(id)) $(id).value = data[id];
    });
    context.itemId = Number(data.itemId || 0);
    context.folio = String(data.folio || '');
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
    const itemRows = d.items.filter((x) => x.description || x.amount > 0).map((x) => `
      <tr>
        <td>${x.qty || ''}</td>
        <td>${escapeHtml(x.description || '—')}</td>
        <td>${money(x.price)}</td>
        <td>${money(x.amount)}</td>
      </tr>`).join('') || '<tr><td colspan="4" style="text-align:center">Sin partidas capturadas</td></tr>';

    $('previewContent').innerHTML = `
      <div class="preview-top">
        <div class="preview-logo-title"><img class="preview-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo"><h3>ORDEN DE COMPRA</h3></div>
        <div class="preview-number">No. ${escapeHtml(d.folio || 'PENDIENTE')}<br><span>${formatDate(d.fecha)}</span></div>
      </div>
      <div class="preview-company">
        <strong>Jardines de Juan Pablo</strong><br>
        RAZÓN SOCIAL: MEGUESA &nbsp;&nbsp; RFC: MEG-060608-LQ6<br>
        CALLE: CHURUBUSCO NORTE No. 217 &nbsp; COLONIA: CHURUBUSCO<br>
        MONTERREY, N.L. C.P. 64590
      </div>
      <div class="preview-provider">
        <div><strong>EMPRESA:</strong> ${escapeHtml(d.proveedor || '—')}</div>
        <div><strong>TELÉFONO:</strong> ${escapeHtml(d.telefono || '—')}</div>
        <div><strong>RFC:</strong> ${escapeHtml(d.rfc || '—')}</div>
        <div><strong>T. ENTREGA:</strong> ${escapeHtml(d.tiempoEntrega || '—')}</div>
        <div><strong>DOMICILIO:</strong> ${escapeHtml(d.domicilio || '—')}</div>
        <div></div>
        <div><strong>CIUDAD/ESTADO:</strong> ${escapeHtml(d.ciudadEstado || '—')}</div>
        <div><strong>T. CAMBIO:</strong> ${d.tipoCambio || 1}</div>
        <div><strong>COND. DE PAGO:</strong> ${escapeHtml(d.condicionPago || '—')}</div>
        <div><strong>MONEDA:</strong> ${escapeHtml(d.moneda)}</div>
      </div>
      <table class="preview-table">
        <thead><tr><th>CANTIDAD</th><th>DESCRIPCIÓN</th><th>PRECIO UNITARIO</th><th>IMPORTE</th></tr></thead>
        <tbody>${itemRows}</tbody>
      </table>
      <div class="preview-bottom">
        <div>
          <div class="preview-requester"><strong>CONFIRMACIÓN DE REQUISICIÓN</strong><br><br><strong>NOMBRE:</strong> ${escapeHtml(d.user.name || 'Usuario')}<br><strong>CORREO:</strong> ${escapeHtml(d.user.email || '—')}</div>
          <div class="preview-bank" style="margin-top:22px"><strong>DATOS BANCARIOS</strong><br><strong>Banco:</strong> ${escapeHtml(d.banco || '—')}<br><strong>No. Cuenta:</strong> ${escapeHtml(d.cuenta || '—')}<br><strong>No. CLABE:</strong> ${escapeHtml(d.clabe || '—')}</div>
        </div>
        <div class="preview-totals">
          <div><span>SUBTOTAL</span><strong>${money(d.subtotal)}</strong></div>
          <div><span>IVA ${d.ivaPct ? d.ivaPct + '%' : ''}</span><strong>${money(d.iva)}</strong></div>
          <div><span>RETENCIÓN ISR</span><strong>${d.retIsr ? '-' + money(d.retIsr) : money(0)}</strong></div>
          <div><span>RETENCIÓN IVA</span><strong>${d.retIva ? '-' + money(d.retIva) : money(0)}</strong></div>
          <div class="grand"><span>TOTAL</span><strong>${money(d.total)}</strong></div>
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
  $('btnPrepareSharepoint').addEventListener('click', prepareSharepoint);
  document.querySelectorAll('[data-close-modal]').forEach((el) => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !$('previewModal').hidden) closeModal(); });

  bindTaxToggle('aplicaIva', 'ivaPct');
  bindTaxToggle('aplicaRetIsr', 'retIsrPct');
  bindTaxToggle('aplicaRetIva', 'retIvaPct');
  $('moneda').addEventListener('change', recalculate);

  let restored = null;
  try { restored = JSON.parse(localStorage.getItem(draftKey()) || 'null'); } catch (_) {}
  if (restored) populateDraft(restored); else addItem();
  checkSharepoint();
})();

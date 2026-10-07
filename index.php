<?php

declare(strict_types=1);

$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$bootstrap = $root . '/includes/bootstrap.php';
$prototypeMode = !is_file($bootstrap);

if (!$prototypeMode) {
    require_once $bootstrap;
    portal_require_authentication();
    require_once __DIR__ . '/includes/ordenes-access.php';
    ordenes_require_preview_access();
    $user = portal_user();
    if (empty($_SESSION['ordenes_csrf']) || !is_string($_SESSION['ordenes_csrf'])) {
        $_SESSION['ordenes_csrf'] = bin2hex(random_bytes(24));
    }
    if (empty($_SESSION['ordenes_mail_nonce']) || !is_string($_SESSION['ordenes_mail_nonce'])) {
        $_SESSION['ordenes_mail_nonce'] = bin2hex(random_bytes(24));
    }
    if (!isset($_SESSION['ordenes_mail_used']) || !is_array($_SESSION['ordenes_mail_used'])) {
        $_SESSION['ordenes_mail_used'] = [];
    }
} else {
    $user = [
        'name' => 'Gabriel Guerra',
        'email' => 'gabriel.guerra@juanpablo.com.mx',
    ];
}

$saveResult = null;
$saveError = '';
$mailResult = null;
$mailError = '';
$mailPayload = null;

if (!$prototypeMode && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_draft') {
    try {
        $expected = (string)($_SESSION['ordenes_csrf'] ?? '');
        $received = (string)($_POST['csrf_token'] ?? '');
        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            throw new RuntimeException('La sesion del formulario expiro. Actualiza la pagina e intentalo nuevamente.');
        }

        $encoded = trim((string)($_POST['draft_payload'] ?? ''));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || $decoded === '') throw new RuntimeException('No fue posible leer los datos del borrador.');
        $input = json_decode($decoded, true);
        if (!is_array($input)) throw new RuntimeException('El borrador recibido no es valido.');

        $saveResult = ordenes_save_draft_payload($input, $user);
    } catch (Throwable $error) {
        $saveError = $error->getMessage();
    }
}

if (!$prototypeMode && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['form_action'] ?? '') === 'send_test_email') {
    try {
        $expected = (string)($_SESSION['ordenes_csrf'] ?? '');
        $received = (string)($_POST['csrf_token'] ?? '');
        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            throw new RuntimeException('La sesion del formulario expiro. Actualiza la pagina e intentalo nuevamente.');
        }

        $mailNonce = trim((string)($_POST['mail_nonce'] ?? ''));
        $currentMailNonce = (string)($_SESSION['ordenes_mail_nonce'] ?? '');
        $usedMail = is_array($_SESSION['ordenes_mail_used'] ?? null) ? $_SESSION['ordenes_mail_used'] : [];

        if ($mailNonce !== '' && isset($usedMail[$mailNonce])) {
            $mailResult = [
                'ok' => true,
                'recipient' => 'gabriel.guerra@juanpablo.com.mx',
                'duplicate' => true,
            ];
        } else {
            if ($mailNonce === '' || $currentMailNonce === '' || !hash_equals($currentMailNonce, $mailNonce)) {
                throw new RuntimeException('La solicitud de correo ya expiro. Recarga la pagina antes de volver a enviarla.');
            }

            $usedMail[$mailNonce] = time();
            if (count($usedMail) > 12) {
                asort($usedMail);
                $usedMail = array_slice($usedMail, -12, null, true);
            }
            $_SESSION['ordenes_mail_used'] = $usedMail;
            $_SESSION['ordenes_mail_nonce'] = bin2hex(random_bytes(24));

            $encoded = trim((string)($_POST['draft_payload'] ?? ''));
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || $decoded === '') throw new RuntimeException('No fue posible leer los datos de la ODC.');
            $mailPayload = json_decode($decoded, true);
            if (!is_array($mailPayload)) throw new RuntimeException('Los datos recibidos no son validos.');

            $mailResult = ordenes_send_test_email($mailPayload, $user);
        }
    } catch (Throwable $error) {
        $mailError = $error->getMessage();
    }
}

if (!$prototypeMode && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    try {
        $action = trim((string)$_GET['action']);

        if ($action === 'diagnostico') {
            $missing = ordenes_missing_schema_fields();
            $missingRequired = array_values(array_intersect($missing, ordenes_required_schema_fields()));
            $missingOptional = array_values(array_diff($missing, $missingRequired));
            echo json_encode([
                'ok' => true,
                'list' => ORDENES_LIST_TITLE,
                'ready' => count($missingRequired) === 0,
                'missing' => $missing,
                'missingRequired' => $missingRequired,
                'missingOptional' => $missingOptional,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'preparar') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new RuntimeException('Metodo no permitido.');
            }
            $expected = (string)($_SESSION['ordenes_csrf'] ?? '');
            $received = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
                throw new RuntimeException('La sesion de seguridad no es valida. Recarga la pagina.');
            }

            $result = ordenes_try_prepare_schema();
            echo json_encode([
                'ok' => count($result['missing']) === 0,
                'list' => ORDENES_LIST_TITLE,
                'created' => $result['created'],
                'errors' => $result['errors'],
                'missing' => $result['missing'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'guardar') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new RuntimeException('Metodo no permitido.');
            }

            $expected = (string)($_SESSION['ordenes_csrf'] ?? '');
            $received = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
                throw new RuntimeException('La sesion de seguridad no es valida. Recarga la pagina.');
            }

            $raw = file_get_contents('php://input');
            $input = json_decode((string)$raw, true);
            if (!is_array($input)) throw new RuntimeException('El cuerpo JSON no es valido.');

            echo json_encode(
                ordenes_save_draft_payload($input, $user),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        throw new RuntimeException('Accion no reconocida.');
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $error->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$name = htmlspecialchars(trim((string)($user['name'] ?? 'Usuario')), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars(strtolower(trim((string)($user['email'] ?? ''))), ENT_QUOTES, 'UTF-8');
$today = (new DateTimeImmutable('now', new DateTimeZone('America/Monterrey')))->format('Y-m-d');
$todayDisplay = (new DateTimeImmutable('now', new DateTimeZone('America/Monterrey')))->format('d/m/Y');
?><!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Órdenes de Compra | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="styles.css?v=20261007-7">
</head>
<body>
<header class="tool-header">
  <div class="shell tool-header-inner">
    <div class="tool-brand">
      <img class="tool-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo" onerror="this.style.display='none'">
      <div class="tool-identity">
        <strong>Órdenes de Compra</strong>
        <span>Portal Interno JdJP · Jardines de Juan Pablo</span>
      </div>
    </div>

    <div class="tool-header-context">Captura y generación de órdenes de compra</div>

    <div class="tool-header-actions">
      <a class="header-action" href="/">Regresar al portal</a>
      <details class="account-menu">
        <summary class="account-trigger" aria-label="Abrir menú de usuario" title="<?= $name ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" fill="currentColor"/>
            <path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/>
          </svg>
        </summary>
        <div class="account-menu-panel">
          <div class="account-menu-info">
            <strong><?= $name ?></strong>
            <span><?= $email ?></span>
          </div>
          <?php if (!$prototypeMode): ?>
          <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
          <?php endif; ?>
        </div>
      </details>
    </div>
  </div>
</header>

<main class="shell main-content">
  <section class="form-banner">
    <div>
      <span class="status-pill">EN DESARROLLO</span>
      <p class="eyebrow">Nueva orden de compra</p>
      <h1>Captura de ODC</h1>
      <p>Completa la información del proveedor y de la compra. Los importes y totales se calculan automáticamente.</p>
    </div>
    <div class="banner-meta">
      <div><span>Folio</span><strong id="folioDisplay">PENDIENTE</strong></div>
      <div><span>Fecha</span><strong><?= htmlspecialchars((new DateTimeImmutable($today))->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>
  </section>

  <div class="development-note">
    <div>
      <strong>Vista previa controlada</strong>
      <span>Los borradores se registran en BI_Ordenes_Compra. Esta versión todavía no genera folios oficiales, PDF definitivo ni correos a Finanzas.</span>
      <span id="sharepointStatus" class="sharepoint-status">Verificando conexión con SharePoint…</span>
    </div>
    <button id="btnPrepareSharepoint" class="mini-button" type="button" hidden>Preparar lista SharePoint</button>
  </div>

  <form id="odcForm" class="odc-form" method="post" action="/ordenes-compra/" novalidate>
    <input type="hidden" name="form_action" value="save_draft">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['ordenes_csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" id="draftPayload" name="draft_payload" value="">
    <input type="hidden" id="mailNonce" name="mail_nonce" value="<?= htmlspecialchars((string)($_SESSION['ordenes_mail_nonce'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <section class="form-section">
      <div class="section-title">
        <span>1</span>
        <div>
          <h2>Datos generales</h2>
          <p>Empresa compradora, fecha y solicitante de la orden.</p>
        </div>
      </div>

      <div class="form-grid grid-4">
        <label class="span-2">Empresa compradora
          <select id="empresaCompradora" required>
            <option value="MEGUESA" selected>MEGUESA, S.A. de C.V.</option>
          </select>
        </label>
        <label>Fecha
          <div class="date-input-wrap">
            <input id="fechaDisplay" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="dd/mm/aaaa" value="<?= htmlspecialchars($todayDisplay, ENT_QUOTES, 'UTF-8') ?>" required>
            <button id="fechaPickerButton" class="date-picker-button" type="button" aria-label="Abrir selector de fecha" title="Seleccionar fecha">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M7 2v3M17 2v3M3 9h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </button>
            <input id="fecha" class="date-native-picker" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" tabindex="-1" aria-hidden="true">
          </div>
        </label>
        <label>Folio
          <input type="text" value="Se genera automáticamente" readonly>
        </label>
      </div>

      <div class="company-summary">
        <div><span>RFC</span><strong>MEG-060608-LQ6</strong></div>
        <div class="company-address"><span>Domicilio</span><strong>Churubusco Norte No. 217, Col. Churubusco, Monterrey, N.L. C.P. 64590</strong></div>
      </div>
    </section>

    <section class="form-section">
      <div class="section-title">
        <span>2</span>
        <div>
          <h2>Datos del proveedor</h2>
          <p>Información fiscal y condiciones de la compra.</p>
        </div>
      </div>

      <div class="form-grid grid-4">
        <label class="span-2">Empresa / Razón social *
          <input id="proveedor" type="text" autocomplete="organization" required placeholder="Nombre o razón social del proveedor">
        </label>
        <label>RFC *
          <input id="rfc" type="text" maxlength="13" required placeholder="RFC del proveedor">
        </label>
        <label>Teléfono
          <input id="telefono" type="tel" placeholder="Teléfono">
        </label>

        <label class="span-2">Domicilio
          <input id="domicilio" type="text" placeholder="Calle, número, colonia">
        </label>
        <label class="span-2">Ciudad y estado
          <input id="ciudadEstado" type="text" placeholder="Ej. Monterrey, Nuevo León">
        </label>
        <label>Condición de pago *
          <input id="condicionPago" type="text" required placeholder="Ej. Pago total / Crédito 30 días">
        </label>
        <label>Tiempo de entrega
          <input id="tiempoEntrega" type="text" placeholder="Ej. 5 días hábiles">
        </label>

        <label>Moneda *
          <select id="moneda" required>
            <option value="MXN" selected>MXN · Peso mexicano</option>
            <option value="USD">USD · Dólar estadounidense</option>
          </select>
        </label>
        <label>Tipo de cambio
          <input id="tipoCambio" type="number" min="0" step="0.0001" value="1.0000">
        </label>
      </div>
    </section>

    <section class="form-section">
      <div class="section-heading-row">
        <div class="section-title compact">
          <span>3</span>
          <div>
            <h2>Detalle de la compra</h2>
            <p>Agrega únicamente las partidas necesarias.</p>
          </div>
        </div>
        <button id="btnAddItem" class="secondary-button add-item" type="button">+ Agregar partida</button>
      </div>

      <div class="items-wrap">
        <div class="items-header" aria-hidden="true">
          <span>Cantidad</span><span>Descripción</span><span>Precio unitario</span><span>Importe</span><span></span>
        </div>
        <div id="itemsList" class="items-list"></div>
      </div>
    </section>

    <div class="two-column-layout">
      <section class="form-section banking-section">
        <div class="section-title">
          <span>4</span>
          <div>
            <h2>Datos bancarios</h2>
            <p>Información para el pago al proveedor.</p>
          </div>
        </div>
        <div class="form-grid grid-2">
          <label>Banco
            <input id="banco" type="text" placeholder="Nombre del banco">
          </label>
          <label>No. de cuenta
            <input id="cuenta" type="text" inputmode="numeric" placeholder="Número de cuenta">
          </label>
          <label class="span-2">CLABE
            <input id="clabe" type="text" inputmode="numeric" maxlength="18" placeholder="18 dígitos">
          </label>
        </div>
      </section>

      <section class="form-section totals-section">
        <div class="section-title">
          <span>5</span>
          <div>
            <h2>Impuestos y total</h2>
            <p>Activa solamente los conceptos aplicables.</p>
          </div>
        </div>

        <div class="tax-controls">
          <label class="tax-row active-tax">
            <span><input id="aplicaIva" type="checkbox" checked> Aplicar IVA</span>
            <span class="percent-control"><input id="ivaPct" type="number" min="0" max="100" step="0.01" value="16">%</span>
          </label>
          <label class="tax-row">
            <span><input id="aplicaRetIsr" type="checkbox"> Retención ISR</span>
            <span class="percent-control"><input id="retIsrPct" type="number" min="0" max="100" step="0.01" value="0" disabled>%</span>
          </label>
          <label class="tax-row">
            <span><input id="aplicaRetIva" type="checkbox"> Retención IVA</span>
            <span class="percent-control"><input id="retIvaPct" type="number" min="0" max="100" step="0.01" value="0" disabled>%</span>
          </label>
        </div>

        <div class="totals-card">
          <div><span>Subtotal</span><strong id="subtotal">$0.00</strong></div>
          <div><span>IVA</span><strong id="iva">$0.00</strong></div>
          <div><span>Retención ISR</span><strong id="retIsr">$0.00</strong></div>
          <div><span>Retención IVA</span><strong id="retIva">$0.00</strong></div>
          <div class="grand-total"><span>TOTAL</span><strong id="total">$0.00</strong></div>
        </div>
      </section>
    </div>

    <section class="form-section requester-section">
      <div class="section-title">
        <span>6</span>
        <div>
          <h2>Solicitante</h2>
          <p>La información se toma automáticamente de la sesión activa del Portal.</p>
        </div>
      </div>
      <div class="requester-card">
        <div class="avatar"><?= htmlspecialchars(strtoupper(substr($name !== '' ? $name : 'U', 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
        <div>
          <strong><?= $name ?></strong>
          <span><?= $email ?></span>
        </div>
        <span class="locked-pill">Automático</span>
      </div>
    </section>

    <section class="form-section">
      <div class="section-title">
        <span>7</span>
        <div>
          <h2>Observaciones</h2>
          <p>Este texto se incluirá únicamente en el cuerpo del correo. No se guarda en SharePoint ni aparece en el PDF.</p>
        </div>
      </div>
      <label>Observaciones para Finanzas
        <textarea id="observaciones" rows="4" placeholder="Ej. Favor de programar el pago antes del viernes."></textarea>
      </label>
    </section>

    <section class="form-actions-panel">
      <div class="form-status">
        <strong>Versión de prueba</strong>
        <span id="formStatus">Captura una partida para calcular el total.</span>
      </div>
      <div class="form-actions">
        <button id="btnDraft" class="secondary-button" type="button">Guardar borrador</button>
        <button id="btnPreview" class="secondary-button" type="button">Vista previa PDF</button>
        <button id="btnTestEmail" class="primary-button" type="button">Enviar prueba a Gabriel</button>
        <button class="primary-button" type="button" disabled title="Se habilitará al conectar el flujo productivo">Generar y enviar a Finanzas</button>
      </div>
    </section>
  </form>
</main>

<div id="previewModal" class="modal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="previewTitle">
    <div class="modal-header">
      <div>
        <span class="status-pill">VISTA PREVIA</span>
        <h2 id="previewTitle">Orden de Compra</h2>
      </div>
      <button class="modal-close" type="button" aria-label="Cerrar" data-close-modal>×</button>
    </div>
    <div id="previewContent" class="pdf-preview"></div>
    <div class="modal-actions">
      <button class="secondary-button" type="button" data-close-modal>Cerrar</button>
    </div>
  </section>
</div>

<script>
window.ODC_CONTEXT = <?= json_encode([
    'user' => ['name' => html_entity_decode($name), 'email' => html_entity_decode($email)],
    'prototype' => $prototypeMode,
    'csrf' => $prototypeMode ? '' : (string)($_SESSION['ordenes_csrf'] ?? ''),
    'itemId' => is_array($saveResult) ? (int)($saveResult['itemId'] ?? 0) : 0,
    'folio' => is_array($saveResult) ? (string)($saveResult['folio'] ?? '') : '',
    'saveOk' => is_array($saveResult),
    'saveError' => $saveError,
    'mailOk' => is_array($mailResult),
    'mailError' => $mailError,
    'mailRecipient' => is_array($mailResult) ? (string)($mailResult['recipient'] ?? '') : '',
    'mailItemId' => is_array($mailPayload) ? (int)($mailPayload['itemId'] ?? 0) : 0,
    'mailFolio' => is_array($mailPayload) ? (string)($mailPayload['folio'] ?? '') : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/app.js?v=20261007-7"></script>
</body>
</html>

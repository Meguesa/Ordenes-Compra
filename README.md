# Ordenes-Compra

Herramienta **Órdenes de Compra** del Portal Interno JdJP.

## Etapa actual: preview controlado

- Acceso temporal: `gabriel.guerra@juanpablo.com.mx` y `sistemas@juanpablo.com.mx`.
- Captura de proveedor, condiciones, partidas, impuestos, datos bancarios y solicitante.
- Cálculo automático de importes, subtotal y total.
- Borradores registrados en la lista SharePoint `BI_Ordenes_Compra` del sitio Centro de Control Dirección.
- Copia local del borrador como protección adicional durante pruebas.
- Vista previa del futuro PDF.
- El servidor recalcula los importes antes de escribir en SharePoint.
- Los registros de esta etapa se identifican con `Ambiente = PREVIEW`, `Estado = BORRADOR` y folio `ODC-PREVIEW-######`.

Todavía **no** genera el folio oficial, PDF definitivo ni correo a Finanzas.

## Columnas esperadas en BI_Ordenes_Compra

La herramienta diagnostica la lista al abrirse. Si faltan columnas, muestra el botón **Preparar lista SharePoint**, que intenta crearlas usando las credenciales app-only existentes del Portal. Si SharePoint no permite administrar el esquema con esas credenciales, la interfaz mostrará cuáles faltan para crearlas manualmente.

Campos: Folio, Fecha, EmpresaCompradora, Proveedor, RFC, Telefono, CiudadEstado, CondicionPago, TiempoEntrega, Moneda, TipoCambio, PartidasJson, Subtotal, IvaPct, IVA, RetIsrPct, RetencionISR, RetIvaPct, RetencionIVA, Total, Banco, Cuenta, CLABE, SolicitanteNombre, SolicitanteCorreo, Estado y Ambiente.

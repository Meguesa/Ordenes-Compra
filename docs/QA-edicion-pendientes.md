# QA: editar ODC pendientes
- No desplegar a produccion hasta validar en ambiente aislado con lista y destinatarios de prueba.
- Solo el solicitante puede editar una ODC PENDIENTE_AUTORIZACION. El folio y el ID no cambian.
- Guardar y reenviar: revision R+1, historial MODIFICADA, PDF actualizado y asunto MODIFICADO. Mantiene adjuntos previos y agrega adjuntos de la nueva revision.
- El formulario de autorizacion incluye la revision esperada. Una pestana anterior debe fallar al autorizar.
- Si el correo no se confirma, la revision modificada permanece guardada, bloqueada para autorizacion. Reabrir y reenviar sin cambios ni nuevos adjuntos debe reutilizar la revision; una modificacion adicional crea la siguiente. Un fallo de confirmacion posterior a la aceptacion por Graph puede resultar en un correo repetido, no una orden duplicada.
- El bloqueo flock coordina peticiones del mismo servidor cPanel; si se usa mas de un host o escritores externos a este flujo, se requiere concurrencia distribuida/ETag.
- Probar: envio original R1; editar R2; impuestos, descuento, empresa compradora, datos de pago; mismo ID/folio; asunto y destinatarios; adjuntos; rechazo/aprobacion; intento desde usuario ajeno y orden aprobada; aprobacion desde pestana R1; doble clic; fallo de correo y reenvio; borrador y orden nueva sin regresiones.
- No enviar correos reales ni usar registros productivos para estas pruebas.
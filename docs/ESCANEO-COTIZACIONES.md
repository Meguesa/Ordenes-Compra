# Escaneo de cotizaciones ODC (primera versión)
- Archivos PDF, JPG, PNG y WEBP hasta 10 MB. PDF: hasta 10 páginas de texto y 3 páginas OCR. No admite PDF protegido ni formatos adicionales.
- PDF.js y Tesseract.js funcionan en el navegador; dependencias fijadas y alojadas en el Portal, sin API de pago ni envío de cotizaciones a terceros.
- OCR puede tardar o fallar por resolución, rotación y diseño. Los datos sugeridos no son obligatorios: seleccionarlos individualmente y confirmar contra original. No se importa CLABE, cuenta ni banco. Se conserva la fecha de la ODC, comprador y solicitante.
- Partidas: reconocimiento conservador de encabezados y renglones cantidad, descripción, precio unitario e importe. No promete reconocer todas las tablas; exige coincidencia de cantidad por precio. Diferencias de subtotal/total se advierten. Los importes calculados por la ODC siguen sujetos a revisión.
- El archivo se agrega a los adjuntos existentes al importar. Guardar/Enviar se realiza por los flujos normales, sin generar folio automáticamente.
- No se ha validado con documentos reales de los proveedores de JJP. Probar PDF digital, escaneo/foto, IVA, descuento, varias partidas, RFC de comprador, datos ambiguos, cancelación, límite, captura manual y dispositivos móviles.
- Los archivos binarios de las librerías son generados por scripts/preparar-escaner.sh y no se versionan. Publicar recursos antes de la UI.

# Escaneo de cotizaciones ODC (primera versión)
- Archivos PDF, JPG, PNG y WEBP hasta 10 MB. PDF: hasta 10 páginas de texto y 3 páginas OCR. No admite PDF protegido ni formatos adicionales.
- PDF.js y Tesseract.js funcionan en el navegador; dependencias fijadas y alojadas en el Portal, sin API de pago ni envío de cotizaciones a terceros.
- OCR puede tardar o fallar por resolución, rotación y diseño. Los datos sugeridos no son obligatorios: seleccionarlos individualmente y confirmar contra original. No se importa CLABE, cuenta ni banco. Se conserva la fecha de la ODC, comprador y solicitante.
- Partidas: reconocimiento conservador de encabezados y renglones cantidad, descripción, precio unitario e importe. No promete reconocer todas las tablas; exige coincidencia de cantidad por precio. Diferencias de subtotal/total se advierten. Los importes calculados por la ODC siguen sujetos a revisión.
- El archivo se agrega a los adjuntos existentes al importar. Guardar/Enviar se realiza por los flujos normales, sin generar folio automáticamente.
- No se ha validado con documentos reales de los proveedores de JJP. Probar PDF digital, escaneo/foto, IVA, descuento, varias partidas, RFC de comprador, datos ambiguos, cancelación, límite, captura manual y dispositivos móviles.
- Los archivos binarios de las librerías son generados por scripts/preparar-escaner.sh y no se versionan. Publicar recursos antes de la UI.

## Validación con ejemplos reales
- Se ajustaron dos formatos de tabla: SKU/Producto antes de cantidad y número de artículo con descripción multilínea, UM y MXP. Se ejecutó localmente el parser de estos formatos con coordenadas de texto de los dos PDF aportados (PyMuPDF como extractor alternativo a PDF.js): 6/6 y 14/14 partidas; subtotales conciliados. No se subieron los PDF ni sus datos de compra al repositorio; los tests son sintéticos.
- El lector no debe confundir RFC del comprador con proveedor. El nombre comercial es una sugerencia a confirmar. Se admite seleccionar todas o algunas partidas. Se advierte una diferencia de centavos en IVA sin modificar el cálculo de servidor. Pendiente prueba del navegador con PDF.js, Tesseract y flujo completo.

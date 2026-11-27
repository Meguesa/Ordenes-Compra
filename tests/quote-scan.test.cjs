const assert=require('node:assert/strict');
const {parseQuote,pdfLines}=require('../assets/js/quote-scan.js');
const quote=[
'Proveedor: EQUIPO DEMO SA DE CV','Cliente: MEGUESA','RFC: MEG-060608-LQ6','RFC: EDE120101AB1',
'Condición de pago: Crédito 30 días','Moneda: MXN','Tiempo de entrega: 5 días',
'Cantidad Descripción Precio unitario Importe','2 Vitrina $5,000.00 $10,000.00','1 Accesorio 500.00 500.00',
'Subtotal $10,500.00','IVA 16% $1,680.00','Total $12,180.00 MXN'].join('\n');
const r=parseQuote(quote);
assert.equal(r.fields.proveedor,'EQUIPO DEMO SA DE CV');assert.equal(r.fields.rfc,'EDE120101AB1');
assert.equal(r.fields.moneda,'MXN');assert.equal(r.fields.ivaPct,16);
assert.equal(r.fields.condicionPago,'Crédito 30 días');assert.equal(r.items.length,2);
assert.equal(r.itemSubtotal,10500);assert.equal(r.amounts.total,12180);
assert.equal(parseQuote('Proveedor: MEGUESA\nRFC: MEG-060608-LQ6').fields.proveedor,undefined);
assert.equal(parseQuote('RFC: MEG-060608-LQ6').fields.rfc,undefined);
assert.equal(parseQuote('RFC: AAA120101AA1\nRFC: BBB120101BB2').fields.rfc,undefined);
assert.equal(parseQuote('Total $1,000.00').fields.moneda,undefined);
assert.equal(parseQuote('Cantidad Descripción Precio unitario Importe\n2 Silla 100.00 150.00').items.length,0);
assert.equal(parseQuote('2 Silla 100.00 200.00').items.length,0);
assert.equal(parseQuote('Subtotal 100.00\nIVA 0.00\nTotal 100.00').fields.ivaPct,0);
assert.equal(parseQuote('Total 100.00\nTotal 200.00').amounts.total,undefined);
assert.equal(parseQuote('CLABE 012345678901234567\nBanco: Banco ABC').fields.clabe,undefined);
assert.equal(pdfLines([{str:'Cantidad',transform:[1,0,0,1,10,100],width:30},{str:'Descripción',transform:[1,0,0,1,50,100],width:40}]),'Cantidad Descripción');
console.log('OK escaneo de cotizaciones: casos sintéticos');

const eq=parseQuote([
'ventas@equiprestmx.com','AAA120101AA1','Moneda: Peso Mexicano (MXN)',
'Partida Foto SKU/Producto Cantidad P.Unitario Desc% Importe',
'1 EQ-A DEMO 1.00 $100.01 $100.01','Equipo A',
'Obs: DETALLE TECNICO',
'2 EQ-B DEMO 2.00 $200.02 $400.04','Equipo B',
'SubTotal : $500.05','Descuento : -$0.00','IVA : $80.02','Total : $580.07',
'*Forma de Pago: 70 % de Anticipo y saldo antes de entregar'
].join('\n'));
assert.equal(eq.items.length,2);assert.equal(eq.itemSubtotal,500.05);
assert.equal(eq.fields.rfc,'AAA120101AA1');assert.equal(eq.fields.moneda,'MXN');
assert.equal(eq.fields.ivaPct,16);assert.ok(eq.warnings.some(w=>w.includes('Diferencia de redondeo')));
assert.equal(eq.items[0].description,'EQ-A DEMO — Equipo A');
const el=parseQuote([
'ventas@surtidorelectrico.com','MEG060608LQ6','C-1234 Condiciones de pago: contado',
'# Número de artículo Descripción Cantidad Precio UM Total',
'DESCRIPCION DE ARTICULO','1 ART1 2 12.50 MXP 2 25.0000 MXP','MARCA DEMO',
'FOMO-01','# Número de artículo Descripción Cantidad Precio UM Total',
'OTRO PRODUCTO','2 ART2 3 5.07 MXP 3 15.2100 MXP','MODELO DEMO',
'- Precios sujetos a existencia. Subtotal 40.2100 MXP',
'Impuesto 6.4336 MXP','Total 46.6436 MXP'
].join('\n'));
assert.equal(el.items.length,2);assert.equal(el.itemSubtotal,40.21);
assert.equal(el.fields.rfc,undefined);assert.equal(el.fields.condicionPago,'contado');
assert.equal(el.fields.moneda,'MXN');assert.equal(el.fields.ivaPct,16);
assert.equal(el.amounts.total,46.6436);
assert.equal(el.items[0].description,'ART1 — DESCRIPCION DE ARTICULO MARCA DEMO');
console.log('OK formatos de cotizaciones con SKU, UM, múltiples páginas y cuatro decimales');

(async()=>{
  const {releaseReader}=require('../assets/js/quote-scan.js');
  let taskClosed=0,docClosed=0,workerClosed=0;
  const docWithoutDestroy={numPages:2,cleanup(){docClosed++;}};
  const task={destroy(){taskClosed++;return Promise.resolve();}};
  const worker={terminate(){workerClosed++;return Promise.resolve();}};
  await releaseReader(task,docWithoutDestroy,worker);
  assert.equal(taskClosed,1);
  assert.equal(docClosed,0);
  assert.equal(workerClosed,1);
  await releaseReader(null,docWithoutDestroy,null);
  assert.equal(docClosed,1);
  // A failed or synchronous cleanup must not discard data already read.
  const warn=console.warn;console.warn=()=>{};
  try {
    const result=await (async()=>{try{return 'texto extraido';}finally{
      await releaseReader({destroy(){throw Error('fallo sincrono');}},docWithoutDestroy,{terminate(){return Promise.reject(Error('fallo asincrono'));}});
    }})();
    assert.equal(result,'texto extraido');
    await assert.rejects(async()=>{try{throw Error('error original');}finally{
      await releaseReader({destroy(){throw Error('fallo al cerrar');}},null,null);
    }},/error original/);
  } finally {console.warn=warn;}
  console.log('OK cierre compatible PDF.js: conserva resultado y errores originales');
})().catch(error=>{console.error(error);process.exitCode=1;});

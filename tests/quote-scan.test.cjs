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

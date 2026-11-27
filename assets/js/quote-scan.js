/* ODC: extraction is entirely client-side. No network request contains the quote. */
(function (root) {
  'use strict';
  const LIMIT = 10 * 1024 * 1024;
  const MAX_PAGES = 10;
  const MAX_OCR_PAGES = 3;
  const MAX_TEXT = 100000;
  const VENDOR = '/ordenes-compra/assets/vendor/odc-scan-v1/';
  const labels = {
    proveedor: 'Proveedor', rfc: 'RFC del proveedor', telefono: 'Teléfono',
    domicilio: 'Domicilio del proveedor', ciudadEstado: 'Ciudad y estado',
    condicionPago: 'Condición de pago', tiempoEntrega: 'Tiempo de entrega',
    moneda: 'Moneda', ivaPct: 'IVA (%)', retIsrPct: 'Retención ISR (%)',
    retIvaPct: 'Retención IVA (%)', descuentoValor: 'Descuento (monto fijo)'
  };
  const normalize = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const moneyRE = '(?:\\$\\s*|MXN\\s+|USD\\s+)?(?:\\d{1,3}(?:,\\d{3})+(?:\\.\\d{1,2})?|\\d+(?:\\.\\d{1,2})?)';
  const close = (a,b,tol=.03) => Math.abs(a-b) <= tol + 1e-8;
  const round = n => Math.round((n + Number.EPSILON)*100)/100;
  function amount(s) {
    const clean = String(s || '').replace(/(?:MXN|USD|\$|,|\s)/gi,'');
    return /^\d+(?:\.\d{1,2})?$/.test(clean) && Number.isFinite(Number(clean)) ? Number(clean) : null;
  }
  function isBuyer(s) {
    const v = normalize(s).replace(/[^a-z0-9]/g,'');
    return /meguesa|meg060608lq6|jardinesdejuanpablo|jdjpprevisiones/.test(v);
  }
  function parseQuote(text) {
    const lines = String(text || '').slice(0,MAX_TEXT).split(/\r?\n/).map(x=>x.replace(/\u00a0/g,' ').trim()).filter(Boolean);
    const fields = {}, amounts = {}, items = [], warnings = [];
    const picks = (pattern, filter) => {
      const hits = lines.map(l => l.match(pattern)).filter(Boolean).map(m=>m[1].trim()).filter(v=>v && (!filter || filter(v)));
      const unique = [...new Set(hits)];
      return unique.length === 1 ? unique[0] : '';
    };
    const labelled = [
      ['proveedor', /^(?:proveedor|emisor|raz[oó]n social (?:del )?(?:proveedor|emisor))\s*[:\-]\s*(.{3,150})$/i, v=>!isBuyer(v)],
      ['telefono', /^(?:tel[eé]fono (?:del )?proveedor|tel[eé]fono|tel\.?)\s*:\s*([\d()+\- ]{7,30})$/i],
      ['domicilio', /^(?:domicilio|direcci[oó]n) (?:del )?(?:proveedor|emisor)\s*:\s*(.{5,180})$/i],
      ['ciudadEstado', /^(?:ciudad y estado|ciudad\/estado) (?:del )?proveedor\s*:\s*(.{3,100})$/i],
      ['condicionPago', /^(?:condici[oó]n(?:es)? de pago|forma de pago|payment terms)\s*[:\-]\s*(.{2,100})$/i, v=>!/^(?:transferencia|efectivo|tarjeta)$/i.test(v)],
      ['tiempoEntrega', /^(?:tiempo de entrega|plazo de entrega|entrega|lead time)\s*[:\-]\s*(.{2,100})$/i]
    ];
    labelled.forEach(([key,pattern,filter])=>{const v=picks(pattern,filter);if(v)fields[key]=v;});
    // Do not infer a supplier from the buyer, logo or arbitrary heading.
    const candidates = new Set();
    for(const line of lines) {
      if(/\b(?:cliente|receptor|comprador)\b/i.test(line))continue;
      const matches=line.matchAll(/\b([A-ZÑ&]{3,4})[ -]?(\d{6})[ -]?([A-Z0-9]{3})\b/gi);
      for(const m of matches){const v=(m[1]+m[2]+m[3]).toUpperCase();if(!isBuyer(v))candidates.add(v);}
    }
    if(candidates.size===1)fields.rfc=[...candidates][0];
    else if(candidates.size>1)warnings.push('Se encontraron varios RFC distintos del comprador; captura manualmente el RFC correcto.');
    const currencies=new Set();
    for(const l of lines) {
      const n=normalize(l);
      if(/\b(?:usd|dolares estadounidenses|us dollars)\b|us\$/.test(n))currencies.add('USD');
      if(/\b(?:mxn|pesos mexicanos|m\.n\.)\b/.test(n))currencies.add('MXN');
    }
    if(currencies.size===1)fields.moneda=[...currencies][0];
    if(!fields.moneda)warnings.push('Moneda no identificada sin ambigüedad. Confirma MXN o USD; el símbolo $ no es suficiente.');
    const summary = [
      ['subtotal', /^(?:sub\s*total|importe antes de impuestos)\b/i],
      ['retIva', /^(?:ret(?:enci[oó]n)?\.?\s*(?:de\s+)?i\.?v\.?a\.?|i\.?v\.?a\.?\s+retenido)\b/i],
      ['retIsr', /^(?:ret(?:enci[oó]n)?\.?\s*(?:de\s+)?i\.?s\.?r\.?|i\.?s\.?r\.?\s+retenido)\b/i],
      ['iva', /^(?:i\.?v\.?a\.?|impuesto al valor agregado)\b/i],
      ['descuento', /^(?:descuento|discount)\b/i],
      ['total', /^(?:total(?:\s+(?:a pagar|general|neto))?|grand total|importe total)\b/i]
    ];
    const endMoney=new RegExp('('+moneyRE+')\\s*(?:MXN|USD|M\\.N\\.)?\\s*$','i');
    const found={};
    let table=false;
    for(let i=0;i<lines.length;i++){
      const l=lines[i], low=normalize(l);
      const summaryKey=summary.find(([,p])=>p.test(l));
      if(summaryKey){
        table=false;
        const m=l.match(endMoney);
        if(m && m.index>0 && !/[%\d]$/.test(l.slice(0,m.index).trim().slice(-1)) || m && /\s|:/.test(l[m.index-1]||'')){
          const v=amount(m[1]); if(v!==null)(found[summaryKey[0]]??=new Set()).add(v);
        }
        continue;
      }
      // Table header can span two consecutive lines.
      const heading=normalize(l+' '+(lines[i+1]||''));
      if(/\b(?:cantidad|cant\.?|qty)\b/.test(low) && /descripcion|concepto|producto|articulo|description/.test(heading) && /precio|unitario|importe|amount|price|total/.test(heading)){
        table=true;continue;
      }
      if(!table)continue;
      if(/^(?:notas?|observaciones|vigencia|firma|condiciones|datos bancarios)\b/i.test(low)){table=false;continue;}
      // Only import rows with quantity, description, unit price AND line total.
      const rowRE=new RegExp('^(\\d+(?:\\.\\d{1,3})?)\\s+(.+?)\\s+('+moneyRE+')\\s+('+moneyRE+')$','i');
      const m=l.match(rowRE);
      if(!m)continue;
      const qty=Number(m[1]), description=m[2].trim(), price=amount(m[3]), total=amount(m[4]);
      if(qty<=0 || qty>1000000 || price===null || price>1e9 || total===null || !/[a-zñáéíóú]/i.test(description)||description.length>300)continue;
      if(!close(round(qty*price),total)){
        warnings.push('Partida no importada porque cantidad × precio no coincide con el importe: '+description.slice(0,85));
        continue;
      }
      items.push({qty,description,price,amount:total});
      if(items.length>=100){warnings.push('Se alcanzó el máximo de 100 partidas sugeridas. Revisa si faltan conceptos.');break;}
    }
    for(const [key,values] of Object.entries(found)){
      if(values.size===1)amounts[key]=[...values][0];
      else warnings.push('Hay más de un valor para '+key+'; verifica manualmente el importe.');
    }
    const itemSubtotal=round(items.reduce((a,it)=>a+round(it.qty*it.price),0));
    if(!items.length)warnings.push('No se detectaron partidas comprobables. Puedes corregir el texto extraído o capturarlas manualmente.');
    if(items.length && amounts.subtotal!==undefined && !close(itemSubtotal,amounts.subtotal)){
      warnings.push('Las partidas detectadas no coinciden con el subtotal de la cotización. Pueden faltar partidas o descuentos.');
    }
    if(items.length && amounts.subtotal===undefined)warnings.push('No se detectó un subtotal único para validar las partidas.');
    // Suggest only explicit tax amounts; preserve unknown taxes for manual review.
    if(amounts.subtotal>0){
      for(const [key,field] of [['iva','ivaPct'],['retIsr','retIsrPct'],['retIva','retIvaPct']]){
        if(amounts[key]===undefined)continue;
        const pct=round(amounts[key]*100/amounts.subtotal);
        if(pct<=100 && close(round(amounts.subtotal*pct/100),amounts[key]))fields[field]=pct;
        else warnings.push('No fue posible obtener un porcentaje confiable para '+key+'.');
      }
      if(amounts.descuento!==undefined && amounts.descuento<=amounts.subtotal)fields.descuentoValor=amounts.descuento;
    }
    if(amounts.iva===undefined)warnings.push('IVA no confirmado. El formulario conserva su configuración actual hasta que la revises.');
    if(amounts.total===undefined)warnings.push('Total de la cotización no identificado de forma única. Verifica manualmente.');
    if(!fields.proveedor)warnings.push('Razón social del proveedor no identificada con certeza. Captúrala manualmente.');
    if(!fields.rfc && candidates.size<=1)warnings.push('RFC del proveedor pendiente de verificar.');
    if(!fields.condicionPago)warnings.push('Condición de pago pendiente de capturar.');
    warnings.push('Verifica impuestos, retenciones, descuentos y datos bancarios. No se importan cuentas ni CLABE.');
    return {fields,items,amounts,itemSubtotal,warnings};
  }
  function pdfLines(items){
    const rows=[];
    for(const item of items){
      if(!item.str || !item.transform)continue;
      const x=item.transform[4],y=item.transform[5];
      let row=rows.find(r=>Math.abs(r.y-y)<=Math.max(2,Math.min(4,Math.abs(item.height||8)*.25)));
      if(!row){row={y,cells:[]};rows.push(row);}
      row.cells.push({x,width:item.width||0,text:item.str});
    }
    return rows.sort((a,b)=>b.y-a.y).map(row=>{
      const cells=row.cells.sort((a,b)=>a.x-b.x);let result='',end=null;
      for(const cell of cells){
        if(end!==null && cell.x-end>2)result+=' ';
        result+=cell.text;
        end=Math.max(end??cell.x,cell.x+cell.width);
      }
      return result.trim();
    }).filter(Boolean).join('\n');
  }
  async function extract(file, update, cancelled) {
    if(!file || !file.size || file.size>LIMIT)throw Error('Selecciona un archivo de hasta 10 MB.');
    const ext=file.name.toLowerCase().split('.').pop();
    const header=new Uint8Array(await file.slice(0,16).arrayBuffer());
    const pdf=ext==='pdf' && String.fromCharCode(...header.slice(0,5))==='%PDF-';
    const image=(['jpg','jpeg'].includes(ext)&&header[0]===255&&header[1]===216) ||
      (ext==='png'&&header[0]===137&&header[1]===80&&header[2]===78&&header[3]===71) ||
      (ext==='webp'&&String.fromCharCode(...header.slice(0,4))==='RIFF'&&String.fromCharCode(...header.slice(8,12))==='WEBP');
    if(!pdf&&!image)throw Error('El archivo no parece ser un PDF, JPG, PNG o WEBP válido.');
    const base=new URL(VENDOR,root.location.href).href;
    let worker=null,doc=null,task=null;
    let ocrPages=0;
    async function ocr(input){
      if(cancelled())throw Error('Lectura cancelada.');
      if(!worker){
        update('Preparando reconocimiento de texto (primera carga)...');
        if(!root.Tesseract){
          await new Promise((resolve,reject)=>{
            const script=document.createElement('script');
            script.src=base+'ocr/tesseract.min.js';
            script.onload=resolve;script.onerror=()=>reject(Error('No se pudo cargar el motor OCR.'));
            document.head.appendChild(script);
          });
        }
        worker=await root.Tesseract.createWorker(['spa','eng'],1,{
          workerPath:base+'ocr/worker.min.js',
          corePath:base+'ocr/core/',
          langPath:base+'ocr/lang/',
          workerBlobURL:false,
          logger:m=>{if(!cancelled()&&m.status==='recognizing text')update('Reconociendo texto: '+Math.round(m.progress*100)+'%');}
        });
      }
      if(cancelled())throw Error('Lectura cancelada.');
      const result=await worker.recognize(input);
      return result.data.text||'';
    }
    try{
      if(image){
        update('Preparando imagen...');
        const url=URL.createObjectURL(file);
        try{
          const img=await new Promise((resolve,reject)=>{
            const im=new Image();im.onload=()=>resolve(im);im.onerror=()=>reject(Error('No fue posible abrir la imagen.'));im.src=url;
          });
          const scale=Math.min(1,2400/Math.max(img.naturalWidth,img.naturalHeight));
          const canvas=document.createElement('canvas');
          canvas.width=Math.max(1,Math.round(img.naturalWidth*scale));canvas.height=Math.max(1,Math.round(img.naturalHeight*scale));
          const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(img,0,0,canvas.width,canvas.height);
          return {text:(await ocr(canvas)).slice(0,MAX_TEXT),method:'OCR de imagen',partial:false};
        }finally{URL.revokeObjectURL(url);}
      }
      update('Leyendo PDF...');
      const lib=await import(base+'pdf/pdf.js');
      lib.GlobalWorkerOptions.workerSrc=base+'pdf/pdf.worker.js';
      task=lib.getDocument({data:new Uint8Array(await file.arrayBuffer()),isEvalSupported:false,
        cMapUrl:base+'pdf/cmaps/',cMapPacked:true,standardFontDataUrl:base+'pdf/standard_fonts/',
        wasmUrl:base+'pdf/wasm/',iccUrl:base+'pdf/iccs/',enableXfa:false});
      task.onPassword=()=>{task.destroy();};
      doc=await task.promise;
      const parts=[];let partial=doc.numPages>MAX_PAGES;
      for(let i=1;i<=Math.min(doc.numPages,MAX_PAGES);i++){
        if(cancelled())throw Error('Lectura cancelada.');
        update('Leyendo página '+i+' de '+Math.min(doc.numPages,MAX_PAGES)+'...');
        const page=await doc.getPage(i);
        let t=pdfLines((await page.getTextContent()).items);
        if(t.replace(/\s/g,'').length<35){
          if(ocrPages>=MAX_OCR_PAGES){partial=true;page.cleanup();continue;}
          ocrPages++;
          const vp=page.getViewport({scale:1});
          const scale=Math.min(3,2400/Math.max(vp.width,vp.height));
          const viewport=page.getViewport({scale});
          const canvas=document.createElement('canvas');
          canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
          await page.render({canvas,canvasContext:canvas.getContext('2d'),viewport}).promise;
          t=await ocr(canvas);
          canvas.width=canvas.height=1;
        }
        parts.push(t);page.cleanup();
      }
      const text=parts.join('\n\n');if(text.length>MAX_TEXT)partial=true;
      return {text:text.slice(0,MAX_TEXT),method:ocrPages?'PDF + OCR':'Texto de PDF',partial};
    }finally{
      if(worker)await worker.terminate().catch(()=>{});
      if(doc)await doc.destroy().catch(()=>{});else if(task)await task.destroy().catch(()=>{});
    }
  }
  function bind(callbacks){
    const $=id=>document.getElementById(id);
    const section=$('quoteScanSection');if(!section)return;
    section.hidden=false;
    let current=null,source=null,run=0,busy=false,partial=false;
    const fieldBox=$('quoteScanFields'),amountBox=$('quoteScanAmounts'),warningBox=$('quoteScanWarnings');
    const status=s=>{$('quoteScanStatus').textContent=s;};
    const selected=[];
    const confirm=$('quoteScanConfirm'),apply=$('quoteScanApply');
    const node=(tag,text)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=String(text);return el;};
    function change(){apply.disabled=!confirm.checked || !selected.some(x=>x.input.checked);}
    function checkbox(label,value,key,checked){
      const wrapper=node('label');wrapper.style.cssText='display:flex;align-items:flex-start;gap:8px;padding:6px 0;overflow-wrap:anywhere';
      const input=node('input');input.type='checkbox';input.checked=checked;input.addEventListener('change',change);
      const content=node('span',label+': '+value);
      wrapper.append(input,content);fieldBox.append(wrapper);selected.push({input,key});
    }
    function show(){
      current=parseQuote($('quoteScanText').value);
      fieldBox.replaceChildren();amountBox.replaceChildren();warningBox.replaceChildren();selected.length=0;
      confirm.checked=false;
      fieldBox.append(node('h3','Datos sugeridos (selecciona los que quieras importar)'));
      for(const [key,value] of Object.entries(current.fields)){
        // Tax suggestions and identity information are intentionally unchecked.
        checkbox(labels[key]||key,value,key,false);
      }
      current.items.forEach((it,i)=>checkbox('Partida '+(i+1),it.qty+' × '+it.description+' · '+it.price.toFixed(2)+' = '+it.amount.toFixed(2),'item:'+i,false));
      if(!selected.length)fieldBox.append(node('p','Sin datos identificados con suficiente estructura.'));
      const summary=Object.entries(current.amounts).map(([k,v])=>k+': '+v.toFixed(2)).join(' · ');
      amountBox.append(node('strong','Importes leídos en el documento: '),node('span',summary||'No detectados'));
      if(current.items.length)amountBox.append(node('p','Subtotal de partidas detectadas: '+current.itemSubtotal.toFixed(2)));
      const ul=node('ul');
      if(partial)ul.append(node('li','Lectura parcial: se alcanzó un límite de páginas o texto. No asumas que se leyó toda la cotización.'));
      for(const warning of current.warnings)ul.append(node('li',warning));
      warningBox.append(ul);
      $('quoteScanReview').hidden=false;change();
    }
    function reset(){
      run++;current=null;source=null;partial=false;
      $('quoteScanFile').value='';$('quoteScanText').value='';
      $('quoteScanReview').hidden=true;confirm.checked=false;change();
      status(busy?'Cancelando lectura. Puedes continuar capturando manualmente.':'Lectura eliminada. La captura de la ODC no cambió.');
    }
    $('quoteScanReset').addEventListener('click',reset);
    $('quoteScanFile').addEventListener('change',()=>{
      run++;current=null;source=null;partial=false;
      $('quoteScanReview').hidden=true;confirm.checked=false;change();
      status(busy?'Se canceló la lectura anterior. Espera a que se libere el lector.':'Archivo seleccionado. Presiona Escanear cotización.');
    });
    $('quoteScanRead').addEventListener('click',async()=>{
      if(busy)return;
      const file=$('quoteScanFile').files?.[0];
      if(!file){status('Selecciona una cotización.');return;}
      const id=++run;busy=true;$('quoteScanRead').disabled=true;
      current=null;source=null;$('quoteScanReview').hidden=true;
      try{
        const result=await extract(file,s=>{if(run===id)status(s);},()=>run!==id);
        if(run!==id)return;
        if(!result.text.trim())throw Error('No se encontró texto legible. Captura la orden manualmente.');
        source=file;partial=result.partial;$('quoteScanText').value=result.text;show();
        status('Lectura terminada ('+result.method+'). Selecciona y confirma los datos correctos.');
      }catch(error){if(run===id)status('No se pudo leer: '+(error?.message||String(error))+'. Puedes continuar la captura manual.');}
      finally{busy=false;$('quoteScanRead').disabled=false;}
    });
    $('quoteScanReparse').addEventListener('click',()=>{if(source){show();status('Sugerencias actualizadas. Verifica de nuevo los datos.');}});
    $('quoteScanText').addEventListener('input',()=>{confirm.checked=false;apply.disabled=true;status('Texto modificado. Presiona Volver a detectar datos antes de importar.');current=null;});
    confirm.addEventListener('change',change);
    apply.addEventListener('click',()=>{
      if(!current||!source||!confirm.checked)return;
      const data={},items=[];
      for(const choice of selected){
        if(!choice.input.checked)continue;
        if(choice.key.startsWith('item:'))items.push(current.items[Number(choice.key.split(':')[1])]);
        else data[choice.key]=current.fields[choice.key];
      }
      if(items.length)data.items=items;
      if(data.descuentoValor!==undefined){data.aplicaDescuento=data.descuentoValor>0;data.descuentoTipo='MONTO';}
      const existing=callbacks.getData();
      if(items.length && existing.items?.some(i=>i.description||i.price>0) &&
         !root.confirm('¿Reemplazar las partidas actualmente capturadas por las seleccionadas?'))return;
      const overlap=Object.keys(data).filter(k=>k!=='items'&&existing[k]!==undefined&&existing[k]!==''&&String(existing[k])!==String(data[k]));
      if(overlap.length && !root.confirm('Se reemplazarán valores ya capturados en los campos seleccionados. ¿Continuar?'))return;
      const input=$('attachments');
      if(!input){status('No se encontró la sección de adjuntos.');return;}
      const files=Array.from(input.files||[]);
      const exists=files.some(f=>f.name===source.name&&f.size===source.size&&f.lastModified===source.lastModified);
      if(!exists){
        if(files.length>=5){status('Ya tienes 5 adjuntos. Libera un espacio para conservar la cotización original.');return;}
        try{
          const dt=new DataTransfer();files.forEach(f=>dt.items.add(f));dt.items.add(source);
          input.files=dt.files;input.dispatchEvent(new Event('change'));
        }catch{status('Tu navegador no permite adjuntar automáticamente la cotización. Adjunta el archivo manualmente y vuelve a aplicar.');return;}
      }
      const totals=callbacks.apply(data);
      const expected=current.amounts.total;
      const difference=expected===undefined?null:round(totals.total-expected);
      status('Datos seleccionados importados; la cotización original está en Adjuntos. '+(difference===null?'No se detectó un total para comparar.':close(totals.total,expected)?'El total calculado coincide con el total leído.':'ATENCIÓN: diferencia de '+difference.toFixed(2)+' respecto al total leído. Corrige los datos antes de enviar.')+' Revisa toda la orden antes de guardar o enviar.');
      confirm.checked=false;change();
    });
  }
  const api={parseQuote,pdfLines,bind};
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
  else root.ODCQuoteScan=api;
})(typeof window!=='undefined'?window:globalThis);

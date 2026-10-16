// Run with: node tests/Frontend/fuel_dashboard_requests_test.cjs
// Execute the actual Twig inline script with controlled network responses and timers.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const template = fs.readFileSync(path.join(__dirname, '../../templates/administrateur/carburant/dashboard.html.twig'), 'utf8');
const script = template.slice(template.lastIndexOf('<script>') + 8, template.lastIndexOf('</script>'))
  .replace(/\{\{\s*start\s*\}\}/g, '2026-01-01').replace(/\{\{\s*end\s*\}\}/g, '2026-12-31')
  .replace(/\{\{[\s\S]*?\}\}/g, '/summary');
new vm.Script(script);
const timers = new Map(), elements = new Map(), events = [], requests = [];
let nextTimer = 0, tableRequests = 0;
function element(id) {
  const classes = new Set();
  return { id, value:'', checked:true, textContent:'', hidden:false, disabled:false, style:{}, listeners:{},
    classList:{add(...v){v.forEach(x=>classes.add(x));},remove(...v){v.forEach(x=>classes.delete(x));},contains:v=>classes.has(v),toggle(v,force){ if(force ?? !classes.has(v)) classes.add(v); else classes.delete(v); }},
    addEventListener(name, callback){ (this.listeners[name] ??= []).push(callback); },
    appendChild(){}, querySelector(){return null;}, closest(){return null;}
  };
}
const provider = element('provider'); provider.value='ALX';
const label = element('provider-label');label.textContent='ALX'; label.querySelector=()=>provider;provider.closest=()=>label;
function queryAll(selector) {
  if(selector === '#provList label') return [label];
  if(selector === '.fuel-filter-prov') return [provider];
  if(selector === '.fuel-filter-prov:checked') return provider.checked ? [provider] : [];
  return [];
}
const document = {
  getElementById(id){ if(!elements.has(id)) elements.set(id, element(id));return elements.get(id); },
  querySelectorAll:queryAll, querySelector(){return null;},createElement:element
};
document.getElementById('fStart').value='2026-01-01'; document.getElementById('fEnd').value='2026-12-31';
const jquery = selector => ({
  on(types, selector, fn){ events.push({types,selector,fn});return this; }, val(){return this;},trigger(){return this;},
  DataTable(){tableRequests++; return {ajax:{reload(){tableRequests++;}}};}
});
function flatpickr(el, options){ return {setDate(dates, trigger){ if(trigger) options.onChange(dates); }}; }
flatpickr.l10ns={fr:{}};flatpickr.localize=()=>{};
const context = {document, $:jquery, flatpickr, Chart:class{destroy(){}},console,Date,Number,String,Intl,URLSearchParams,
  setTimeout(fn){ const id=++nextTimer;timers.set(id,fn);return id; },clearTimeout(id){timers.delete(id);},
  fetch(url){return new Promise((resolve,reject)=>requests.push({url,resolve,reject}));},
  window:{flatpickr,matchMedia:()=>({matches:false}),addEventListener(){}},CSS:{escape:s=>s}};
async function settle(){ for(let i=0;i<12;i++) await Promise.resolve(); }
async function flushTimers(){const pending=[...timers.values()];timers.clear();pending.forEach(fn=>fn());await settle();}
async function resolveRequest(index, amount){ requests[index].resolve({ok:true,json:async()=>({kpis:{amount_cents:amount,cnt:1},charts:{}})});await settle(); }
function click(id){document.getElementById(id).listeners.click.forEach(fn=>fn({preventDefault(){}}));}
function search(value){ const event = events.find(e=>e.types==='input' && e.selector==='input[data-filter="prov"]');event.fn.call({value}); }
(async()=>{
  vm.runInNewContext(script,context);
  assert.equal(requests.length,1,'Initial page starts exactly one summary');
  await flushTimers();
  assert.equal(requests.length,1,'No delayed duplicate summary');
  await resolveRequest(0,12300);
  assert.equal(tableRequests,1,'Initial table makes exactly one request');
  assert.equal(document.getElementById('fuelLoadStatus').hidden,true);
  search('ALX');await flushTimers();assert.equal(requests.length,1,'Matching text without selection changes does not refetch');
  search('TOTAL');await flushTimers();assert.equal(requests.length,2,'Search that unchecks a filter fetches');
  assert.equal(provider.checked,false);
  const previousAmount=document.getElementById('kpiAmount').textContent;
  document.getElementById('periodYear').value='2025';
  document.getElementById('periodYear').listeners.change.forEach(fn=>fn());
  await flushTimers();assert.equal(requests.length,2,'At most one summary in flight');
  await resolveRequest(1,99900);
  assert.equal(document.getElementById('kpiAmount').textContent,previousAmount,'Old filtered response is discarded');
  await flushTimers();assert.equal(requests.length,3,'Latest filter request follows');
  assert.match(requests[2].url,/2025-01-01/);
  await resolveRequest(2,77700);
  assert.equal(tableRequests,2,'Only current filtered result refreshes table');
  click('btnReset');await flushTimers();assert.equal(requests.length,4,'Reset has one request despite flatpickr events');
  await resolveRequest(3,12300);assert.equal(tableRequests,3);
  click('btnRefreshTop');requests[4].reject(new Error('HTTP failure'));await settle();
  assert.match(document.getElementById('fuelLoadStatus').textContent,/Actualiser/);
  assert.equal(document.getElementById('btnRefreshTop').disabled,false,'Failed fetch allows retry');
  click('btnRefreshTop');await resolveRequest(5,12300);
  assert.equal(document.getElementById('fuelLoadStatus').hidden,true,'Retry clears error');
  console.log('PASS: JavaScript syntax; one initial summary/table request; filter search semantics; in-flight queue; stale response ignored; reset deduplication; error/retry.');
})().catch(e=>{console.error(e);process.exitCode=1;});

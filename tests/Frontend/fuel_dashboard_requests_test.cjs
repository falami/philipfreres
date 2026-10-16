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
const timers = new Map(), elements = new Map(), events = [], requests = [], charts = new Map(), chartInstances = [];
let nextTimer = 0, tableRequests = 0, mobileViewport = false;
const windowListeners = {};
function element(id) {
  const classes = new Set(), attributes = new Map();
  return { id, tagName:'DIV', value:'', checked:true, textContent:'', hidden:false, disabled:false, style:{}, listeners:{},
    classList:{add(...v){v.forEach(x=>classes.add(x));},remove(...v){v.forEach(x=>classes.delete(x));},contains:v=>classes.has(v),toggle(v,force){ if(force ?? !classes.has(v)) classes.add(v); else classes.delete(v);return classes.has(v); }},
    setAttribute(name,value){attributes.set(name,String(value));},getAttribute(name){return attributes.get(name) ?? null;},
    addEventListener(name, callback){ (this.listeners[name] ??= []).push(callback); },
    appendChild(){}, querySelector(){return null;},
    matches(selector){return selector.split(',').some(tag=>tag.trim().toUpperCase()===this.tagName);},
    closest(selector){return this.matches(selector) ? this : null;}
  };
}
const provider = element('provider'); provider.value='ALX';
const label = element('provider-label');label.textContent='ALX'; label.querySelector=()=>provider;provider.closest=()=>label;
const providers = [provider];
const employee = element('employee');employee.value='42';
const otherEmployee = element('other-employee');otherEmployee.value='43';
const employees = [employee, otherEmployee];
const fuels = ['gasoil','gnr'].map(value => Object.assign(element(value), {value}));
function queryAll(selector) {
  if(selector === '#provList label') return [label];
  if(selector === '.fuel-filter-prov') return providers;
  if(selector === '.fuel-filter-prov:checked') return providers.filter(item=>item.checked);
  if(selector === '.fuel-filter-sous') return fuels;
  if(selector === '.fuel-filter-sous:checked') return fuels.filter(item=>item.checked);
  if(selector === '.fuel-filter-emp') return employees;
  if(selector === '.fuel-filter-emp:checked') return employees.filter(item=>item.checked);
  return [];
}
const document = {
  getElementById(id){ if(!elements.has(id)) elements.set(id, element(id));return elements.get(id); },
  querySelectorAll:queryAll,
  querySelector(selector){
    const match=selector.match(/^(\.fuel-filter-(?:prov|emp|sous))\[value="([^"]+)"\]$/);
    return match ? queryAll(match[1]).find(item=>item.value===match[2]) || null : null;
  },createElement:element
};
document.getElementById('fStart').value='2026-01-01'; document.getElementById('fEnd').value='2026-12-31';
const jquery = selector => ({
  on(types, selector, fn){ events.push({types,selector,fn});return this; }, val(){return this;},trigger(){return this;},
  DataTable(){tableRequests++; return {ajax:{reload(){tableRequests++;}}};}
});
function flatpickr(el, options){ return {setDate(dates, trigger){ if(trigger) options.onChange(dates); }}; }
flatpickr.l10ns={fr:{}};flatpickr.localize=()=>{};
class Chart {
  constructor(canvas, config){
    this.data=config.data;this.options=config.options;this.config=config;
    this.updates=[];this.destroyed=false;
    charts.set(canvas.id,this);chartInstances.push(this);
  }
  update(mode){this.updates.push(mode);}
  destroy(){this.destroyed=true;}
}
const context = {document, $:jquery, flatpickr, Chart,console,Date,Number,String,Intl,URLSearchParams,
  setTimeout(fn){ const id=++nextTimer;timers.set(id,fn);return id; },clearTimeout(id){timers.delete(id);},
  fetch(url){return new Promise((resolve,reject)=>requests.push({url,resolve,reject}));},
  window:{flatpickr,matchMedia:query=>({matches:query==='(max-width: 576px)' && mobileViewport}),
    addEventListener(name,callback){(windowListeners[name] ??= []).push(callback);}},CSS:{escape:s=>s}};
async function settle(){ for(let i=0;i<12;i++) await Promise.resolve(); }
async function flushTimers(){const pending=[...timers.values()];timers.clear();pending.forEach(fn=>fn());await settle();}
async function resolveRequest(index, amount, chartData={}){ requests[index].resolve({ok:true,json:async()=>({kpis:{amount_cents:amount,cnt:1},charts:chartData})});await settle(); }
function click(id){document.getElementById(id).listeners.click.forEach(fn=>fn({preventDefault(){}}));}
function search(value){ const event = events.find(e=>e.types==='input' && e.selector==='input[data-filter="prov"]');event.fn.call({value}); }
(async()=>{
  vm.runInNewContext(script,context);
  assert.equal(requests.length,1,'Initial page starts exactly one summary');
  await flushTimers();
  assert.equal(requests.length,1,'No delayed duplicate summary');
  await resolveRequest(0,12300);
  assert.equal(tableRequests,1,'Initial table makes exactly one request');
  const initialCharts=[...chartInstances];
  assert.equal(initialCharts.length,5,'Only five distinct charts are created');
  assert.equal(charts.has('chEmpProvAmount'),false,'Duplicate employee/provider amount chart is absent');
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
  click('btnRefreshTop');await resolveRequest(5,12300,{
    byEmployeeProviderQty:[{label:'Employé de test',filter_id:42,alx:20,total:10,edenred:7,note:3}],
    byEmployeeFuelQty:[{label:'Employé de test',filter_id:42,sous:'gasoil',sous_label:'Gasoil',v:25},{label:'Employé de test',filter_id:42,sous:'gnr',sous_label:'GNR',v:15}]
  });
  assert.equal(document.getElementById('fuelLoadStatus').hidden,true,'Retry clears error');

  const extraProvider=element('other-provider');extraProvider.value='EDENRED';providers.push(extraProvider);
  const employeeChart=charts.get('chEmpProvQty');
  assert.equal(employeeChart.data.datasets[0].data[0],25,'Reused chart receives new quantities');
  for (const chartId of ['chEmp','chEmpProvQty']) {
    const chart = charts.get(chartId);
    assert.equal(chart.options.scales.x.stacked,true);
    assert.equal(chart.options.scales.y.stacked,true);
    assert.equal(chart.data.datasets[1].label,chartId === 'chEmp' ? 'TOTAL' : 'GNR');
    assert.equal(chart.data.datasets[1].data[0],chartId === 'chEmp' ? 10 : 15);
  }
  const selectedProvidersBeforeClick = providers.filter(item=>item.checked).map(item=>item.value);
  employeeChart.options.onClick({},[{index:0,datasetIndex:0}],employeeChart);
  await flushTimers();
  assert.equal(requests.length,7,'A chart click combines its filters into one request');
  const clickedFilters=new URLSearchParams(requests[6].url.split('?')[1]);
  assert.deepEqual(clickedFilters.getAll('employeIds[]'),['42'],'Employee chart selects the clicked employee');
  assert.deepEqual(clickedFilters.getAll('sousCategorieProduits[]'),['gasoil'],'Employee chart selects the clicked fuel');
  assert.deepEqual(clickedFilters.getAll('providers[]'),selectedProvidersBeforeClick,'Fuel click preserves provider selection');
  await resolveRequest(6,2500);

  employeeChart.options.onClick({},[],employeeChart);
  await flushTimers();
  assert.equal(requests.length,8,'Clicking chart background resets filters without duplicate requests');
  assert.ok(fuels.every(item=>item.checked) && employees.every(item=>item.checked),'Chart reset restores employee and fuel selections');
  await resolveRequest(7,12300);
  assert.equal(chartInstances.length,initialCharts.length,'Filter changes reuse existing Chart instances');
  assert.ok(initialCharts.every(chart=>!chart.destroyed && chart.updates.length>0),'No chart is destroyed during refresh');
  assert.ok(initialCharts.every(chart=>chart.updates.every(mode=>mode==='none')),'Updates do not restart chart animations');

  // A pointer activation focuses the button before the click bubbles. Focusing
  // this button must not pre-open the panel and let the following click close it.
  const toolbar=document.getElementById('fuelToolbar');
  const filtersButton=document.getElementById('btnFiltersMobile');filtersButton.tagName='BUTTON';
  mobileViewport=true;
  windowListeners.resize.forEach(callback=>callback());
  assert.equal(toolbar.classList.contains('is-open'),false,'Entering mobile starts with filters collapsed');
  assert.equal(filtersButton.getAttribute('aria-expanded'),'false');
  toolbar.listeners.focusin.forEach(callback=>callback({target:filtersButton}));
  assert.equal(toolbar.classList.contains('is-open'),false,'Button focus alone does not open filters');
  click('btnFiltersMobile');
  assert.equal(toolbar.classList.contains('is-open'),true,'First mobile click opens filters after button focus');
  assert.equal(filtersButton.getAttribute('aria-expanded'),'true');
  click('btnFiltersMobile');
  assert.equal(toolbar.classList.contains('is-open'),false,'Second mobile click closes filters');
  assert.equal(filtersButton.getAttribute('aria-expanded'),'false');
  const field=document.getElementById('periodYear');field.tagName='SELECT';
  toolbar.listeners.focusin.forEach(callback=>callback({target:field}));
  assert.equal(toolbar.classList.contains('is-open'),true,'Focusing an actual filter field opens filters');
  assert.equal(filtersButton.getAttribute('aria-expanded'),'true');
  assert.equal(requests.length,8,'Opening and closing filters does not request data');
  console.log('PASS: JavaScript syntax; unique initial requests; filter search; in-flight queue; stale response ignored; reset deduplication; error/retry; chart filter interactions; five charts reused without animation; mobile filter focus/click behavior and aria state.');
})().catch(e=>{console.error(e);process.exitCode=1;});

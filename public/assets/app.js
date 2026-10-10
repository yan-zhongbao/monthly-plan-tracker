'use strict';
const $ = id => document.getElementById(id);
const categories = ['成长','健康','家庭','财务','自我实现','社交','娱乐','职业'];
const categoryEn = ['GROWTH','HEALTH','FAMILY','FINANCE','SELF-FULFILLMENT','SOCIAL','LEISURE','CAREER'];
const dateParts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()).map(p=>[p.type,p.value]));
const today = `${dateParts.year}-${dateParts.month}-${dateParts.day}`;
let month = today.slice(0,7), data = {items:[],records:[]}, recordMap = new Map(), view = 'tracker';
let activeRecord = null, activeUnplanned = false, loadId = 0, busyCells = new Set(), loading=false;
let historySuggestions=[],suggestionRequest=0;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const dateFor = day => `${month}-${String(day).padStart(2,'0')}`;
const daysInMonth = () => new Date(Number(month.slice(0,4)),Number(month.slice(5)),0).getDate();
const keyFor = (id,date) => `${id}:${date}`;
const getRecord = (id,date) => recordMap.get(keyFor(id,date));
const dayCount = id => data.records.filter(r=>r.item_id===id && r.completed).length;
function symbolFor(item){
  if(item.symbol)return item.symbol;
  const presets=[['羽毛球','🏸'],['跑步','🏃'],['力量','💪'],['公积金','公'],['社保','社'],['对账','对'],['财务月报','¥'],['月计划','❀'],['生日','🎂']];
  return presets.find(([name])=>item.title.includes(name))?.[1] || (item.once_only?Array.from(item.title)[0]:'✓');
}
function markHtml(item){
  const symbol=symbolFor(item);
  if(symbol==='❀')return `<svg class="activity-mark" viewBox="0 0 24 24" aria-hidden="true">${Array.from({length:8},(_,i)=>`<ellipse cx="12" cy="5" rx="2.4" ry="4" transform="rotate(${i*45} 12 12)" fill="none" stroke="currentColor"/>`).join('')}<circle cx="12" cy="12" r="2" fill="currentColor"/></svg>`;
  return `<span class="activity-mark ${item.once_only&&Array.from(symbol).length===1?'circle-mark':''}">${esc(symbol)}</span>`;
}
const readOnly = () => !!data.access?.read_only;
const dateReadOnly = date => readOnly() || date>today;
function renderAccess(){
  $('month-review').checked=!!data.access?.review_completed;
  $('month-review').disabled=!data.access?.can_review;
  $('month-access').textContent=data.access?.reason || (data.access?.can_review?'可编辑 · 勾选后锁定月计划和追踪记录':'可编辑 · 月份到来后可标记月回顾');
  document.querySelector('.review-bar').classList.toggle('locked',readOnly());
  for(const id of ['add-item','add-unplanned','copy-month'])$(id).disabled=readOnly();
  document.querySelectorAll('[data-category], [data-plan-check], #empty-add').forEach(b=>b.disabled=readOnly());
  $('view-hint').textContent=readOnly()?'只读 · 点击查看详情，仍可导出':view==='tracker'?'轻点打勾 · 长按 / 右键添加备注':'八个方面可以留白 · 只有选中的项目进入追踪表';
}
function toast(message) { $('toast').textContent=message; $('toast').hidden=false; clearTimeout(toast.timer); toast.timer=setTimeout(()=>$('toast').hidden=true,4500); }
async function api(action,method='GET',payload=null,query={}) {
  const params = new URLSearchParams({action,...query});
  const response = await fetch(`api.php?${params}`, {method,credentials:'same-origin',headers: {'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:payload===null ? undefined : JSON.stringify(payload)});
  const result = await response.json();
  if (!response.ok) { if(response.status===401) location.reload(); throw new Error(result.error || '请求失败，请重试。'); }
  return result;
}
async function load(recenter=false) {
  const id=++loadId, requestedMonth=month;
  loading=true;
  $('month-review').disabled=true;
  $('save-state').textContent='读取中…';
  try {
    const result=await api('month','GET',null,{month:requestedMonth});
    if(id!==loadId) return;
    data=result; rebuildRecords(); render(recenter); $('save-state').textContent='已更新';
  } catch(e) { if(id===loadId){$('save-state').textContent='读取失败'; toast(e.message);} }
  finally{if(id===loadId)loading=false;}
}
function rebuildRecords(){recordMap=new Map(data.records.map(r=>[keyFor(r.item_id,r.date),r]));}
function render(recenter=false){
  $('month-title').textContent=`${month.slice(0,4)} 年 ${Number(month.slice(5))} 月`;
  $('mobile-month-title').textContent=`${month.slice(0,4)}年${Number(month.slice(5))}月`;
  $('month-subtitle').textContent=month===today.slice(0,7) ? 'THIS MONTH · 记录每一天' : 'MONTHLY NOTEBOOK · 一个月，一页记录';
  $('month-picker').value=month;
  renderTracker(); renderPlan(); renderAccess();
  if(recenter) requestAnimationFrame(()=>scrollToDate(month===today.slice(0,7)?Number(today.slice(8)):1));
}
function cellHtml(item,day){
  const date=dateFor(day), r=getRecord(item.id,date), weekday=new Date(`${date}T12:00:00`).getDay();
  const attrs=[weekday===1?'week-start':'',weekday===0||weekday===6?'weekend':'',date===today?'today':'',date>today?'future-date':''].join(' ');
  const detail=r?.note || r?.links?.length;
  const book=TrackerDisplay.bookTitle(item,r);
  return `<td class="${attrs}"><button class="cell-button ${r?.completed?'checked':''} ${book?'book-cell':''}" data-item="${item.id}" data-date="${date}" aria-pressed="${!!r?.completed}" aria-label="${esc(item.title)}，${day}日，${r?.completed?'已完成':'未标记'}${book?'，书名：'+esc(book):detail?'，有备注或链接':''}" title="${esc(item.title+(r?.note?'：'+r.note:'；点击标记；长按或右键添加备注'))}">${book?`<span class="book-title">${TrackerDisplay.escape(book)}</span>`:r?.completed?markHtml(item):''}${detail&&!book?'<span class="cell-note"></span>':''}</button></td>`;
}
function groupHtml(label,items){
  if(!items.length)return '';
  const days=daysInMonth();
  let html=label?`<tr class="category-row"><th class="name-cell" scope="row">${label}</th><th colspan="${days+1}"></th></tr>`:'';
  for(const item of items){
    const count=dayCount(item.id);
    html+=`<tr class="tracking-row"><th class="name-cell" scope="row"><button class="row-title" data-edit="${item.id}" title="编辑项目">${esc(item.title)}</button></th>${Array.from({length:days},(_,i)=>cellHtml(item,i+1)).join('')}<td class="sum-cell"><span class="sum-value">${count}</span>${item.target?` / ${item.target}`:' 天'}</td></tr>`;
  }
  return html;
}
function renderTracker(){
  const left=$('table-scroll').scrollLeft, days=daysInMonth(), items=data.items.filter(i=>i.tracked&&!i.once_only&&!isOutputItem(i)), events=data.items.filter(i=>i.once_only&&!isOutputItem(i));
  const weekdays=['日','一','二','三','四','五','六'];
  let html=`<colgroup><col class="name-col">${Array.from({length:days},()=>'<col class="day-col">').join('')}<col class="sum-col"></colgroup><thead><tr><th class="name-cell">追踪项目</th>`;
  for(let day=1;day<=days;day++){
    const date=dateFor(day),weekday=new Date(`${date}T12:00:00`).getDay();
    html+=`<th scope="col" class="${weekday===1?'week-start':''} ${weekday===0||weekday===6?'weekend':''} ${date===today?'today':''}"><span class="day-number">${day}</span>周${weekdays[weekday]}</th>`;
  }
  html+='<th class="sum-cell">完成 / 目标</th></tr></thead><tbody>';
  if(!items.length&&!events.length&&!outputEntries().length) html+=`<tr><td colspan="${days+2}" class="empty-row"><p>这个月还是一张空白页。<br>添加需要每天打勾的项目，或沿用上月计划。</p><button class="primary" id="empty-add">＋ 添加追踪项目</button></td></tr>`;
  else{
    html+=groupHtml('',items.filter(i=>!i.unplanned));
    html+=groupHtml('计划外记录',items.filter(i=>i.unplanned));
  }
  html+=`<tr class="tracking-row event-row"><th class="name-cell" scope="row">一次性事件</th>${Array.from({length:days},(_,i)=>{
    const date=dateFor(i+1),done=events.filter(item=>getRecord(item.id,date)?.completed);
    return `<td class="${date===today?'today':''}"><div class="event-cell">${done.map(item=>`<button class="event-mark" data-event-item="${item.id}" data-date="${date}" title="${esc(item.title+(getRecord(item.id,date)?.note?'：'+getRecord(item.id,date).note:''))}" aria-label="${esc(item.title)}，查看详情">${markHtml(item)}</button>`).join('')}<button class="event-add" data-event-date="${date}" aria-label="${i+1}日添加一次性事件" title="选择当天完成的事项">＋</button></div></td>`;
  }).join('')}<td class="sum-cell">${events.filter(i=>dayCount(i.id)>0).length} / ${events.length}</td></tr>`;
  html+=outputRow(days);
  $('tracker-table').innerHTML=html+'</tbody>';
  bindOutputs();
  $('tracker-table').querySelectorAll('.tracking-row').forEach((row,index)=>row.classList.toggle('stripe',index%2===1));
  document.querySelectorAll('[data-event-date]').forEach(b=>{b.disabled=dateReadOnly(b.dataset.eventDate);b.onclick=()=>openEvents(b.dataset.eventDate);if(b.dataset.eventDate>today)b.title='未来日期只读';});
  document.querySelectorAll('[data-event-item]').forEach(b=>b.onclick=()=>openRecord(Number(b.dataset.eventItem),b.dataset.date));
  $('tracker-summary').textContent=`${items.filter(i=>!i.unplanned).length} 个追踪项目 · ${events.length} 个一次性事件 · ${items.filter(i=>i.unplanned).length} 个计划外事项`;
  sizeTable(); $('table-scroll').scrollLeft=left;
  document.querySelectorAll('.cell-button').forEach(bindCell);
  document.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>openItem(Number(b.dataset.edit)));
  if($('empty-add'))$('empty-add').onclick=()=>openItem(null,false,true);
}
function sizeTable(){
  const table=$('tracker-table'),width=$('table-scroll').clientWidth;
  const mobile=window.matchMedia('(max-width:640px)').matches;
  const name=mobile?116:window.innerWidth>=1600?210:180, sum=mobile?70:90;
  const cell=mobile ? Math.max(45,(width-name)/3) : Math.max(27,(width-name-sum)/daysInMonth());
  table.style.width=`${name+cell*daysInMonth()+sum}px`;
  table.querySelector('.name-col').style.width=`${name}px`;
  table.querySelector('.sum-col').style.width=`${sum}px`;
  table.querySelectorAll('.day-col').forEach(c=>c.style.width=`${cell}px`);
  table.dataset.cellWidth=String(cell);
}
function scrollToDate(day){
  sizeTable();
  const mobile=window.matchMedia('(max-width:640px)').matches;
  $('table-scroll').scrollTo({left:Math.max(0,(day-(mobile?2:1))*Number($('tracker-table').dataset.cellWidth)),behavior:'smooth'});
}
function bindCell(button){
  let timer,startX,startY,suppressed=false;
  const open=()=>{suppressed=true;openRecord(Number(button.dataset.item),button.dataset.date);};
  button.addEventListener('pointerdown',event=>{
    if(event.button!==0)return;
    suppressed=false;startX=event.clientX;startY=event.clientY;
    timer=setTimeout(open,550);
  });
  button.addEventListener('pointermove',event=>{if(Math.abs(event.clientX-startX)>8||Math.abs(event.clientY-startY)>8){clearTimeout(timer);suppressed=true;}});
  for(const event of ['pointerup','pointercancel','pointerleave'])button.addEventListener(event,()=>clearTimeout(timer));
  button.addEventListener('contextmenu',event=>{event.preventDefault();clearTimeout(timer);open();});
  button.addEventListener('click',event=>{if(suppressed){suppressed=false;return;}if(event.shiftKey||button.classList.contains('book-cell')){open();return;}toggleRecord(Number(button.dataset.item),button.dataset.date);});
  button.addEventListener('keydown',event=>{if(event.key==='F2'){event.preventDefault();open();}});
}
async function toggleRecord(itemId,date){
  if(loading||data.month!==month)return;
  if(dateReadOnly(date)){openRecord(itemId,date);return;}
  const key=keyFor(itemId,date); if(busyCells.has(key))return;
  const record=getRecord(itemId,date);
  busyCells.add(key); $('save-state').textContent='保存中…';
  const button=document.querySelector(`[data-item="${itemId}"][data-date="${date}"]`);
  if(button){button.disabled=true;button.classList.add('busy');}
  try{
    const result=await api('records','PUT',{item_id:itemId,date,completed:!record?.completed,revision:record?.revision??0});
    updateRecord(result.record);$('save-state').textContent='已保存';
  }catch(e){toast(e.message);await load();}
  finally{busyCells.delete(key);if(button){button.disabled=false;button.classList.remove('busy');}}
}
function updateRecord(record){
  const index=data.records.findIndex(r=>r.item_id===record.item_id&&r.date===record.date);
  if(record.date.slice(0,7)!==month)return;
  if(index<0)data.records.push(record);else data.records[index]=record;
  rebuildRecords();renderTracker();renderPlan();renderAccess();
}
function planMoveHtml(item){
  if(!item.tracked||item.once_only||isOutputItem(item))return '';
  const peers=data.items.filter(i=>i.tracked&&!i.once_only&&!isOutputItem(i)&&i.category===item.category&&i.unplanned===item.unplanned);
  const index=peers.findIndex(i=>i.id===item.id);
  return `<span class="plan-move"><button type="button" data-move-item="${item.id}" data-direction="up" aria-label="上移${esc(item.title)}" title="上移" ${readOnly()||index===0?'disabled':''}>↑</button><button type="button" data-move-item="${item.id}" data-direction="down" aria-label="下移${esc(item.title)}" title="下移" ${readOnly()||index===peers.length-1?'disabled':''}>↓</button></span>`;
}
function renderPlan(){
  const plans=data.items.filter(i=>!i.unplanned), singles=plans.filter(i=>!i.tracked);
  $('plan-summary').textContent=`${plans.length} 项月计划 · ${plans.filter(i=>i.tracked&&!i.once_only).length} 项每日追踪 · ${plans.filter(i=>i.once_only).length} 项一次性事件 · ${singles.filter(i=>i.completed).length} / ${singles.length} 项独立事项已完成`;
  $('plan-grid').innerHTML=categories.map((category,index)=>{
    const items=plans.filter(i=>i.category===category);
    return `<article class="plan-card"><div class="plan-card-header"><h3>${category}</h3><span>${categoryEn[index]}</span></div>${items.length?items.map(item=>`<div class="plan-entry ${item.completed&&!item.tracked?'done':''}">${item.tracked?`<span class="plan-tracked-icon" title="${esc(item.once_only?'在底部一次性事件中标记':'在追踪表打勾')}">${markHtml(item)}</span>`:`<button class="plan-check ${item.completed?'checked':''}" data-plan-check="${item.id}" aria-label="标记${esc(item.title)}完成" aria-pressed="${item.completed}">${item.completed?'✓':''}</button>`}<div class="plan-entry-content"><button class="plan-entry-title" data-plan-edit="${item.id}">${esc(item.title)}</button>${item.note?`<div class="plan-entry-note">${esc(item.note)}</div>`:''}${item.tracked?`<span class="plan-badge">${item.once_only?`一次性事件 · ${dayCount(item.id)?'已完成':'待完成'}`:`每日追踪 · ${dayCount(item.id)}${item.target?' / '+item.target:''} 天`}</span>`:''}</div>${planMoveHtml(item)}</div>`).join(''):'<div class="plan-empty">这个方面暂时留白。</div>'}<button class="quiet" data-category="${category}">＋ 添加计划</button></article>`;
  }).join('');
  document.querySelectorAll('[data-plan-edit]').forEach(b=>{
    const item=data.items.find(i=>i.id===Number(b.dataset.planEdit));
    if(isOutputItem(item)){
      const entry=b.closest('.plan-entry');entry.querySelector('.plan-tracked-icon').innerHTML=outputMark('出');entry.querySelector('.plan-badge').textContent=outputSummary();
    } else if(item.once_only){
      const entry=b.closest('.plan-entry');
      entry.classList.toggle('done',dayCount(item.id)>0);
      entry.querySelector('.plan-tracked-icon').innerHTML=markHtml(item);
      entry.querySelector('.plan-badge').textContent=dayCount(item.id)>0?'一次性事件 · 已完成':'一次性事件 · 待完成';
    }
  });
  document.querySelectorAll('[data-move-item]').forEach(b=>b.onclick=async()=>{
    if(readOnly()||loading||data.month!==month)return;
    const id=Number(b.dataset.moveItem),direction=b.dataset.direction;
    const requestedMonth=month;loading=true;
    document.querySelectorAll('[data-move-item]').forEach(button=>button.disabled=true);
    try{
      const result=await api('move','PATCH',{id,direction});
      if(month===requestedMonth){data=result;rebuildRecords();render();toast('顺序已保存，月度追踪同步更新');}
    }catch(error){toast(error.message);await load();}
    finally{loading=false;if(month===requestedMonth)renderPlan();}
  });
  document.querySelectorAll('[data-plan-edit]').forEach(b=>b.onclick=()=>openItem(Number(b.dataset.planEdit)));
  document.querySelectorAll('[data-category]').forEach(b=>b.onclick=()=>openItem(null,false,false,b.dataset.category));
  document.querySelectorAll('[data-plan-check]').forEach(b=>b.onclick=async()=>{
    if(readOnly()||loading)return;
    const item=data.items.find(i=>i.id===Number(b.dataset.planCheck));b.disabled=true;
    try{await api('items','PATCH',{id:item.id,completed:!item.completed});await load();}catch(e){toast(e.message);b.disabled=false;}
  });
}
function switchView(next){
  view=next;$('tracker-view').hidden=view!=='tracker';$('plan-view').hidden=view!=='plan';
  for(const tab of ['tracker','plan']){$(`tab-${tab}`).classList.toggle('active',tab===view);$(`tab-${tab}`).setAttribute('aria-selected',String(tab===view));}
  $('view-hint').textContent=view==='tracker'?'轻点打勾 · 长按 / 右键添加备注':'八个方面可以留白 · 只有选中的项目进入追踪表';
  renderAccess();
  if(view==='tracker') {sizeTable();scrollToDate(month===today.slice(0,7)?Number(today.slice(8)):1);}
}
function openItem(id=null,unplanned=false,tracked=false,category=null){
  if(loading||data.month!==month)return;
  if(readOnly()&&!id)return;
  const item=data.items.find(i=>i.id===id);activeUnplanned=item?.unplanned??unplanned;
  $('item-form').reset();$('item-id').value=item?.id??'';
  $('item-dialog-title').textContent=item?'编辑项目':activeUnplanned?'添加计划外事项':'添加月计划';
  $('item-kicker').textContent=activeUnplanned?'UNPLANNED MOMENTS':'MONTHLY PLAN';
  $('item-title').value=item?.title??'';$('item-category').value=item?.category??category??'';
  $('item-note').value=item?.note??'';$('item-target').value=item?.target??'';
  $('item-tracked').checked=item?.tracked??(tracked||activeUnplanned);
  $('item-mode').value=item?.once_only?'once':$('item-tracked').checked?'daily':'plan';
  syncModeRadios();renderSymbolOptions(false);setSymbolPicker(false);$('choose-symbol').disabled=readOnly();
  $('item-symbol').value=item?.symbol??'';
  $('item-mode').disabled=readOnly();$('item-symbol').disabled=readOnly();
  $('tracked-label').hidden=true;$('target-label').hidden=activeUnplanned;
  $('item-target').disabled=$('item-mode').value==='once';
  $('item-help').textContent=activeUnplanned?'计划外事项会放在追踪表底部，不计入原月计划。':$('item-mode').value==='once'?'每月只完成一次，合并到追踪表底部。同一天可放多个符号。':$('item-mode').value==='daily'?'每天单独标记，可填写目标天数。':'仅月计划的事项可单独标记完成；填写目标天数会自动加入每日追踪。';
  $('delete-item').hidden=!item;$('item-dialog').showModal();
  $('suggestion-toggle').hidden=!!item;
  if(item){suggestionRequest++;historySuggestions=[];setSuggestionOpen(false);$('suggestion-status').textContent='';$('item-title').disabled=false;$('item-title').placeholder='项目名称';$('item-title').focus();}
  else{loadSuggestions();$('item-category').focus();}
  if(readOnly())$('item-dialog-title').textContent='查看项目 · 只读';
  for(const field of ['item-category','item-note','item-tracked'])$(field).disabled=readOnly();
  $('item-title').disabled=readOnly()||(!item&&!$('item-category').value);
  $('item-target').disabled=readOnly()||$('item-mode').value==='once';
  $('item-save').hidden=readOnly();$('delete-item').hidden=readOnly()||!item;
}
function setSuggestionOpen(open){$('item-suggestions').hidden=!open;$('suggestion-toggle').setAttribute('aria-expanded',String(open));}
function renderSuggestions(){
  const needle=$('item-title').value.trim().toLocaleLowerCase();
  const items=historySuggestions.filter(i=>i.title.toLocaleLowerCase().includes(needle));
  $('item-suggestions').innerHTML=items.length?items.map(i=>`<button type="button" data-history-title="${esc(i.title)}"><span>${esc(i.title)}</span><small>最近用于 ${esc(i.last_used_month)}</small></button>`).join(''):'<div class="suggestion-empty">暂无匹配的历史项目，可以直接输入新名称。</div>';
  $('item-suggestions').querySelectorAll('[data-history-title]').forEach(b=>b.onclick=()=>{$('item-title').value=b.dataset.historyTitle;$('item-title').focus();setSuggestionOpen(false);});
}
async function loadSuggestions(){
  const request=++suggestionRequest,category=$('item-category').value,requestedMonth=month;
  historySuggestions=[];setSuggestionOpen(false);
  $('item-title').disabled=!category;$('suggestion-toggle').disabled=!category;
  $('item-title').placeholder=category?'选择历史项目，或输入新名称':'先选择所属方面';
  if(!category){$('suggestion-status').textContent='选择方面后，列出最近用过且本月尚未添加的项目。';return;}
  if($('item-id').value){$('suggestion-status').textContent='';return;}
  $('suggestion-status').textContent='正在读取历史项目…';
  try{
    const result=await api('suggestions','GET',null,{month:requestedMonth,category});
    if(request!==suggestionRequest||requestedMonth!==month||!$('item-dialog').open)return;
    historySuggestions=result.items;renderSuggestions();
    $('suggestion-status').textContent=historySuggestions.length?`${historySuggestions.length} 个历史项目，最近使用的排在前面。`:'这个方面暂时没有可复用的历史项目，直接输入新名称即可。';
    setSuggestionOpen(historySuggestions.length>0);
  }catch(e){if(request===suggestionRequest){$('suggestion-status').textContent='历史项目暂时无法读取，仍可输入新名称。';toast(e.message);}}
}
function openRecord(itemId,date){
  if(loading||data.month!==month)return;
  if(busyCells.has(keyFor(itemId,date)))return;
  const item=data.items.find(i=>i.id===itemId),record=getRecord(itemId,date);
  activeRecord={itemId,date,revision:record?.revision??0};
  $('record-title').textContent=item.title;$('record-date').textContent=date.replaceAll('-',' / ');
  $('record-completed').checked=!!record?.completed;$('record-note').value=record?.note??'';
  $('record-note-label').textContent=TrackerDisplay.isReading(item)?'书名（填书名表示读完；只打勾表示读过）':'补充备注';
  $('record-note').placeholder=TrackerDisplay.isReading(item)?'例如：《悉达多》；有书名时直接显示在格子里':'书名、朋友名字，或想留住的一点细节…';
  $('record-links').value=(record?.links??[]).join('\n');$('record-lock').checked=record?.source==='manual' ? record.manual_lock : true;
  $('record-source').textContent=record?`来源：${record.source==='manual'?'手动记录':'OpenClaw'} · 修改时间：${new Date(record.updated_at).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai'})}`:'可以只写备注，也可以一起标记完成。';
  $('record-link-list').innerHTML=(record?.links??[]).map((link,i)=>`<a href="${esc(link)}" target="_blank" rel="noopener noreferrer">打开详情 ${i+1} ↗</a>`).join('');
  for(const field of ['record-completed','record-lock'])$(field).disabled=dateReadOnly(date);
  for(const field of ['record-note','record-links'])$(field).readOnly=dateReadOnly(date);
  $('record-save').hidden=dateReadOnly(date);
  if(date>today)$('record-source').textContent+=' · 未来日期只读';
  if(readOnly())$('record-source').textContent+=' · 本月只读';
  $('record-dialog').showModal();setTimeout(()=>$('record-note').focus(),50);
}
function openEvents(date){
  if(loading||data.month!==month)return;
  $('event-date').textContent=date.replaceAll('-',' / ');
  const items=data.items.filter(i=>i.once_only);
  $('event-list').innerHTML=items.length?items.map(item=>{
    const completed=data.records.find(r=>r.item_id===item.id&&r.completed),onDate=completed?.date===date;
    return `<div class="event-choice"><button data-event-toggle="${item.id}" ${dateReadOnly(date)||completed&&!onDate?'disabled':''}>${markHtml(item)} ${esc(item.title)} <small>${completed?onDate?'✓ 当天已完成':completed.date+' 已完成':date>today?'未来日期只读':'点击完成'}</small></button><button class="quiet" data-event-detail="${item.id}">详情</button></div>`;
  }).join(''):'<p class="field-help">暂无一次性事件。在月计划中添加项目，记录方式选择“一次性事件”。</p>';
  $('event-list').querySelectorAll('[data-event-toggle]').forEach(b=>b.onclick=async()=>{b.disabled=true;await toggleRecord(Number(b.dataset.eventToggle),date);if($('event-dialog').open)openEvents(date);});
  $('event-list').querySelectorAll('[data-event-detail]').forEach(b=>b.onclick=()=>{const id=Number(b.dataset.eventDetail),done=data.records.find(r=>r.item_id===id&&r.completed);$('event-dialog').close();openRecord(id,done?.date??date);});
  if(!$('event-dialog').open)$('event-dialog').showModal();
}
async function submitItem(event){
  if(readOnly()){event.preventDefault();return;}
  event.preventDefault();const id=Number($('item-id').value)||null;
  const mode=$('item-mode').value;
  const payload={month,title:$('item-title').value.trim(),category:$('item-category').value,note:$('item-note').value,tracked:activeUnplanned||mode!=='plan',once_only:mode==='once',symbol:$('item-symbol').value.trim(),unplanned:activeUnplanned,target:!activeUnplanned&&mode==='daily'&&$('item-target').value?Number($('item-target').value):null};
  if(id)payload.id=id;else{payload.sort_order=data.items.reduce((max,item)=>Math.max(max,item.sort_order),0)+10;payload.avoid_duplicate=true;}
  const button=event.submitter;button.disabled=true;
  try{await api('items',id?'PATCH':'POST',payload);$('item-dialog').close();await load();toast('项目已保存');}catch(e){toast(e.message);}finally{button.disabled=false;}
}
async function submitRecord(event){
  if(dateReadOnly(activeRecord.date)){event.preventDefault();return;}
  event.preventDefault();const button=event.submitter;button.disabled=true;
  try{
    const result=await api('records','PUT',{item_id:activeRecord.itemId,date:activeRecord.date,revision:activeRecord.revision,completed:$('record-completed').checked,note:$('record-note').value,links:$('record-links').value.split('\n').map(s=>s.trim()).filter(Boolean),manual_lock:$('record-lock').checked});
    updateRecord(result.record);$('record-dialog').close();$('save-state').textContent='已保存';toast('记录已保存');
  }catch(e){toast(e.message);}finally{button.disabled=false;}
}
function download(blob,filename){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename;a.click();setTimeout(()=>URL.revokeObjectURL(url),10000);}
function csvText(){
  const days=daysInMonth(),rows=[['项目','方面','类型','符号','计划说明','独立事项已完成','目标天数',...Array.from({length:days},(_,i)=>dateFor(i+1)),'完成天数']];
  for(const item of data.items){rows.push([item.title,item.category,item.once_only?'一次性事件':item.unplanned?'计划外':item.tracked?'每日追踪':'月计划',symbolFor(item),item.note,!item.tracked&&item.completed?'✓':'',item.target??'',...Array.from({length:days},(_,i)=>{const r=getRecord(item.id,dateFor(i+1));return r?[r.completed?'✓':'',r.note,...r.links].filter(Boolean).join(' | '):'';}),item.tracked?dayCount(item.id):'']);}
  for(const output of data.outputs??[]){rows.push([output.title,'自我实现','输出 · '+data.output_types[output.type],outputLetter(output),output.note,'','',...Array.from({length:days},(_,i)=>output.date===dateFor(i+1)?['✓',...output.links].join(' | '):''),'1 篇']);}
  return '\ufeff'+rows.map(row=>row.map(v=>'"'+String(v).replaceAll('"','""')+'"').join(',')).join('\r\n');
}
function svgText(){
  const width=1600,name=190,sum=90,days=daysInMonth(),cell=(width-80-name-sum)/days;
  let parts=[],y=126;
  const text=(x,y,value,size=15,color='#3b5542',extra='')=>parts.push(`<text x="${x}" y="${y}" font-size="${size}" fill="${color}" ${extra}>${esc(value)}</text>`);
  const rect=(x,y,w,h,fill)=>parts.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fill}"/>`);
  const line=(x1,y1,x2,y2)=>parts.push(`<path d="M${x1} ${y1} L${x2} ${y2}" stroke="#e0e4d5"/>`);
  const wrap=(value,n=86)=>{const chars=Array.from(value);return Array.from({length:Math.ceil(chars.length/n)},(_,i)=>chars.slice(i*n,(i+1)*n).join(''));};
  text(40,48,'月度计划与追踪',28);text(40,82,`${month.slice(0,4)} 年 ${Number(month.slice(5))} 月`,20);text(width-40,80,'MY MONTHLY NOTEBOOK',13,'#8b9684','text-anchor="end"');
  rect(40,y,width-80,52,'#eef1e4');text(52,y+31,'追踪项目',14);
  for(let d=1;d<=days;d++){const x=40+name+(d-1)*cell;const weekday=['日','一','二','三','四','五','六'][new Date(`${dateFor(d)}T12:00:00`).getDay()];text(x+cell/2,y+22,d,15,'#647a5d','text-anchor="middle"');text(x+cell/2,y+40,weekday,10,'#8c9982','text-anchor="middle"');}
  text(width-40-sum/2,y+31,'完成 / 目标',12,'#647a5d','text-anchor="middle"');y+=52;
  const tracked=data.items.filter(i=>i.tracked&&!i.once_only&&!isOutputItem(i));
  const groups=[['',tracked.filter(i=>!i.unplanned)],['计划外记录',tracked.filter(i=>i.unplanned)]];
  for(const [label,items] of groups){if(!items.length)continue;if(label){rect(40,y,width-80,28,'#f1f3e9');text(52,y+19,label,12);y+=28;}
    for(const item of items){
      const books=Array.from({length:days},(_,i)=>TrackerDisplay.bookTitle(item,getRecord(item.id,dateFor(i+1))));
      const height=books.some(Boolean)?54:38;rect(40,y,width-80,height,tracked.indexOf(item)%2?'#eef1e7':'#fffdf8');text(52,y+26,item.title,14);
      for(let d=1;d<=days;d++){const r=getRecord(item.id,dateFor(d)),x=40+name+(d-1)*cell,book=books[d-1];if(r?.completed){rect(x,y,cell,height,'#edf2e3');if(book){const lines=wrap(book.replaceAll('\n',' / '),3).slice(0,3);if(Array.from(book).length>9)lines[2]=lines[2].slice(0,2)+'…';lines.forEach((s,n)=>text(x+cell/2,y+16+n*14,s,10,'#557a53','text-anchor="middle"'));}else{text(x+cell/2,y+26,symbolFor(item),20,'#557a53','text-anchor="middle"');}}if(!book&&(r?.note||r?.links?.length))text(x+cell-5,y+height-6,'•',11,'#b39462');line(x,y,x,y+height);}
      text(width-40-sum/2,y+25,`${dayCount(item.id)}${item.target?' / '+item.target:' 天'}`,14,'#557a53','text-anchor="middle"');line(40,y+height,width-40,y+height);y+=height;
    }
  }
  const events=data.items.filter(i=>i.once_only&&!isOutputItem(i));
  if(events.length){
    const eventsByDay=Array.from({length:days},(_,i)=>events.filter(item=>getRecord(item.id,dateFor(i+1))?.completed));
    const height=Math.max(38,...eventsByDay.map(items=>items.length*26+8));
    rect(40,y,width-80,height,'#eef1e7');text(52,y+26,'一次性事件',14);
    eventsByDay.forEach((items,index)=>items.forEach((item,n)=>{
      const x=40+name+(index+.5)*cell,sy=y+22+n*26,symbol=symbolFor(item);
      if(Array.from(symbol).length===1)parts.push(`<circle cx="${x}" cy="${sy-5}" r="10" fill="none" stroke="#557a53"/>`);
      text(x,sy,symbol,13,'#557a53','text-anchor="middle"');
    }));
    text(width-40-sum/2,y+25,`${events.filter(i=>dayCount(i.id)>0).length} / ${events.length}`,14,'#557a53','text-anchor="middle"');y+=height;
    for(const segment of wrap(events.map(i=>`${symbolFor(i)} = ${i.title}`).join('；'))){y+=22;text(40,y,segment,12);}
  }
  const outputsByDay=Array.from({length:days},(_,i)=>outputEntries().filter(o=>o.date===dateFor(i+1)));
  const outputHeight=Math.max(38,...outputsByDay.map(entries=>entries.length*26+8));
  rect(40,y,width-80,outputHeight,'#eef1e7');text(52,y+26,'输出',14);
  outputsByDay.forEach((entries,index)=>entries.forEach((output,n)=>{const x=40+name+(index+.5)*cell,sy=y+22+n*26;parts.push(`<circle cx="${x}" cy="${sy-5}" r="10" fill="none" stroke="#557a53"/>`);text(x,sy,outputLetter(output),13,'#557a53','text-anchor="middle"');}));
  text(width-40-sum/2,y+25,String((data.outputs??[]).length)+' 篇',14,'#557a53','text-anchor="middle"');y+=outputHeight;
  text(40,y+22,'读 = 读书笔记；文 = 文章；旅 = 旅行记录；出 = 原有输出记录',12);y+=30;
  for(const output of data.outputs??[]){for(const segment of wrap(`${output.date} · ${data.output_types[output.type]} · ${output.title}${output.note?'：'+output.note:''}${output.links.length?' · '+output.links.join(' · '):''}`)){y+=22;text(40,y,segment,13);}}
  y+=35;text(40,y,'月计划',21);y+=18;
  for(const category of categories){const items=data.items.filter(i=>!i.unplanned&&i.category===category);if(!items.length)continue;y+=29;text(40,y,category,14,'#617957');for(const item of items){const summary=`${item.tracked?'▦':item.completed?'✓':'○'} ${item.title}${item.tracked?` · 已完成 ${dayCount(item.id)}${item.target?' / '+item.target:''} 天`:''}${item.note?' — '+item.note:''}`;for(const segment of wrap(summary)){y+=24;text(65,y,segment,14);}}}
  const details=data.records.filter(r=>r.note||r.links.length).sort((a,b)=>a.date.localeCompare(b.date));
  if(details.length){y+=45;text(40,y,'记录备注与详情',21);for(const r of details){const item=data.items.find(i=>i.id===r.item_id);const content=`${r.date} · ${item.title}${r.note?'：'+r.note:''}${r.links.length?' · '+r.links.join(' · '):''}`;for(const segment of wrap(content.replaceAll('\n',' / '))){y+=24;text(40,y,segment,13,'#6c7f64');}}}
  y+=40;text(40,y,'空白表示尚未标记。图片为导出时的月度快照。',12,'#96a18d');y+=35;
  return {height:y,xml:`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${y}" viewBox="0 0 ${width} ${y}"><rect width="100%" height="100%" fill="#fffdf8"/><g font-family="Microsoft YaHei, PingFang SC, sans-serif">${parts.join('')}</g></svg>`};
}
async function exportMonth(type){
  if(loading||data.month!==month){toast('请先成功读取当月数据，再导出。');return;}
  try{
    const filename=`${month}-月度计划与追踪`;
    if(type==='json')download(new Blob([JSON.stringify(data,null,2)],{type:'application/json;charset=utf-8'}),filename+'.json');
    if(type==='csv')download(new Blob([csvText()],{type:'text/csv;charset=utf-8'}),filename+'.csv');
    if(type==='print'){switchView('tracker');window.print();}
    if(type==='png'){
      const svg=svgText();if(svg.height>16000)throw new Error('记录较多，请使用 CSV 或 JSON 导出完整内容。');
      const url=URL.createObjectURL(new Blob([svg.xml],{type:'image/svg+xml;charset=utf-8'}));
      try{const image=new Image();await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(new Error('图片生成失败，请使用表格导出。'));image.src=url;});
        const canvas=document.createElement('canvas');canvas.width=1600;canvas.height=svg.height;canvas.getContext('2d').drawImage(image,0,0);
        const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));if(!blob)throw new Error('图片生成失败。');download(blob,filename+'.png');
      }finally{URL.revokeObjectURL(url);}
    }
    $('export-dialog').close();
  }catch(e){toast(e.message);}
}
$('item-category').innerHTML='<option value="" disabled>请选择所属方面</option>'+categories.map(c=>`<option>${c}</option>`).join('');
$('item-category').onchange=()=>{if(!$('item-id').value)$('item-title').value='';loadSuggestions();};
$('suggestion-toggle').onclick=()=>{renderSuggestions();setSuggestionOpen($('item-suggestions').hidden);};
$('item-title').onfocus=()=>{if(!$('item-id').value&&historySuggestions.length){renderSuggestions();setSuggestionOpen(true);}};
$('item-title').oninput=()=>{if(!$('item-id').value&&$('item-category').value){renderSuggestions();setSuggestionOpen(true);}};
$('item-title').onkeydown=e=>{if(e.key==='Escape'&&!$('item-suggestions').hidden){e.preventDefault();e.stopPropagation();setSuggestionOpen(false);}if(e.key==='ArrowDown'&&!$('item-suggestions').hidden){e.preventDefault();$('item-suggestions').querySelector('button')?.focus();}};
$('tab-tracker').onclick=()=>switchView('tracker');$('tab-plan').onclick=()=>switchView('plan');
function changeMonth(next){if(!/^20\d{2}-(0[1-9]|1[0-2])$/.test(next)){toast('支持 2000—2099 年。');return;}document.querySelectorAll('dialog[open]').forEach(d=>d.close());suggestionRequest++;month=next;load(true);}
function offsetMonth(offset){const d=new Date(`${month}-01T12:00:00`);d.setMonth(d.getMonth()+offset);changeMonth(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`);}
$('prev-month').onclick=()=>offsetMonth(-1);$('next-month').onclick=()=>offsetMonth(1);$('month-picker').onchange=e=>changeMonth(e.target.value);
$('go-today').onclick=()=>{if(month!==today.slice(0,7))changeMonth(today.slice(0,7));else scrollToDate(Number(today.slice(8)));};
$('month-review').onchange=async()=>{
  if(loading||data.month!==month){$('month-review').checked=!!data.access?.review_completed;return;}
  const requestedMonth=month,completed=$('month-review').checked;
  $('month-review').disabled=true;
  try{await api('review','PATCH',{month:requestedMonth,completed});await load();toast(completed?'已锁定本月，月计划和追踪记录只读。':'已取消月回顾标记，可以继续修改。');}
  catch(e){toast(e.message);await load();}
};
$('add-item').onclick=()=>openItem(null,false,view==='tracker');$('add-unplanned').onclick=()=>openItem(null,true,true);
$('item-tracked').onchange=()=>{$('item-target').disabled=!$('item-tracked').checked;};
$('item-mode').onchange=()=>{
  syncModeRadios();
  $('item-tracked').checked=$('item-mode').value!=='plan';
  $('item-target').disabled=$('item-mode').value==='once';
  $('item-help').textContent=$('item-mode').value==='once'?'每月只完成一次，合并到追踪表底部。同一天可放多个符号。':$('item-mode').value==='daily'?'每天单独打勾，可填写目标天数。':'只在月计划中标记完成；填写目标天数会自动切换为每日追踪。';
};
$('item-target').oninput=()=>{if($('item-target').value&&$('item-mode').value==='plan'){$('item-mode').value='daily';$('item-mode').onchange();}};
function syncModeRadios(){document.querySelectorAll('[name="record-mode"]').forEach(r=>{r.checked=r.value===$('item-mode').value;r.disabled=readOnly();});}
document.querySelectorAll('[name="record-mode"]').forEach(r=>r.onchange=()=>{if(r.checked){$('item-mode').value=r.value;$('item-mode').onchange();}});
function setSymbolPicker(open){$('symbol-picker').hidden=!open;$('choose-symbol').setAttribute('aria-expanded',String(open));}
$('choose-symbol').onclick=()=>setSymbolPicker($('symbol-picker').hidden);
const symbolGroups=[['常用','✓','○','★','♥','❀','社','公','对','¥'],['运动与健康','🏃','🏸','💪','🚶','🚴','🏊','🧘','⚽','🏀','🎾','🥾','😴','💊','🥗'],['学习与创作','📚','📖','✍️','🎓','🧠','🎧','🗣️','🎹','🎨','💻','📷','🎬'],['生活与事项','🎂','🎁','👪','🤝','☕','🍽️','✈️','🏠','🌱','🛒','💰','🧾','🎮','🎵','📝','📅']];
const moreSymbolGroups=[
  ['运动与拉伸','🧘','🤸','⛸️','⛷️','🏂','🛼','🛹','🏃','🚶','🚴','🏊','💪','🏋️','🤾','🤽','🤺','🏇','🧗','🏄','🚣','🏌️','🏸','🏓','🎾','⚽','🏀','🏐','🏈','🏉','⚾','🥎','🏒','🏑','🏏','🥍','🥊','🥋','🎿','🛷','🥌','🎯','🪁','🤿'],
  ['表情与感受','😀','😃','😄','😁','😆','😊','🙂','😉','😍','🥰','😘','😎','🤓','🤩','🥳','🤔','🤗','🤭','🤫','😌','😔','🥺','😢','😭','😤','😠','😱','😮','😅','😴','🤤','😷','🤒','🤕','🤯','😇'],
  ['手势与心意','👍','👎','👏','🙌','🤲','🙏','✌️','🤞','👌','🤟','🤙','👋','✋','👊','✊','🫶','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💖','💝','💕','💯','💫','✨','🔥'],
  ['动物与植物','🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🦉','🦋','🐝','🐢','🐬','🐳','🐙','🌸','🌼','🌻','🌷','🌹','🌺','🍀','🌿','🌳','🌲','🌵'],
  ['饮食与烹饪','🍎','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍒','🍑','🥭','🍍','🥝','🥑','🥦','🥕','🌽','🥔','🍞','🥐','🥯','🧀','🥚','🍳','🥞','🥩','🍗','🍕','🍔','🍣','🍜','🍚','🥟','🍰','🍪','🍫','🍵','🧋','🥛','🍷'],
  ['旅行与风景','🚗','🚕','🚌','🚆','🚄','🚢','⛵','🚀','🛫','🛬','🧳','🗺️','🧭','🏕️','⛺','🏖️','🏝️','🏔️','⛰️','🌋','🏜️','🏞️','🌅','🌄','🌇','🌃','🌉','🏙️','☀️','🌙','⭐','🌈','☁️','🌧️','❄️','🌊'],
  ['工作与工具','📋','📌','📍','📎','🗂️','📁','📂','📊','📈','📉','🗓️','⏰','⏱️','⌛','🔔','📞','📱','⌨️','🖥️','🖨️','🔍','💡','🔧','🔨','🪛','🧰','⚙️','🔑','🔒','📦','✉️','📮','🪙','💳','🏦','✅','☑️','🎯'],
  ['爱好与庆祝','🎤','🎸','🎻','🥁','🎺','🎷','🪕','🎼','🎭','🎪','🎟️','🎫','🎞️','📺','📻','🧩','♟️','🎲','🎳','🎱','🏓','🏐','🏈','🥊','🥋','🎣','⛷️','🏂','🛹','🛼','🏆','🥇','🥈','🥉','🎉','🎊','🎈','🪄'],
  ['生活与整理','🛏️','🛋️','🪑','🚿','🛁','🧼','🪥','🧹','🧺','🪣','🧽','🧯','🪴','💐','🕯️','🪞','👕','👔','👗','👟','👓','🧢','🎒','👜','☂️','💍','💎','🧸','👶','🍼','🩺','🩹','🦷','🧬','⚖️','♻️'],
  ['语言与国家','🇨🇳','🇬🇧','🇺🇸','🇫🇷','🇪🇸','🇩🇪','🇯🇵','🇰🇷','🇮🇹','🇵🇹','🇧🇷','🇨🇦','🇦🇺','🇳🇿','🇸🇬','🇹🇭','🇮🇳','🇳🇱','🇸🇪','🇨🇭']
];
const sportSymbolNames={'🧘':'瑜伽 / 放松','🤸':'体操 / 拉伸','⛸️':'滑冰','⛷️':'滑雪','🏂':'单板滑雪','🛼':'轮滑','🛹':'滑板','🏃':'跑步','🚶':'步行','🚴':'骑行','🏊':'游泳','💪':'力量训练','🏋️':'举重','🤾':'手球','🤽':'水球','🤺':'击剑','🏇':'骑马','🧗':'攀岩','🏄':'冲浪','🚣':'划船','🏌️':'高尔夫','🏸':'羽毛球','🏓':'乒乓球','🎾':'网球','⚽':'足球','🏀':'篮球','🏐':'排球','🏈':'美式橄榄球','🏉':'橄榄球','⚾':'棒球','🥎':'垒球','🏒':'冰球','🏑':'曲棍球','🏏':'板球','🥍':'长曲棍球','🥊':'拳击','🥋':'武术','🎿':'滑雪装备','🛷':'雪橇','🥌':'冰壶','🎯':'飞镖','🪁':'风筝','🤿':'潜水'};
function renderSymbolOptions(more){
  const groups=more?[symbolGroups[0],moreSymbolGroups[0],...symbolGroups.slice(1),...moreSymbolGroups.slice(1)]:symbolGroups;
  $('symbol-options').innerHTML=groups.map(([name,...symbols])=>`<div class="symbol-group-title">${name}</div>${symbols.map(s=>`<button type="button" data-symbol="${esc(s)}" aria-label="选择符号 ${esc(s)}${sportSymbolNames[s]?' '+esc(sportSymbolNames[s]):''}" title="${esc(sportSymbolNames[s]||s)}">${s==='❀'?markHtml({symbol:s,once_only:false}):esc(s)}</button>`).join('')}`).join('');
  $('symbol-options').querySelectorAll('[data-symbol]').forEach(b=>b.onclick=()=>{$('item-symbol').value=b.dataset.symbol;setSymbolPicker(false);});
  $('more-symbols').textContent=more?'收起更多图标':'更多图标';$('more-symbols').setAttribute('aria-expanded',String(more));
}
$('more-symbols').onclick=()=>renderSymbolOptions($('more-symbols').getAttribute('aria-expanded')!=='true');
renderSymbolOptions(false);
$('symbol-auto').onclick=()=>{$('item-symbol').value='';setSymbolPicker(false);};
$('item-form').onsubmit=submitItem;$('record-form').onsubmit=submitRecord;
$('record-note').oninput=()=>{const item=data.items.find(i=>i.id===activeRecord?.itemId);if(TrackerDisplay.isReading(item)&&!$('record-note').readOnly&&$('record-note').value.trim())$('record-completed').checked=true;};
$('delete-item').onclick=async()=>{if(!confirm('删除这个项目及其所有每日记录？此操作无法在页面中撤销。'))return;try{await api('items','DELETE',{id:Number($('item-id').value)});$('item-dialog').close();await load();toast('项目已删除');}catch(e){toast(e.message);}};
$('copy-month').onclick=async()=>{
  if(loading||data.month!==month)return;
  if(data.items.length){toast('当月已有项目，请在空白月份沿用上月计划。');return;}
  const d=new Date(`${month}-01T12:00:00`);d.setMonth(d.getMonth()-1);const from=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
  try{await api('copy','POST',{from,to:month});await load(true);toast('已沿用上月计划；每日勾选与计划外事项不会复制。');}catch(e){toast(e.message);}
};
$('export-open').onclick=()=>$('export-dialog').showModal();document.querySelectorAll('[data-export]').forEach(b=>b.onclick=()=>exportMonth(b.dataset.export));
document.querySelectorAll('.close-dialog').forEach(b=>b.onclick=()=>b.closest('dialog').close());
window.addEventListener('resize',()=>{clearTimeout(sizeTable.timer);sizeTable.timer=setTimeout(()=>{const previous=Number($('tracker-table').dataset.cellWidth),left=$('table-scroll').scrollLeft;sizeTable();if(previous)$('table-scroll').scrollLeft=left/previous*Number($('tracker-table').dataset.cellWidth);},100);});
// Move the existing controls rather than duplicate IDs or event handlers.
const mobileLayout=window.matchMedia('(max-width:640px)');
function placeMobileControls(){
  const controls=$('primary-controls'),menu=$('mobile-menu');
  if(mobileLayout.matches){$('mobile-controls-host').append(controls);}
  else{if(menu.open)menu.close();$('tracker-view').before(controls);}
}
$('mobile-menu-open').onclick=()=>{$('mobile-menu').showModal();};
$('mobile-go-today').onclick=()=>{$('mobile-menu').close();switchView('tracker');$('go-today').click();};
$('primary-controls').addEventListener('click',e=>{
  if(e.target.closest('#add-item,#copy-month,#export-open,#tab-tracker,#tab-plan')&&$('mobile-menu').open)$('mobile-menu').close();
},true);
mobileLayout.addEventListener('change',placeMobileControls);placeMobileControls();
window.addEventListener('beforeprint',()=>{$('tracker-view').before($('primary-controls'));});
window.addEventListener('afterprint',placeMobileControls);
let activeOutput=null;
function isOutputItem(item){return item.title==='输出'&&item.tracked&&!item.unplanned;}
function outputLetter(output){return output.legacy?'出':Array.from((data.output_types??{})[output.type]??'输出')[0];}
function outputMark(letter){return markHtml({title:'输出',symbol:letter,once_only:true});}
function outputEntries(){return [...(data.outputs??[]),...(data.legacy_outputs??[]).map(r=>({...r,legacy:true,title:r.completed?'输出完成':'旧输出备注（未完成）'}))];}
function outputSummary(){const old=(data.legacy_outputs??[]).filter(r=>r.completed).length;return `输出 · ${(data.outputs??[]).length} 篇${old?' · 旧记录 '+old+' 天':''}`;}
function outputRow(days){return `<tr class="tracking-row event-row output-row"><th class="name-cell" scope="row">输出</th>${Array.from({length:days},(_,index)=>{
  const date=dateFor(index+1),entries=outputEntries().filter(o=>o.date===date);
  return `<td class="${date===today?'today':''} ${date>today?'future-date':''}"><div class="event-cell">${entries.map(o=>`<button class="event-mark" ${o.legacy?`data-output-legacy="${o.item_id}"`:`data-output-id="${o.id}"`} data-date="${date}" title="${esc((o.legacy?'旧记录':data.output_types[o.type])+'：'+o.title+(o.note?'；'+o.note:''))}" aria-label="${esc(o.title)}，查看输出">${outputMark(outputLetter(o))}</button>`).join('')}<button class="event-add" data-output-date="${date}" aria-label="${index+1}日添加输出" ${dateReadOnly(date)?'disabled':''} title="${date>today?'未来日期只读':'添加一篇输出，可添加多篇'}">＋</button></div></td>`;
}).join('')}<td class="sum-cell" title="${esc(outputSummary())}">${(data.outputs??[]).length} 篇${(data.legacy_outputs??[]).some(r=>r.completed)?'<span class="row-target">＋旧记录</span>':''}</td></tr>`;}
function bindOutputs(){
  document.querySelectorAll('[data-output-date]').forEach(b=>b.onclick=()=>openOutput(null,b.dataset.outputDate));
  document.querySelectorAll('[data-output-id]').forEach(b=>b.onclick=()=>openOutput(Number(b.dataset.outputId),b.dataset.date));
  document.querySelectorAll('[data-output-legacy]').forEach(b=>b.onclick=()=>openRecord(Number(b.dataset.outputLegacy),b.dataset.date));
}
function openOutput(id,date){
  if(loading||data.month!==month)return;
  const entry=(data.outputs??[]).find(o=>o.id===id);activeOutput=entry??null;
  date=entry?.date??date;
  if(!entry&&dateReadOnly(date))return;
  $('output-form').reset();$('output-heading').textContent=entry?'输出详情':'添加输出';
  $('output-date').value=date;$('output-date').max=today;$('output-type').value=entry?.type??'book_note';
  $('output-title').value=entry?.title??'';$('output-note').value=entry?.note??'';$('output-links').value=(entry?.links??[]).join('\n');
  $('output-lock').checked=entry?.manual_lock??true;
  $('output-link-list').innerHTML=(entry?.links??[]).map((link,i)=>`<a href="${esc(link)}" target="_blank" rel="noopener noreferrer">打开输出详情 ${i+1} ↗</a>`).join('');
  for(const field of ['output-date','output-type','output-title','output-note','output-links','output-lock'])$(field).disabled=dateReadOnly(date);
  $('output-save').hidden=dateReadOnly(date);$('output-remove').hidden=!entry||dateReadOnly(date);
  $('output-info').textContent=dateReadOnly(date)?'只读，可以打开详情链接。':'同一天可添加多篇，同类型也可重复添加。移除后可恢复。';
  $('output-dialog').showModal();
}
$('output-form').onsubmit=async e=>{
  e.preventDefault();if(readOnly())return;
  const button=e.submitter;button.disabled=true;
  const payload={date:$('output-date').value,type:$('output-type').value,title:$('output-title').value.trim(),note:$('output-note').value,links:$('output-links').value.split('\n').map(v=>v.trim()).filter(Boolean),manual_lock:$('output-lock').checked};
  if(activeOutput){payload.id=activeOutput.id;payload.revision=activeOutput.revision;}
  try{await api('outputs',activeOutput?'PATCH':'POST',payload);$('output-dialog').close();await load();toast('输出已保存');}catch(error){toast(error.message);}finally{button.disabled=false;}
};
$('output-remove').onclick=async()=>{
  if(!activeOutput||readOnly())return;const b=$('output-remove');b.disabled=true;
  try{await api('outputs','DELETE',{id:activeOutput.id,revision:activeOutput.revision});$('output-dialog').close();await load();toast('输出已移除，可在“已移除输出”恢复');}catch(error){toast(error.message);}finally{b.disabled=false;}
};
$('output-archives').onclick=async()=>{
  const requestedMonth=month;
  try{const result=await api('outputs','GET',null,{month:requestedMonth,include_archived:'true'});if(month!==requestedMonth)return;
    const entries=result.outputs.filter(o=>o.archived);
    $('output-archive-list').innerHTML=entries.length?entries.map(o=>`<div class="event-choice"><span>${esc(o.date)} · ${esc(o.title)}</span><button class="quiet" data-restore-output="${o.id}" ${dateReadOnly(o.date)?'disabled':''}>恢复</button></div>`).join(''):'<p class="field-help">当前月没有已移除输出。</p>';
    $('output-archive-list').querySelectorAll('[data-restore-output]').forEach(b=>b.onclick=async()=>{b.disabled=true;const entry=entries.find(o=>o.id===Number(b.dataset.restoreOutput));try{await api('outputs','PATCH',{id:entry.id,revision:entry.revision,archived:false});$('output-archive-dialog').close();await load();toast('输出已恢复');}catch(error){toast(error.message);b.disabled=false;}});
    $('output-archive-dialog').showModal();
  }catch(error){toast(error.message);}
};

load(true);

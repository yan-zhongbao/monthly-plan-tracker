import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const block=source.slice(source.indexOf('function canLiveRefresh('),source.indexOf('async function load('));
let checks=0;
const check=(actual,expected)=>{assert.deepEqual(actual,expected);checks++;};
function harness(){
 const elements={'table-scroll':{scrollLeft:123,scrollTop:87},'plan-grid':{scrollLeft:42},'save-state':{textContent:'已保存'}};
 const baseline={month:'2026-10',items:[{id:1}],records:[]};
 const ctx=vm.createContext({month:'2026-10',today:'2026-10-12',data:baseline,loadId:1,writeEpoch:0,pendingWrites:0,loading:false,busyCells:new Set(),liveRefreshBusy:false,lastLiveRefresh:0,livePointerDown:false,liveInteractionUntil:0,Date:{now:()=>100000},AbortController,JSON,CSS:{escape:s=>s},navigator:{onLine:true},document:{hidden:false,activeElement:{matches:()=>false,hasAttribute:()=>false},querySelector:selector=>selector==='dialog[open]'?ctx.dialog:null,addEventListener:(event,fn)=>events.set('document:'+event,fn)},window:{scrollX:0,scrollY:36,scrollTo:(x,y)=>{ctx.position=[x,y];},addEventListener:(event,fn)=>events.set('window:'+event,fn)},$:id=>elements[id],currentToday:()=>ctx.nextToday??'2026-10-12',rebuildRecords:()=>{ctx.rebuilds++;},render:()=>{ctx.renders++;elements['table-scroll'].scrollLeft=0;elements['table-scroll'].scrollTop=0;elements['plan-grid'].scrollLeft=0;},setTimeout:()=>1,clearTimeout:()=>{},setInterval:(fn,ms)=>{ctx.interval=ms;ctx.tick=fn;}});
 const events=new Map();ctx.renders=0;ctx.rebuilds=0;ctx.requests=0;ctx.dialog=null;
 ctx.api=async(action,method,payload,query,options)=>{ctx.requests++;ctx.options=options;ctx.query=query;return ctx.nextData??baseline;};
 vm.runInContext(block,ctx);return {ctx,elements,events,baseline};
}
{
 const {ctx,elements}=harness();ctx.nextData={...ctx.data,records:[{id:1,completed:true}]};await ctx.refreshLive();
 check(ctx.renders,1);check(ctx.data.records[0].completed,true);check(elements['save-state'].textContent,'已自动更新');
 check([elements['table-scroll'].scrollLeft,elements['table-scroll'].scrollTop,elements['plan-grid'].scrollLeft],[123,87,42]);check(ctx.position,[0,36]);check(ctx.options.background,true);
}
{
 const {ctx,elements}=harness();await ctx.refreshLive();check(ctx.renders,0);check(elements['save-state'].textContent,'已保存');
}
for(const kind of ['dialog','input','hidden','offline','loading','write','cell','pointer','interaction']){
 const {ctx}=harness();
 if(kind==='dialog')ctx.dialog={draft:'未保存备注'};
 if(kind==='input')ctx.document.activeElement.matches=()=>true;
 if(kind==='hidden')ctx.document.hidden=true;
 if(kind==='offline')ctx.navigator.onLine=false;
 if(kind==='loading')ctx.loading=true;
 if(kind==='write')ctx.pendingWrites=1;
 if(kind==='cell')ctx.busyCells.add('1:2026-10-12');
 if(kind==='pointer')ctx.livePointerDown=true;
 if(kind==='interaction')ctx.liveInteractionUntil=100001;
 await ctx.refreshLive();check(ctx.requests,0);
}
for(const kind of ['writeEpoch','loadId','month','dialog']){
 const {ctx,baseline}=harness();let resolve;ctx.api=()=>new Promise(done=>resolve=done);const pending=ctx.refreshLive();
 if(kind==='writeEpoch')ctx.writeEpoch++;
 if(kind==='loadId')ctx.loadId++;
 if(kind==='month')ctx.month='2026-11';
 if(kind==='dialog')ctx.dialog={draft:'未保存书名'};
 resolve({...baseline,records:[{id:2}]});await pending;check(ctx.renders,0);check(ctx.data,baseline);
}
{
 const {ctx}=harness();let resolve;ctx.api=()=>{ctx.requests++;return new Promise(done=>resolve=done);};
 const first=ctx.refreshLive();await ctx.refreshLive();check(ctx.requests,1);resolve(ctx.data);await first;check(ctx.liveRefreshBusy,false);
}
{
 const {ctx,elements}=harness();ctx.api=async()=>{throw new Error('offline');};await ctx.refreshLive();check(elements['save-state'].textContent,'同步暂时中断，稍后自动重试');check(ctx.renders,0);
}
{
 const {ctx,elements}=harness();ctx.api=async()=>{const error=new Error('expired');error.status=401;throw error;};await ctx.refreshLive();check(elements['save-state'].textContent,'登录已失效，请刷新后登录');
}
{
 const {ctx,elements}=harness();elements['save-state'].textContent='同步暂时中断，稍后自动重试';await ctx.refreshLive();check(elements['save-state'].textContent,'已更新');
}
{
 const {ctx}=harness();ctx.nextToday='2026-10-13';await ctx.refreshLive();check(ctx.today,'2026-10-13');check(ctx.month,'2026-10');check(ctx.renders,1);
}
{
 const {ctx,events}=harness();ctx.startLiveRefresh();check(ctx.interval,15000);check(events.has('document:visibilitychange'),true);check(events.has('window:online'),true);check(events.has('window:focus'),true);
 events.get('window:pointerdown')();check(ctx.livePointerDown,true);events.get('window:blur')();check(ctx.livePointerDown,false);
}
console.log(`PASS: ${checks} live-refresh update, draft/race protection, foreground/offline, midnight and scroll assertions.`);

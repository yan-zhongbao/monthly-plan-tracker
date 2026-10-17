import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const block=source.slice(source.indexOf('async function exportMonth('),source.indexOf("$('item-category').innerHTML="));
let checks=0;const check=(a,b)=>{assert.deepEqual(a,b);checks++;};
function make(){
 const buttons=[{disabled:false},{disabled:false}];const dialog={open:true,close(){this.open=false;}};
 const saved=[],calls=[],messages=[];const latest={format:'monthly-plan-tracker/month-backup',format_version:1,month:'2026-10',data:{journals:[{content:'服务器最新日记'}]}};
 const ctx=vm.createContext({loading:false,month:'2026-10',data:{month:'2026-10',journals:[{content:'旧页面日记'}]},Blob,JSON,$:()=>dialog,document:{querySelectorAll:()=>buttons},toast:message=>messages.push(message),download:(blob,name)=>saved.push({blob,name}),api:async(...args)=>{calls.push(args);return latest;}});
 vm.runInContext(block,ctx);return {ctx,buttons,dialog,saved,calls,messages,latest};
}
{
 const {ctx,buttons,dialog,saved,calls,latest}=make();await ctx.exportMonth('json');
 check(calls[0][0],'export');check(calls[0][3].month,'2026-10');check(JSON.parse(await saved[0].blob.text()),latest);check(saved[0].name,'2026-10-月度计划与追踪-结构化备份.json');check(dialog.open,false);check(buttons.every(button=>!button.disabled),true);
}
{
 const {ctx,buttons,dialog,saved}=make();ctx.api=async()=>{throw new Error('断网');};await ctx.exportMonth('json');check(saved.length,0);check(dialog.open,true);check(buttons.every(button=>!button.disabled),true);check(ctx.exportMonth.busy,false);
}
{
 const {ctx,buttons,saved,latest}=make();let finish,count=0;ctx.api=()=>{count++;return new Promise(resolve=>finish=resolve);};const first=ctx.exportMonth('json');await ctx.exportMonth('json');check(count,1);check(buttons.every(button=>button.disabled),true);ctx.month='2026-11';finish(latest);await first;check(saved[0].name.startsWith('2026-10'),true);
}
console.log(`PASS: ${checks} fresh snapshot download, failure recovery and duplicate-click assertions.`);

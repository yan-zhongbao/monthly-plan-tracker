import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const block=source.slice(source.indexOf('let activeJournal='),source.indexOf("window.addEventListener('resize',schedulePlanLayout);"));
let checks=0;const check=(a,b)=>{assert.deepEqual(a,b);checks++;};
function make(){
 const elements=new Map();function element(id){if(!elements.has(id))elements.set(id,{value:'',readOnly:false,disabled:false,hidden:false,textContent:'',open:false,scrollTop:0,showModal(){this.open=true;},close(){this.open=false;},focus(){},addEventListener(name,fn){this[name]=fn;}});return elements.get(id);}
 const events=new Map();let calls=[];
 const ctx=vm.createContext({$:element,loading:false,month:'2026-10',data:{journals:[]},api:async(action,method,payload)=>{calls.push({action,method,payload});return method==='GET'?{journal:null,read_only:false,access:{}}:{journal:{date:payload.date,content:payload.content,revision:1}};},renderTracker(){},toast(){},window:{addEventListener:(name,fn)=>events.set(name,fn)}});
 vm.runInContext(block,ctx);return {ctx,element,events,calls};
}
{
 const {ctx,element}=make();await ctx.openJournal('2026-10-15');element('journal-content').value='草稿';ctx.requestJournalClose();
 check(element('journal-dialog').open,true);check(element('journal-unsaved').open,true);element('journal-stay').onclick();check(element('journal-dialog').open,true);check(element('journal-content').value,'草稿');
 ctx.requestJournalClose();element('journal-discard').onclick();check(element('journal-dialog').open,false);
}
{
 const {ctx,element,calls}=make();await ctx.openJournal('2026-10-15');element('journal-content').value='保存内容';ctx.requestJournalClose();await ctx.saveJournal();
 check(element('journal-dialog').open,false);check(ctx.data.journals[0].content,'保存内容');check(calls[1].payload.revision,0);
}
{
 const {ctx,element,events}=make();await ctx.openJournal('2026-10-15');element('journal-content').value='冲突草稿';ctx.api=async()=>{throw new Error('版本冲突');};await ctx.saveJournal();
 check(element('journal-dialog').open,true);check(element('journal-content').value,'冲突草稿');check(element('journal-content').readOnly,false);check(element('journal-status').textContent.includes('草稿仍保留'),true);
 let prevented=false;events.get('beforeunload')({preventDefault(){prevented=true;}});check(prevented,true);
}
{
 const {ctx,element}=make();ctx.api=async()=>({journal:{date:'2026-10-15',content:'只读内容',revision:2},read_only:true,access:{reason:'已锁定'}});await ctx.openJournal('2026-10-15');
 check(element('journal-content').readOnly,true);check(element('journal-save').hidden,true);ctx.requestJournalClose();check(element('journal-dialog').open,false);
}
{
 const {ctx,element}=make();let finish;ctx.api=()=>new Promise(resolve=>finish=resolve);const opening=ctx.openJournal('2026-10-15');ctx.requestJournalClose();finish({journal:null,read_only:false,access:{}});await opening;check(element('journal-dialog').open,false);
}
{
 const {ctx,element}=make();await ctx.openJournal('2026-10-15');element('journal-content').value='保存中';let finish;ctx.api=()=>new Promise(resolve=>finish=resolve);const saving=ctx.saveJournal();
 check(element('journal-content').readOnly,true);ctx.requestJournalClose();check(element('journal-dialog').open,true);finish({journal:{date:'2026-10-15',content:'保存中',revision:1}});await saving;check(element('journal-dialog').open,false);
}
console.log(`PASS: ${checks} journal unsaved/return/save/conflict/readonly/async-close assertions.`);
{
 const {ctx,element}=make();let text='';Object.defineProperty(element('journal-content'),'value',{get:()=>text,set:value=>{text=String(value).replaceAll('\r\n','\n');}});
 ctx.api=async()=>({journal:{date:'2026-10-15',content:'第一行\r\n第二行',revision:1},read_only:false,access:{}});
 await ctx.openJournal('2026-10-15');check(ctx.journalDirty(),false);ctx.requestJournalClose();check(element('journal-unsaved').open,false);
}
console.log('PASS: 2 Windows newline compatibility assertions.');

'use strict';
const ROOT=new URL('./',self.location.href);
const PREFIX='month-tracker-static-'+encodeURIComponent(ROOT.pathname)+'-';
const CACHE=PREFIX+'0.7.2';
const FILES=['assets/app.css?v=0.7.2','assets/app.js?v=0.7.2','assets/tracking-display.js?v=0.7.2','assets/pwa.js?v=0.7.2','assets/icon-192.png','assets/icon-512.png','assets/apple-touch-icon.png'].map(path=>new URL(path,ROOT).href);
const ALLOWED=new Set(FILES);
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(FILES)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith(PREFIX)&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  if(ALLOWED.has(request.url)){
    event.respondWith(caches.open(CACHE).then(async cache=>{const cached=await cache.match(request);if(cached)return cached;const response=await fetch(request);if(response.ok)await cache.put(request,response.clone());return response;}));
  }else if(request.mode==='navigate'&&new URL(request.url).origin===ROOT.origin){
    // HTML/session/API data never enter Cache Storage. No offline write queue.
    event.respondWith(fetch(request).catch(()=>new Response('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>暂时离线</title><h1>暂时离线</h1><p>连接网络后，重新打开月度计划与追踪。离线时不能查询或填写记录。</p></html>',{status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}})));
  }
});

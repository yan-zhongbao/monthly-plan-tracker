'use strict';
(() => {
  const dialog=document.getElementById('install-dialog'),confirm=document.getElementById('install-confirm'),status=document.getElementById('install-status');
  const entries=()=>document.querySelectorAll('[data-install-app]');
  let pending=null,knownInstalled=false;
  const standalone=window.matchMedia('(display-mode: standalone)');
  const installed=()=>knownInstalled||standalone.matches||navigator.standalone===true;
  const refresh=()=>{entries().forEach(b=>b.hidden=installed());confirm.hidden=!pending||installed();};
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();pending=event;refresh();status.textContent='浏览器已准备好，可以点击下面的按钮安装。';});
  window.addEventListener('appinstalled',()=>{knownInstalled=true;pending=null;status.textContent='已安装，可从桌面或应用列表打开。';refresh();});
  standalone.addEventListener('change',refresh);
  entries().forEach(b=>b.onclick=()=>{const menu=document.getElementById('mobile-menu');if(menu?.open)menu.close();refresh();dialog.showModal();});
  document.getElementById('install-close').onclick=()=>dialog.close();
  confirm.onclick=async()=>{
    const prompt=pending;if(!prompt)return;pending=null;confirm.disabled=true;
    try{await prompt.prompt();const choice=await prompt.userChoice;status.textContent=choice.outcome==='accepted'?'已接受安装，请稍候；完成后可从桌面打开。':'已取消安装，之后仍可从浏览器菜单添加。';}
    catch{status.textContent='暂时无法弹出安装窗口，请按下方浏览器菜单步骤添加。';}
    finally{confirm.disabled=false;refresh();}
  };
  refresh();
  if('serviceWorker' in navigator&&window.isSecureContext){navigator.serviceWorker.register('sw.js',{scope:'./',updateViaCache:'none'}).catch(()=>{status.textContent='安装准备暂时未完成，可以刷新页面，或按浏览器菜单步骤添加。';});}
})();

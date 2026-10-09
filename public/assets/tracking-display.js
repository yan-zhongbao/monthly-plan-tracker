'use strict';
// Presentation only: the stored note and completed flag remain authoritative.
globalThis.TrackerDisplay=Object.freeze({
  isReading(item){return !!item?.tracked&&!item.once_only&&String(item.title??'').startsWith('阅读');},
  bookTitle(item,record){return this.isReading(item)&&record?.completed?String(record.note??'').trim():'';},
  escape(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
});

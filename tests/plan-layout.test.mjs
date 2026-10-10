import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const context=vm.createContext({});
vm.runInContext(source.slice(source.indexOf('function planColumns('),source.indexOf('function layoutPlan(')),context);
let checks=0;
function check(heights,limit,expected){assert.deepEqual(JSON.parse(JSON.stringify(context.planColumns(heights,limit))),expected);checks++;}
// Growth fills the first column; Health and Social must not fall below the visible bottom.
check([518,300,240,220,150,150,120,120],650,[[0],[1,2],[3,4,5],[6,7]]);
check([518,300,240,220,150,150,120,120],500,[[0],[1],[2,3],[4,5,6],[7]]);
check([300,200],514,[[0,1]]);
check([300,200],513,[[0],[1]]);
check([800,100,100],500,[[0],[1,2]]);
check([100,800,100],500,[[0],[1],[2]]);
check([],500,[]);
check([100],500,[[0]]);
console.log(`PASS: ${checks} viewport card-packing assertions.`);
// Exercise desktop packing and mobile flattening with the actual DOM-layout function.
class Node {
  constructor(card=false,height=0){this.card=card;this.height=height;this.children=[];this.style={setProperty(k,v){this[k]=v;},removeProperty(k){delete this[k];}};}
  append(...nodes){for(const node of nodes){if(node.parent)node.parent.children=node.parent.children.filter(n=>n!==node);this.children.push(node);node.parent=this;}}
  replaceChildren(...nodes){for(const node of this.children)node.parent=null;this.children=[];this.append(...nodes);}
  querySelectorAll(){return this.children.flatMap(node=>node.card?[node]:node.querySelectorAll());}
  getBoundingClientRect(){return {top:340,height:this.height};}
}
const grid=new Node(),cards=[518,300,240,220,150,150,120,120].map(height=>new Node(true,height));
grid.clientWidth=1100;grid.scrollLeft=0;grid.append(...cards);
let mobile=false;
const domContext=vm.createContext({view:'plan',$:()=>grid,window:{scrollY:0,innerHeight:1000,matchMedia:()=>({matches:mobile})},document:{createElement:()=>new Node()}});
vm.runInContext(source.slice(source.indexOf('function planColumns('),source.indexOf('function schedulePlanLayout(')),domContext);
domContext.layoutPlan();assert.deepEqual(grid.children.map(column=>column.children.map(card=>cards.indexOf(card))),[[0],[1,2],[3,4,5],[6,7]]);checks++;
assert.deepEqual(grid.querySelectorAll(),cards);checks++;
assert.equal(grid.children.every(column=>column.className==='plan-column'),true);checks++;
mobile=true;domContext.layoutPlan();assert.deepEqual(grid.children,cards);checks++;
assert.equal(cards.every(card=>!card.style.width),true);checks++;
domContext.view='tracker';const previous=grid.children.slice();domContext.layoutPlan();assert.deepEqual(grid.children,previous);checks++;
console.log('PASS: 6 desktop/mobile DOM-layout assertions.');

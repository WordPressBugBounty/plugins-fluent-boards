var t,e,o=t=>{throw TypeError(t)},s=(t,e,s)=>e.has(t)||o("Cannot "+s),a=(t,e,o)=>(s(t,e,"read from private field"),o?o.call(t):e.get(t)),n=(t,e,s)=>e.has(t)?o("Cannot add the same private member more than once"):e instanceof WeakSet?e.add(t):e.set(t,s),i=(t,e,o,a)=>(s(t,e,"write to private field"),a?a.call(t,o):e.set(t,o),o);import{d as c}from"../../../../../../../index.js?ver=1.95.2";import{cs as l,cI as r,cy as d,cJ as h,cK as u,cL as m,cM as v,cN as p,cO as f,cz as b,c9 as w,cP as y,cQ as g,cR as k,cS as $,cT as x,cU as I,cV as j,cW as L,ca as O}from"../../../../../../../vendor.js?ver=1.95.2";import{g as E,j as M,s as R,d as S,l as T}from"../../../../../../../todoList.js?ver=1.95.2";const W=({ctx:t,hide:e,show:o,config:s})=>{var a,n,i,c,l;const j=r();d(()=>{j()},[o]);const L=e=>o=>{o.preventDefault(),t&&e(t),j()},O=e=>{if(!t)return!1;const o=t.get(w),{state:{doc:s,selection:a}}=o;return s.rangeHasMark(a.from,a.to,e)};return b`<host>
    <button
      class=${h("toolbar-item",t&&O(u.type(t))&&"active")}
      onmousedown=${L(t=>{t.get(y).call(g.key)})}
    >
      ${(null==(a=null==s?void 0:s.boldIcon)?void 0:a.call(s))??E}
    </button>
    <button
      class=${h("toolbar-item",t&&O(m.type(t))&&"active")}
      onmousedown=${L(t=>{t.get(y).call(k.key)})}
    >
      ${(null==(n=null==s?void 0:s.italicIcon)?void 0:n.call(s))??M}
    </button>
    <button
      class=${h("toolbar-item",t&&O(v.type(t))&&"active")}
      onmousedown=${L(t=>{t.get(y).call($.key)})}
    >
      ${(null==(i=null==s?void 0:s.strikethroughIcon)?void 0:i.call(s))??R}
    </button>
    <div class="divider"></div>
    <button
      class=${h("toolbar-item",t&&O(p.type(t))&&"active")}
      onmousedown=${L(t=>{t.get(y).call(x.key)})}
    >
      ${(null==(c=null==s?void 0:s.codeIcon)?void 0:c.call(s))??S}
    </button>
    <button
      class=${h("toolbar-item",t&&O(f.type(t))&&"active")}
      onmousedown=${L(t=>{const o=t.get(w),{selection:s}=o.state;O(f.type(t))?t.get(I.key).removeLink(s.from,s.to):(t.get(I.key).addLink(s.from,s.to),null==e||e())})}
    >
      ${(null==(l=null==s?void 0:s.linkIcon)?void 0:l.call(s))??T}
    </button>
  </host>`};W.props={ctx:Object,hide:Function,show:Boolean,config:Object};const B=l(W),C=j("CREPE_TOOLBAR");class F{constructor(o,s,c){n(this,t),n(this,e),this.update=(e,o)=>{a(this,t).update(e,o)},this.destroy=()=>{a(this,t).destroy(),a(this,e).remove()},this.hide=()=>{a(this,t).hide()};const l=new B;i(this,e,l),a(this,e).ctx=o,a(this,e).hide=this.hide,a(this,e).config=c,i(this,t,new L({content:a(this,e),debounce:20,offset:10,shouldShow(t){const{doc:e,selection:o}=t.state,{empty:s,from:a,to:n}=o,i=!e.textBetween(a,n).length&&o instanceof O,c=!(o instanceof O),r=t.dom.getRootNode().activeElement,d=l.contains(r),h=!t.hasFocus()&&!d,u=!t.editable;return!(h||c||s||i||u)}})),a(this,t).onShow=()=>{a(this,e).show=!0,setTimeout(()=>{let t=a(this,e).style.left;t=parseInt(t),t<0?a(this,e).style.left=0:t>350&&(a(this,e).style.left="350px")},0)},a(this,t).onHide=()=>{a(this,e).show=!1},this.update(s)}}t=new WeakMap,e=new WeakMap,c("milkdown-toolbar",B);const H=(t,e)=>{t.config(t=>{t.set(C.key,{view:o=>new F(t,o,e)})}).use(C)};export{H as defineFeature};

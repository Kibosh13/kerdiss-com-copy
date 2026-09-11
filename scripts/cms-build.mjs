import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {parse} from 'parse5';
import {build} from 'vite';
import react from '@vitejs/plugin-react';

const root = path.resolve(import.meta.dirname, '..');
const source = path.join(root, 'site');
const out = path.join(root, 'dist');
const hash = x => crypto.createHash('sha256').update(x).digest('hex').slice(0,16);
const attr = (n,k) => n?.attrs?.find(a=>a.name===k)?.value || '';
const has = (n,c) => attr(n,'class').split(/\s+/).includes(c);
const nodes = n => [n,...(n.childNodes||[]).flatMap(nodes)];
const text = n => n.nodeName==='#text' ? n.value : (n.childNodes||[]).map(text).join('');
const ancestors = n => n ? [n,...ancestors(n.parentNode)] : [];
const walkFiles = d => fs.readdirSync(d,{withFileTypes:true}).flatMap(e=> e.isDirectory()?walkFiles(path.join(d,e.name)):[path.join(d,e.name)]);
fs.mkdirSync(path.join(out,'templates'),{recursive:true});
fs.mkdirSync(path.join(out,'content'),{recursive:true});
const cards = {};
const pages = [], globals = new Map(), media = new Map();
for(const file of walkFiles(source).filter(p=>/\.(png|jpe?g|webp|gif|svg|avif|ico)$/i.test(p))){
  if (file.includes('/fonts/') || file.includes('/lib/')) continue;
  const url='/'+path.relative(source,file).split(path.sep).join('/');
  media.set(url,{id:'asset_'+hash(url),url,name:path.basename(file),type:path.extname(file).slice(1),size:fs.statSync(file).size,pages:[],alt:''});
}
for(const file of walkFiles(source).filter(p=>p.endsWith('.html'))){
  const html=fs.readFileSync(file,'utf8');
  if(/http-equiv="refresh"/i.test(html))continue;
  const route='/'+path.relative(source,file).replace(/index\.html$/,'').split(path.sep).join('/');
  const id=route==='/'?'home':route.replace(/^\/|\/$/g,'').replaceAll('/','__');
  const tree=parse(html,{sourceCodeLocationInfo:true});
  const all=nodes(tree), patches=[], fields=[], groups=new Map(), parentIds=new Map();
  const title=text(all.find(n=>n.tagName==='title')||{}).replace(/ - URSAR$/,'');
  const getMeta=k=>attr(all.find(n=>n.tagName==='meta'&&(attr(n,'name')===k||attr(n,'property')===k)),'content');
  if(route==='/shop/')for(const li of all.filter(n=>n.tagName==='li'&&has(n,'product'))){const link=nodes(li).find(n=>n.tagName==='a'&&attr(n,'href').startsWith('/product/'));if(link&&li.sourceCodeLocation)cards[attr(link,'href')]=html.slice(li.sourceCodeLocation.startOffset,li.sourceCodeLocation.endOffset);}
  const productRoot=all.find(n=>has(n,'type-product'));
  const type=route.startsWith('/product/')?'product':route.startsWith('/category/')||route.startsWith('/product-category/')?'archive':'page';
  const sectionOf = a => {
    if(a.some(n=>attr(n,'id')==='masthead'))return 'Шапка и меню';
    if(a.some(n=>attr(n,'data-elementor-post-type')==='elementor-hf'))return 'Футер';
    if(a.some(n=>attr(n,'id')==='stickyelements-form'||attr(n,'id').includes('mystickyelements')))return 'Боковая форма';
    if(a.some(n=>n.tagName==='form'))return 'Формы';
    if(a.some(n=>has(n,'woocommerce-tabs')))return 'Подробное описание';
    if(a.some(n=>has(n,'summary')))return 'Карточка товара';
    const section=[...a].reverse().find(n=>has(n,'e-parent'));
    if(section){
      const heading=nodes(section).find(n=>/^h[1-6]$/.test(n.tagName));
      return text(heading||{}).trim().slice(0,100)||'Содержимое страницы';
    }
    return 'Содержимое страницы';
  };
  const locatorOf=n=>ancestors(n).reverse().slice(2).map(x=>x.parentNode?.childNodes?.indexOf(x)).join('.');
  const scopeOf=a=>a.some(n=>attr(n,'id')==='masthead'||attr(n,'data-elementor-post-type')==='elementor-hf'||attr(n,'id')==='stickyelements-form')?'global':id;
  function addField(n,value,kind,attribute){
    const a=ancestors(n),scope=scopeOf(a),section=sectionOf(a);
    const fid=(scope==='global'?'g_':'t_')+hash(scope==='global'?kind+'|'+attribute+'|'+value:scope+'|'+locatorOf(n)+'|'+kind+'|'+attribute);
    const h=a.find(x=>/^h[1-6]$/.test(x.tagName));
    const field={id:fid,value,kind,attribute,section,label:kind==='link'?'Адрес ссылки':kind==='attribute'?(attribute==='alt'?'Описание изображения':attribute==='placeholder'?'Подсказка поля':attribute==='value'?'Надпись кнопки':'Подпись'):h?'Заголовок '+h.tagName.toUpperCase():a.some(x=>x.tagName==='a')?'Текст ссылки':a.some(x=>x.tagName==='label')?'Подпись поля':'Текст',tag:h?.tagName||n.parentNode?.tagName||n.tagName};
    if(scope==='global'){if(!globals.has(fid))globals.set(fid,field);}else fields.push(field);
    const parent=n.nodeName==='#text'?n.parentNode:n;
    if(parent.sourceCodeLocation?.startTag){const ids=parentIds.get(parent)||[];ids.push(fid);parentIds.set(parent,ids);}
    return fid;
  }
  for(const n of all){
    const a=ancestors(n);
    if(a.some(x=>['head','script','style','svg','noscript','template'].includes(x.tagName)))continue;
    if(n.nodeName==='#text'&&n.sourceCodeLocation&&/[\p{L}\p{N}]/u.test(n.value)&&n.value.trim()&&!a.some(x=>x.tagName==='textarea')){
      const value=n.value.trim();
      if(value.length>20000)continue;
      const fid=addField(n,value,'text');
      const {startOffset:start,endOffset:end}=n.sourceCodeLocation;
      patches.push({start,end,value:`<!--URSAR:T:${fid}-->${html.slice(start,end)}<!--URSAR:/T-->`});
    }
    if(n.attrs&&n.sourceCodeLocation?.attrs){
      for(const at of n.attrs){
        if(!['placeholder','alt','title','href','value'].includes(at.name)||!at.value.trim())continue;
        if(at.name==='href'&&(n.tagName!=='a'||!/^(https?:|mailto:|tel:|\/(?!\/)|#)/.test(at.value)))continue;
        if(at.name==='value'&&!(n.tagName==='input'&&['submit','button'].includes(attr(n,'type'))))continue;
        const loc=n.sourceCodeLocation.attrs[at.name];if(!loc)continue;
        const fid=addField(n,at.value,at.name==='href'?'link':'attribute',at.name);
        patches.push({start:loc.startOffset,end:loc.endOffset,value:`${at.name}="__URSAR_ATTR_${fid}__"`});
      }
    }
    if(n.tagName==='img'){
      let src=attr(n,'src').split('?')[0];try{src=decodeURI(src);}catch{}
      const item=media.get(src);if(item){if(!item.pages.includes(id))item.pages.push(id);if(attr(n,'alt')&&(!item.alt||item.alt.length<attr(n,'alt').length))item.alt=attr(n,'alt');}
    }
  }
  for(const [n,ids]of parentIds){const pos=n.sourceCodeLocation.startTag.endOffset-1;patches.push({start:pos,end:pos,value:` data-cms-fields="${[...new Set(ids)].join(' ')}"`});}
  // Include responsive sources and CSS backgrounds in each page's media collection.
  const imageRefs = (content,base) => {
    for (const match of content.replaceAll('\\/','/').matchAll(/(?:\/|\.\.\/)[^\s"'()<>]+?\.(?:png|jpe?g|webp|gif|svg|avif|ico)/gi)) {
      try {const url=decodeURI(new URL(match[0],'https://local'+base).pathname);const m=media.get(url);if(m&&!m.pages.includes(id))m.pages.push(id);}catch{}
    }
  };
  imageRefs(html,route);
  for(const link of all.filter(n=>n.tagName==='link'&&attr(n,'rel')==='stylesheet')){
    const href=attr(link,'href').split('?')[0];if(!href.startsWith('/'))continue;
    const css=path.join(source,decodeURI(href));if(fs.existsSync(css))imageRefs(fs.readFileSync(css,'utf8'),href);
  }
  const usedImages=[...media.values()].filter(m=>m.pages.includes(id));
  const gallery=all.filter(n=>n.tagName==='img'&&ancestors(n).some(x=>has(x,'woocommerce-product-gallery__image'))).map(n=>attr(n,'data-large_image')||attr(n,'src'));
  const h1=all.find(n=>n.tagName==='h1');
  const prodName=text(h1||{}).trim();
  const short=all.find(n=>has(n,'woocommerce-product-details__short-description'));
  const category=all.find(n=>n.tagName==='a'&&attr(n,'href').startsWith('/product-category/'));
  const hero=usedImages.find(m=>!m.url.includes('/brand/')&&m.size>15000)?.url||'';
  const page={id,route,title,type,templateId:id,fields,images:usedImages.map(x=>x.id),seo:{title:getMeta('og:title')||`${title} - URSAR`,description:getMeta('description'),canonical:route,ogTitle:getMeta('og:title'),ogDescription:getMeta('og:description'),ogImage:getMeta('og:image'),noindex:/^\/(?:cart|checkout|my-account|booking)(?:\/|$)/.test(route)},product:type==='product'?{name:prodName,slug:route.split('/').filter(Boolean).pop(),category:attr(category,'href').includes('lawn-mower')?'lawn-mower':'skid-loader',description:text(short||{}).trim(),gallery:[...new Set(gallery)],price:'',availability:'request'}:null,hero};
  // These marked fragments can be rebuilt for new products without rewriting the site theme.
  const galleryRoot=all.find(n=>has(n,'woocommerce-product-gallery'));
  if(galleryRoot?.sourceCodeLocation){const l=galleryRoot.sourceCodeLocation;patches.push({start:l.startOffset,end:l.startOffset,value:'<!--URSAR:GALLERY-->'},{start:l.endOffset,end:l.endOffset,value:'<!--URSAR:/GALLERY-->'});}
  const grid=all.find(n=>n.tagName==='ul'&&has(n,'products'));
  if(grid?.sourceCodeLocation?.startTag&&grid.sourceCodeLocation.endTag){patches.push({start:grid.sourceCodeLocation.startTag.endOffset,end:grid.sourceCodeLocation.startTag.endOffset,value:'<!--URSAR:PRODUCTS-->'},{start:grid.sourceCodeLocation.endTag.startOffset,end:grid.sourceCodeLocation.endTag.startOffset,value:'<!--URSAR:/PRODUCTS-->'});}
  let template=html;
  for(const p of patches.sort((a,b)=>b.start-a.start||b.end-a.end))template=template.slice(0,p.start)+p.value+template.slice(p.end);
  template=template.replace('</head>',`<script src="/_cms/public.js?v=${hash(fs.readFileSync(path.join(root,'cms/public/public.js')))}" defer></script><link rel="stylesheet" href="/_cms/public.css?v=${hash(fs.readFileSync(path.join(root,'cms/public/public.css')))}"></head>`);
  fs.writeFileSync(path.join(out,'templates',id+'.html'),template);
  fs.writeFileSync(path.join(out,'content',id+'.json'),JSON.stringify(page));
  pages.push({...page,fields:undefined,images:undefined,fieldCount:fields.length});
}
const globalPage={id:'globals',route:'/',title:'Общие блоки',type:'global',templateId:'globals',fields:[...globals.values()],images:[],seo:{},product:null};
fs.writeFileSync(path.join(out,'content','globals.json'),JSON.stringify(globalPage));
pages.push({...globalPage,fields:undefined,fieldCount:globals.size});
fs.writeFileSync(path.join(out,'content','cards.json'),JSON.stringify(cards));
fs.writeFileSync(path.join(out,'content','index.json'),JSON.stringify({pages,media:[...media.values()],builtAt:new Date().toISOString()}));
console.log(`CMS: ${pages.length-1} pages, ${pages.reduce((n,p)=>n+p.fieldCount,0)} editable fields, ${media.size} images`);
if(!process.argv.includes('--content-only'))await build({root:path.join(root,'cms/admin'),plugins:[react()],base:'/_admin/',build:{outDir:path.join(out,'admin'),emptyOutDir:true},logLevel:'warn'});

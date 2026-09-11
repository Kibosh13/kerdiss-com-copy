<?php
function publishedRecords(bool $preview=false):array {
  return array_values(array_filter(array_map(fn($r)=>recordView($r,$preview?false:true),all("SELECT * FROM content WHERE archived=0 AND type<>'global'")),fn($r)=>$r['data']!==null&&!($r['data']['hidden']??false)));
}
function mediaMappings(bool $preview=false):array {
  global $renderContentId;static $cache=[];$key=(int)$preview.'|'.($renderContentId??'');if(isset($cache[$key]))return $cache[$key];
  $g=json_decode(record('globals')[$preview?'draft':'published'],true);$items=array_column(all('SELECT * FROM media'),null,'id');$map=[];
  $combined=$g['mediaMap']??[];if(!empty($renderContentId)){$page=one('SELECT draft,published FROM content WHERE id=? AND archived=0',[$renderContentId]);$pageData=$page?json_decode($page[$preview?'draft':'published']??'null',true):null;$combined=array_replace($combined,$pageData['mediaMap']??[]);}
  foreach($combined as $from=>$to){if(isset($items[$from],$items[$to])&&$from!==$to)$map[$items[$from]['url']]=$items[$to]['url'];}
  return $cache[$key]=$map;
}
function rewriteMedia(string $html,bool $preview=false):string {
  $map=mediaMappings($preview);$replace=[];
  foreach($map as $from=>$to){$replace[$from]=$to;$replace[str_replace('%2F','/',rawurlencode($from))]=$to;}
  return $replace?strtr($html,$replace):$html;
}
function cssContent(string $url,bool $preview=false):string {
  $path=parse_url($url,PHP_URL_PATH)??'';$file=siteFile($path);
  if(!$file||strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='css'){http_response_code(404);return '';}
  $css=file_get_contents($file);$folder=dirname($path);
  $css=preg_replace_callback('~url\(\s*([\'"]?)(.*?)\1\s*\)~i',function($m)use($folder,$preview){
    $value=$m[2];if(preg_match('~^(data:|https?:|//|#)~i',$value))return $m[0];
    if(!str_starts_with($value,'/')){$parts=[];foreach(explode('/',$folder.'/'.$value) as $p){if($p==='..')array_pop($parts);elseif($p!==''&&$p!=='.')$parts[]=$p;}$value='/'.implode('/',$parts);}
    return 'url("'.str_replace('"','%22',rewriteMedia($value,$preview)).'")';
  },$css);
  return $css;
}
function productCard(array $r):string {
  global $baseline;
  static $cards=null;if($cards===null)$cards=json_decode(file_get_contents(ROOT.'/dist/content/cards.json'),true);
  $base=$baseline[$r['templateId']]??null;$original=$cards[$base['route']??'']??null;
  if($original&&$base['product']){
    $p=$r['product'];$old=$base['product'];$card=str_replace([esc($base['route']),esc($old['name'])],[esc($r['route']),esc($p['name'])],$original);
    if($p['gallery']!==$old['gallery'])$card=preg_replace_callback('~<img\b[^>]*>~i',function($m)use($p){$tag=preg_replace('~\s+(?:srcset|sizes)=[\'"][^\'"]*[\'"]~','',$m[0]);return preg_replace_callback('~\bsrc=[\'"][^\'"]*[\'"]~',fn()=>'src="'.esc($p['gallery'][0]??'/_preserved/brand/ursar-transparent.svg').'"',$tag);},$card);
    if($p['category']!==$old['category']){$card=preg_replace_callback('~(<span\b[^>]*class="ast-woo-product-category"[^>]*>).*?(</span>)~s',fn($m)=>$m[1].($p['category']==='lawn-mower'?'Газонокосилки':'Мини-погрузчики').$m[2],$card);$card=str_replace('product_cat-'.$old['category'],'product_cat-'.$p['category'],$card);}
    if($p['price']!=='')$card=preg_replace('~(</h2></a>)~','$1<span class="price">'.number_format((float)$p['price'],0,',',' ').' ₽</span>',$card,1);
    return $card;
  }
  $p=$r['product'];$url=esc($r['route']);$name=esc($p['name']);$img=esc($p['gallery'][0]??'/_preserved/brand/ursar-transparent.svg');$category=$p['category']==='lawn-mower'?'Газонокосилки':'Мини-погрузчики';
  $price=$p['price']!==''?'<span class="price">'.number_format((float)$p['price'],0,',',' ').' ₽</span>':'';
  return '<li class="ast-grid-common-col ast-full-width ast-article-post remove-featured-img-padding desktop-align-left tablet-align-left mobile-align-left product type-product status-publish instock has-post-thumbnail"><div class="astra-shop-thumbnail-wrap"><a href="'.$url.'" class="woocommerce-LoopProduct-link woocommerce-loop-product__link"><img loading="lazy" width="300" height="300" src="'.$img.'" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="'.$name.'"></a></div><div class="astra-shop-summary-wrap"><span class="ast-woo-product-category">'.$category.'</span><a href="'.$url.'" class="ast-loop-product__link"><h2 class="woocommerce-loop-product__title">'.$name.'</h2></a>'.$price.'<a href="'.$url.'" class="button product_type_simple">Читать далее</a></div></li>';
}
function galleryMarkup(array $p):string {
  if(!$p['gallery'])return '<div class="woocommerce-product-gallery ursar-gallery"><img src="/_preserved/brand/ursar-transparent.svg" alt="'.esc($p['name']).'"></div>';
  $html='<div class="woocommerce-product-gallery ursar-gallery"><a class="ursar-gallery-main" href="'.esc($p['gallery'][0]).'" target="_blank" rel="noopener"><img src="'.esc($p['gallery'][0]).'" alt="'.esc($p['name']).'"></a><div class="ursar-gallery-thumbs">';
  foreach($p['gallery'] as $url)$html.='<button type="button" data-gallery-image="'.esc($url).'" aria-label="Посмотреть изображение"><img src="'.esc($url).'" alt="'.esc($p['name']).'"></button>';
  return $html.'</div></div>';
}
function renderPage(array $r,bool $preview=false):string {
  global $baseline,$origin,$renderContentId;$renderContentId=$r['id'];
  $b=$baseline[$r['template_id']];$d=json_decode($r[$preview?'draft':'published'],true);$global=json_decode(record('globals')[$preview?'draft':'published'],true);
  $html=file_get_contents(ROOT.'/dist/templates/'.$r['template_id'].'.html');
  $defaults=array_column([...$b['fields'],...$baseline['globals']['fields']],'value','id');$overrides=array_merge($global['fields']??[],$d['fields']??[]);
  $html=preg_replace_callback('~<!--URSAR:T:([a-z0-9_]+)-->(.*?)<!--URSAR:/T-->~s',fn($m)=>array_key_exists($m[1],$overrides)?'<!--URSAR:T:'.$m[1].'-->'.esc($overrides[$m[1]]).'<!--URSAR:/T-->':$m[0],$html);
  $html=preg_replace_callback('/__URSAR_ATTR_([a-z0-9_]+)__/',fn($m)=>esc($overrides[$m[1]]??$defaults[$m[1]]??''),$html);
  $products=array_values(array_filter(publishedRecords($preview),fn($x)=>$x['type']==='product'));
  // Product names and routes stay consistent in catalog links and breadcrumbs.
  foreach($products as $p){$base=$baseline[$p['templateId']];if($p['id']===$p['templateId']){
    if($p['route']!==$base['route'])$html=str_replace(esc($base['route']),esc($p['route']),$html);
    if($p['product']['name']!==$base['product']['name'])$html=str_replace(esc($base['product']['name']),esc($p['product']['name']),$html);
  }}
  if($r['type']==='product'){
    $p=$d['product'];$old=$b['product'];
    if($p['name']!==$old['name'])$html=str_replace(esc($old['name']),esc($p['name']),$html);
    if($p['description']!==$old['description'])$html=preg_replace_callback('~(<div\b[^>]*class="[^"]*woocommerce-product-details__short-description[^"]*"[^>]*>).*?(</div>)~s',fn($m)=>$m[1].'<p>'.nl2br(esc($p['description'])).'</p>'.$m[2],$html,1);
    if($p['gallery']!==$old['gallery'])$html=preg_replace_callback('~<!--URSAR:GALLERY-->.*?<!--URSAR:/GALLERY-->~s',fn()=>galleryMarkup($p),$html,1);
    if($p['price']!==''||$p['availability']!=='request'){$availability=['request'=>'Цена по запросу','in-stock'=>'В наличии','preorder'=>'Под заказ','out-of-stock'=>'Нет в наличии'];$box='<div class="ursar-product-offer">'.($p['price']!==''?'<strong>'.number_format((float)$p['price'],0,',',' ').' ₽</strong>':'').'<span>'.$availability[$p['availability']].'</span></div>';$html=preg_replace('~(</h1>)~','$1'.$box,$html,1);}
  }
  $catalogChanged=count($products)!==count(array_filter($baseline,fn($b)=>$b['type']==='product'));foreach($products as $item)if($item['id']!==$item['templateId']||$item['product']!==$baseline[$item['templateId']]['product'])$catalogChanged=true;
  if(str_contains($html,'<!--URSAR:PRODUCTS-->')&&($catalogChanged||isset($_GET['orderby']))){
    $items=$products;$route=$d['route'];
    if(str_contains($route,'/product-category/')){$category=basename(trim($route,'/'));$items=array_values(array_filter($items,fn($p)=>$p['product']['category']===$category));}
    if($r['type']==='product')$items=array_slice(array_values(array_filter($items,fn($p)=>$p['id']!==$r['id'])),0,4);
    $sort=$_GET['orderby']??'';if(in_array($sort,['price','price-desc']))usort($items,fn($a,$b)=>((float)$a['product']['price']<=>(float)$b['product']['price'])*($sort==='price'?1:-1));elseif($sort==='date')usort($items,fn($a,$b)=>strcmp($b['publishedAt']??'',$a['publishedAt']??''));
    $cards=implode('',array_map('productCard',$items));$html=preg_replace_callback('~<!--URSAR:PRODUCTS-->.*?<!--URSAR:/PRODUCTS-->~s',fn()=>$cards?:'<li class="ursar-empty-catalog">Товары скоро появятся.</li>',$html,1);
    $html=preg_replace_callback('~(<p\b[^>]*class="[^"]*woocommerce-result-count[^"]*"[^>]*>).*?(</p>)~s',fn($m)=>$m[1].'Товаров: '.count($items).$m[2],$html);
  }
  $seo=$d['seo'];$s=settings();$title=$seo['title']?:$d['title'].' — '.$s['siteName'];$desc=$seo['description']?:$s['description'];$canonical=$seo['canonical']?:$d['route'];if(str_starts_with($canonical,'/'))$canonical=$origin.$canonical;
  $noindex=$preview||$s['noindex']||($seo['noindex']??false);
  $html=preg_replace('~<title\b[^>]*>.*?</title>~s','<title>'.esc($title).'</title>',$html);
  $html=preg_replace('~<meta\b[^>]*(?:name=[\'"](?:description|robots)[\'"]|property=[\'"]og:[^\'"]+[\'"])[^>]*>~i','',$html);
  $html=preg_replace('~<link\b[^>]*rel=[\'"]canonical[\'"][^>]*>~i','',$html);
  $html=preg_replace('~<script\b[^>]*type=[\'"]application/ld\+json[\'"][^>]*>.*?</script>~si','',$html);
  $og=$seo['ogImage']?:($d['product']['gallery'][0]??'/_preserved/brand/ursar-transparent.svg');if(str_starts_with($og,'/'))$og=$origin.$og;
  $meta='<meta name="description" content="'.esc($desc).'"><meta name="robots" content="'.($noindex?'noindex,nofollow,noarchive':'index,follow').'"><link rel="canonical" href="'.esc($canonical).'"><meta property="og:title" content="'.esc($seo['ogTitle']?:$title).'"><meta property="og:description" content="'.esc($seo['ogDescription']?:$desc).'"><meta property="og:url" content="'.esc($canonical).'"><meta property="og:image" content="'.esc($og).'"><meta property="og:type" content="website">';
  $schema=['@context'=>'https://schema.org','@type'=>$r['type']==='product'?'Product':'WebPage','name'=>$r['type']==='product'?$d['product']['name']:$title,'description'=>$desc,'url'=>$canonical];if($r['type']==='product'){$schema['image']=array_map(fn($url)=>$origin.$url,$d['product']['gallery']);$schema['brand']=['@type'=>'Brand','name'=>$s['siteName']];}
  $meta.='<script type="application/ld+json">'.json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
  $config=['preview'=>$preview,'contentId'=>$r['id'],'consentText'=>$s['consentText'],'formSuccess'=>$s['formSuccess']];
  $meta.='<script id="ursar-public-config" type="application/json">'.json_encode($config,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
  $html=str_replace('</head>',$meta.'</head>',$html);
  // Replacement is applied to every image occurrence, including responsive variants and inline backgrounds.
  $html=rewriteMedia($html,$preview);
  $editedAlt=all("SELECT url,alt FROM media WHERE source='upload' OR id IN (SELECT target FROM audit WHERE action='media-edit')");
  foreach($editedAlt as $m){$u=rewriteMedia($m['url'],$preview);$html=preg_replace_callback('~<img\b[^>]*>~i',function($match)use($u,$m){$tag=$match[0];if(!preg_match('~\bsrc=[\'"]'.preg_quote(esc($u),'~').'[\'"]~',$tag))return $tag;$alt='alt="'.esc($m['alt']).'"';return preg_match('/\balt=[\'"][^\'"]*[\'"]/',$tag)?preg_replace_callback('/\balt=[\'"][^\'"]*[\'"]/',fn()=>$alt,$tag):str_replace('<img','<img '.$alt,$tag);},$html);}
  $gVersion=record('globals')['version'];
  $html=preg_replace_callback('~(<link\b[^>]*\bhref=)([\'"])(/(?!_cms/)[^\'"]+\.css(?:\?[^\'"]*)?)\2~i',fn($m)=>$m[1].$m[2].'/_cms/style?file='.rawurlencode(html_entity_decode($m[3])).'&amp;v='.$gVersion.(!empty($d['mediaMap'])?'&amp;content='.rawurlencode($r['id']).'&amp;p='.$r['version']:'').($preview?'&amp;preview=1':'').$m[2],$html);
  return $html;
}

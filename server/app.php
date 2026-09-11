<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Frame-Options: SAMEORIGIN');
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/');$method=$_SERVER['REQUEST_METHOD']??'GET';
function siteFile(string $path):?string {
  $root=realpath(__DIR__.'/../site');$f=realpath($root.'/'.$path);
  if(!$f||!str_starts_with($f,$root.'/')||!is_file($f))return null;
  return $f;
}
function sendFile(string $file,?string $type=null):never {
  $types=['js'=>'text/javascript','css'=>'text/css','svg'=>'image/svg+xml','webp'=>'image/webp','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','otf'=>'font/otf','eot'=>'application/vnd.ms-fontobject','mp4'=>'video/mp4','pdf'=>'application/pdf','avif'=>'image/avif','json'=>'application/json'];
  $type=$type??$types[strtolower(pathinfo($file,PATHINFO_EXTENSION))]??'application/octet-stream';header('Content-Type: '.$type);header('Cache-Control: public,max-age=3600');header('Content-Length: '.filesize($file));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file);exit;
}
if(str_contains($path,"\0")||str_contains($path,'\\')||preg_match('~(?:^/(?:data|server|node_modules|dist|\.git)(?:/|$)|/(?:\.|\.\.)(?:/|$))~',$path)){http_response_code(404);exit('Не найдено');}
if(preg_match('~^/_admin/(.+)$~',$path,$m)){$root=realpath(__DIR__.'/../dist/admin');$f=realpath($root.'/'.$m[1]);if($f&&str_starts_with($f,$root.'/')&&is_file($f))sendFile($f);http_response_code(404);exit;}
if($path==='/_cms/public.js'||$path==='/_cms/public.css')sendFile(__DIR__.'/../cms/public/'.basename($path));
if($path==='/admin'||str_starts_with($path,'/admin/')){
  header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
  header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
  readfile(__DIR__.'/../dist/admin/index.html');exit;
}
if(preg_match('~^/(wp-content|wp-includes|_preserved|_external)/~',$path)&&preg_match('~\.(css|js|svg|webp|png|jpe?g|gif|ico|woff2?|ttf|eot|otf|mp4|pdf|avif|json)$~i',$path)){
  $file=siteFile($path);if(!$file){http_response_code(404);exit;}
  sendFile($file);
}
try {
  require __DIR__.'/bootstrap.php';require __DIR__.'/render.php';
  if(str_starts_with($path,'/api/')){require __DIR__.'/api.php';api($path,$method);}
  if($path==='/_cms/style'){
    $preview=isset($_GET['preview'])&&($u=sessionUser())&&in_array($u['role'],['admin','editor'],true);$renderContentId=preg_match('/^[a-z0-9_-]+$/',$_GET['content']??'')?$_GET['content']:null;header('Content-Type: text/css; charset=utf-8');header('Cache-Control: '.($preview?'no-store':'public,max-age=300'));echo cssContent((string)($_GET['file']??''),$preview);exit;
  }
  if(preg_match('~^/media/(upload_[a-f0-9]{32})\.webp$~',$path,$m)){
    $media=one('SELECT id FROM media WHERE id=? AND archived=0',[$m[1]]);$file=$dataDir.'/uploads/'.$m[1].'.webp';if($media&&is_file($file))sendFile($file,'image/webp');http_response_code(404);exit;
  }
  if($path==='/robots.txt'){header('Content-Type: text/plain; charset=utf-8');echo settings()['noindex']?"User-agent: *\nDisallow: /\n":"User-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /_cms/\nSitemap: ".$origin."/sitemap.xml\n";exit;}
  if($path==='/sitemap.xml'){header('Content-Type: application/xml; charset=utf-8');echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';if(!settings()['noindex'])foreach(publishedRecords() as $p){if(!($p['seo']['noindex']??false))echo '<url><loc>'.esc($origin.$p['route']).'</loc><lastmod>'.esc(substr($p['publishedAt'],0,10)).'</lastmod></url>';}echo '</urlset>';exit;}
  if(!in_array($method,['GET','HEAD'])){http_response_code(405);exit('Метод не поддерживается');}
  $preview=false;$r=null;
  if(isset($_GET['cms_preview'])){requireUser('content');$r=record(str($_GET['cms_preview'],150));if($r['type']==='global')$r=record('home');$preview=true;}
  if(!$r){$route=rtrim(preg_replace('~/index\.html$~','/',$path),'/').'/';if($route==='//')$route='/';foreach(all("SELECT * FROM content WHERE archived=0 AND published IS NOT NULL AND type<>'global'") as $candidate){$d=json_decode($candidate['published'],true);if($d['route']===$route&&!($d['hidden']??false)){$r=$candidate;break;}}
    if($r&&$path!==$route){header('Location: '.$route, true,301);exit;}
    if(!$r&&$redirect=one('SELECT c.published,c.archived FROM redirects x JOIN content c ON c.id=x.content_id WHERE x.route=?',[$route])){if(!$redirect['archived']&&$redirect['published']){header('Location: '.json_decode($redirect['published'],true)['route'],true,301);exit;}}
  }
  if(!$r){http_response_code(404);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="robots" content="noindex"><title>Страница не найдена — URSAR</title><body style="font:18px system-ui;background:#f4f7f7;color:#17353b;padding:12vw"><h1>Страница не найдена</h1><p>Возможно, адрес изменился.</p><a href="/">Главная</a> · <a href="/shop/">Каталог</a></body></html>';exit;}
  header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');if($preview||settings()['noindex'])header('X-Robots-Tag: noindex, nofollow');echo renderPage($r,$preview);
}catch(Throwable $e){error_log('URSAR: '.get_class($e).' in '.basename($e->getFile()).':'.$e->getLine());if(str_starts_with($path,'/api/')){if(function_exists('fail'))fail('Не удалось выполнить запрос. Повторите позже.',500);http_response_code(500);header('Content-Type: application/json');echo '{"error":"Сервис временно недоступен."}';}else{http_response_code(500);echo 'Сервис временно недоступен. Пожалуйста, повторите позже.';}}

<?php
declare(strict_types=1);
const ROOT = __DIR__ . '/..';
const SESSION_HOURS = 12;
date_default_timezone_set('Europe/Moscow');
$configFile = getenv('URSAR_CONFIG') ?: ROOT . '/data/config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = $config['data_dir'] ?? ROOT . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
if (!is_dir($dataDir . '/uploads')) mkdir($dataDir . '/uploads', 0700, true);
if (!is_file($dataDir . '/secret')) {file_put_contents($dataDir . '/secret', bin2hex(random_bytes(48)), LOCK_EX);chmod($dataDir . '/secret',0640);}
$secret = trim(file_get_contents($dataDir . '/secret'));
$origin = rtrim($config['origin'] ?? 'http://127.0.0.1:4311', '/');
$db = new PDO('sqlite:' . $dataDir . '/ursar.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=5000');
$db->sqliteCreateFunction('mb_lower',fn($s)=>mb_strtolower((string)$s,'UTF-8'),1);
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
foreach(glob(__DIR__ . '/migrations/*.sql') as $file){
  $name=basename($file); $q=$db->prepare('SELECT name FROM schema_migrations WHERE name=?');$q->execute([$name]);
  if(!$q->fetch()){$db->beginTransaction();try{$db->exec(file_get_contents($file));$q=$db->prepare('INSERT INTO schema_migrations(name,applied_at) VALUES(?,?)');$q->execute([$name,gmdate('c')]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
}
$manifest=json_decode(file_get_contents(ROOT.'/dist/content/index.json'),true,512,JSON_THROW_ON_ERROR);
$baseline=[];
$manifestHash=hash_file('sha256',ROOT.'/dist/content/index.json');
$seed = !one('SELECT id FROM content_import_state WHERE id=1 AND fingerprint=?',[$manifestHash]);
if ($seed) $db->beginTransaction();
foreach($manifest['pages'] as $p){
  $p=json_decode(file_get_contents(ROOT.'/dist/content/'.$p['id'].'.json'),true,512,JSON_THROW_ON_ERROR);$baseline[$p['id']]=$p;
  if (!$seed) continue;
  $record=['fields'=>new stdClass(),'seo'=>$p['seo'],'product'=>$p['product'],'route'=>$p['route'],'title'=>$p['title'],'mediaMap'=>new stdClass(),'hidden'=>false];
  $q=$db->prepare('INSERT OR IGNORE INTO content(id,type,template_id,draft,published,version,updated_at,published_at) VALUES(?,?,?,?,?,1,?,?)');
  $q->execute([$p['id'],$p['type'],$p['id'],json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),gmdate('c'),gmdate('c')]);
}
if ($seed) foreach($manifest['media'] as $m){$q=$db->prepare('INSERT OR IGNORE INTO media(id,url,name,type,size,alt,source,pages,created_at) VALUES(?,?,?,?,?,?,\'original\',?,?)');$q->execute([$m['id'],$m['url'],$m['name'],$m['type'],$m['size'],$m['alt'],json_encode($m['pages']),gmdate('c')]);}
$defaultSettings=['siteName'=>'URSAR','description'=>'Газонокосилки и мини-погрузчики URSAR','noindex'=>true,'formSuccess'=>'Спасибо! Ваше сообщение отправлено. Мы свяжемся с вами в ближайшее время.','consentText'=>'Я согласен с политикой конфиденциальности и обработкой персональных данных.'];
$q=$db->prepare('INSERT OR IGNORE INTO settings(id,value,version) VALUES(1,?,1)');$q->execute([json_encode($defaultSettings,JSON_UNESCAPED_UNICODE)]);
if ($seed) {q('INSERT OR REPLACE INTO content_import_state(id,fingerprint,imported_at) VALUES(1,?,?)',[$manifestHash,now()]);$db->commit();}
function q(string $sql,array $args=[]):PDOStatement{global $db;$s=$db->prepare($sql);$s->execute($args);return $s;}
function one(string $sql,array $args=[]):?array{return q($sql,$args)->fetch()?:null;}
function all(string $sql,array $args=[]):array{return q($sql,$args)->fetchAll();}
function uuid():string{return bin2hex(random_bytes(16));}
function now():string{return gmdate('c');}
function encode($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function esc($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function jsonObjects($value){if(!is_array($value))return $value;foreach($value as $key=>$item){$value[$key]=jsonObjects($item);if(in_array($key,['fields','mediaMap'],true)&&is_array($value[$key])&&($value[$key]===[]||!array_is_list($value[$key])))$value[$key]=(object)$value[$key];}return $value;}
function jsonResponse($data,int $status=200):never{$data=jsonObjects($data);http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo encode($data);exit;}
function fail(string $message,int $status=400):never{jsonResponse(['error'=>$message],$status);}
function input():array{if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))fail('Ожидаются данные JSON.',415);if((int)($_SERVER['CONTENT_LENGTH']??0)>1500000)fail('Слишком большой запрос.',413);$v=json_decode(file_get_contents('php://input'),true);if(!is_array($v)||($v!==[]&&array_is_list($v)))fail('Некорректные данные.');return $v;}
function str($v,int $max=1000):string{if(!is_string($v)||mb_strlen($v)>$max||str_contains($v,"\0"))fail('Недопустимое значение поля.');return $v;}
function validUrl($v,bool $links=true):bool{if(!is_string($v)||strlen($v)>4096||preg_match('/[\x00-\x20\\\\]/',$v))return false;return $v===''||preg_match($links?'~^(?:https?://|mailto:|tel:|/(?!/)|\#)~i':'~^(?:https?://|/(?!/))~i',$v)===1;}
function settings():array{return json_decode(one('SELECT value FROM settings WHERE id=1')['value'],true);}
function audit(string $action,string $target,string $label,array $detail=[]):void{global $actor;q('INSERT INTO audit(id,actor,action,target,label,details,created_at) VALUES(?,?,?,?,?,?,?)',[uuid(),$actor['email']??'Система',$action,$target,$label,encode($detail),now()]);}
function userPublic(array $u):array{return array_intersect_key($u,array_flip(['id','email','name','role','active','created_at','last_login']));}
function sessionUser():?array{
  $token=$_COOKIE['ursar_session']??'';if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
  return one('SELECT u.*,s.id session_id,s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires_at>? AND u.active=1',[hash('sha256',$token),time()]);
}
function csrf():string{global $secret;return hash_hmac('sha256','csrf|'.($_COOKIE['ursar_session']??''),$secret);}
function requireUser(string $permission='read'):array{
  global $actor,$origin;
  $actor=sessionUser();if(!$actor)fail('Войдите в админку.',401);
  $roles=['read'=>['admin','editor','manager'],'content'=>['admin','editor'],'leads'=>['admin','manager'],'admin'=>['admin']];
  if(!in_array($actor['role'],$roles[$permission]??['admin'],true))fail('Для этого действия недостаточно прав.',403);
  if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){
    if(!hash_equals(csrf(),$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Сессия устарела. Обновите страницу.',403);
    $requestOrigin=$_SERVER['HTTP_ORIGIN']??'';
    if($requestOrigin!==''&&!hash_equals($origin,$requestOrigin))fail('Недопустимый источник запроса.',403);
  }
  return $actor;
}
function createSession(string $id):void{global $origin;$token=bin2hex(random_bytes(32));q('DELETE FROM sessions WHERE expires_at<?',[time()]);q('INSERT INTO sessions(id,user_id,expires_at,created_at) VALUES(?,?,?,?)',[hash('sha256',$token),$id,time()+SESSION_HOURS*3600,now()]);setcookie('ursar_session',$token,['expires'=>time()+SESSION_HOURS*3600,'path'=>'/','secure'=>str_starts_with($origin,'https://'),'httponly'=>true,'samesite'=>'Lax']);$_COOKIE['ursar_session']=$token;}
function passwordHash(string $password):string{if(mb_strlen($password)<12||strlen($password)>72)fail('Пароль должен содержать от 12 до 72 байт (не менее 12 символов).');return password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT,defined('PASSWORD_ARGON2ID')?['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]:['cost'=>12]);}
function rateLimit(string $key,int $limit,int $window):bool{$bucket=intdiv(time(),$window);q('INSERT OR IGNORE INTO rate_limits(key,bucket,count) VALUES(?,?,0)',[$key,$bucket]);q('UPDATE rate_limits SET count=count+1 WHERE key=? AND bucket=?',[$key,$bucket]);$count=(int)one('SELECT count FROM rate_limits WHERE key=? AND bucket=?',[$key,$bucket])['count'];if(random_int(1,100)===1)q('DELETE FROM rate_limits WHERE key=? AND bucket<?',[$key,$bucket-2]);return $count<=$limit;}
function ipKey():string{global $secret;return hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',$secret);}
function recordView(array $r,bool $published=false):array{global $baseline;$b=$baseline[$r['template_id']]??null;$data=json_decode($r[$published?'published':'draft']??'null',true);return ['id'=>$r['id'],'type'=>$r['type'],'templateId'=>$r['template_id'],'version'=>(int)$r['version'],'updatedAt'=>$r['updated_at'],'publishedAt'=>$r['published_at'],'isPublished'=>$r['published']!==null,'hasDraft'=>$r['draft']!==$r['published'],'archived'=>(bool)$r['archived'],'fieldCount'=>count($b['fields']??[]),'hero'=>$data['product']['gallery'][0]??$b['hero']??'','title'=>$data['title']??$b['title']??'','route'=>$data['route']??$b['route']??'/','product'=>$data['product']??null,'seo'=>$data['seo']??[],'data'=>$data];}
function record(string $id):array{$r=one('SELECT * FROM content WHERE id=?',[$id]);if(!$r)fail('Страница не найдена.',404);return $r;}
function validateData(array $data,array $r):array{
  global $baseline;
  $b=$baseline[$r['template_id']]??null;if(!$b)fail('Шаблон не найден.',409);
  $fields=$data['fields']??[];if(!is_array($fields)||count($fields)>3000)fail('Некорректные текстовые блоки.');
  $known=array_column($b['fields'],null,'id');
  foreach($fields as $id=>$value){if(!isset($known[$id]))fail('Структура страницы изменилась. Обновите редактор.',409);str($value,30000);if($known[$id]['kind']==='link'&&!validUrl($value))fail('Разрешены обычные ссылки, телефон и email.');}
  $title=trim(str($data['title']??$b['title'],200));if($title==='')fail('Укажите название страницы.');
  $route=str($data['route']??$b['route'],250);if(!preg_match('~^/(?:[a-z0-9_-]+/)*$~',$route)||preg_match('~^/(admin|api|media|_cms|_admin|data|server|dist)/~',$route))fail('Адрес должен состоять из латинских букв, цифр и дефисов.');
  $seo=$data['seo']??[];foreach(['title'=>200,'description'=>1000,'canonical'=>4096,'ogTitle'=>200,'ogDescription'=>1000,'ogImage'=>4096] as $key=>$max){$seo[$key]=str($seo[$key]??'', $max);}
  if(!validUrl($seo['canonical'],false)||!validUrl($seo['ogImage'],false))fail('Проверьте адрес в SEO-настройках.');$seo['noindex']=(bool)($seo['noindex']??true);
  $product=null;
  if($r['type']==='product'){
    $p=$data['product']??[];$name=trim(str($p['name']??'',250));if(!$name)fail('Укажите название товара.');
    $slug=str($p['slug']??'',150);if(!preg_match('/^[a-z0-9][a-z0-9-]*$/',$slug))fail('Адрес товара: только латинские буквы, цифры и дефисы.');
    if(!in_array($p['category']??'',['lawn-mower','skid-loader'],true))fail('Выберите категорию.');
    $gallery=$p['gallery']??[];if(!is_array($gallery)||count($gallery)>60)fail('В галерее может быть до 60 изображений.');foreach($gallery as $url)if(!validUrl($url,false)||!str_starts_with($url,'/'))fail('Выберите изображения из медиатеки.');
    $price=str((string)($p['price']??''),20);if($price!==''&&(!is_numeric($price)||(float)$price<0||(float)$price>1000000000))fail('Укажите корректную цену.');
    $availability=in_array($p['availability']??'',['request','in-stock','preorder','out-of-stock'],true)?$p['availability']:'request';
    $product=['name'=>$name,'slug'=>$slug,'category'=>$p['category'],'description'=>str($p['description']??'',30000),'gallery'=>array_values(array_unique($gallery)),'price'=>$price,'availability'=>$availability];$title=$name;$route='/product/'.$slug.'/';
  }
  $prior=json_decode($r['draft']??'null',true);if($prior&&$route!==$prior['route']&&$seo['canonical']===$prior['route'])$seo['canonical']=$route;
  foreach(all('SELECT id,draft,published FROM content WHERE id<>?',[$r['id']]) as $other){foreach(['draft','published'] as $kind){$v=json_decode($other[$kind]??'null',true);if($r['type']!=='global'&&$other['id']!=='globals'&&($v['route']??null)===$route)fail('Этот адрес уже занят другой страницей.',409);}}
  $map=$data['mediaMap']??[];if(!is_array($map))$map=[];
  foreach($map as $from=>$to){if(!one('SELECT id FROM media WHERE id=?',[$from])||!one('SELECT id FROM media WHERE id=? AND archived=0',[$to]))fail('Изображение не найдено.');}
  return ['fields'=>(object)$fields,'seo'=>$seo,'product'=>$product,'route'=>$route,'title'=>$title,'mediaMap'=>(object)$map,'hidden'=>(bool)($data['hidden']??false)];
}
function saveRecord(string $id,array $data,int $version,bool $publish=false):array{
  global $db,$actor;
  $r=record($id);if((int)$r['version']!==$version)fail('Другой пользователь уже изменил эту страницу. Обновите редактор, чтобы не потерять его изменения.',409);
  $data=validateData($data,$r);$json=encode($data);
  $db->beginTransaction();try{
    if(!one('SELECT id FROM revisions WHERE content_id=? LIMIT 1',[$id])) q('INSERT INTO revisions(id,content_id,actor,action,snapshot,created_at) VALUES(?,?,?,?,?,?)',[uuid(),$id,'Система','initial',$r['draft'],$r['updated_at']]);
    $s=q($publish?'UPDATE content SET draft=?,published=?,version=version+1,updated_at=?,published_at=?,archived=0 WHERE id=? AND version=?':'UPDATE content SET draft=?,version=version+1,updated_at=? WHERE id=? AND version=?',$publish?[$json,$json,now(),now(),$id,$version]:[$json,now(),$id,$version]);
    if(!$s->rowCount()){$db->rollBack();fail('Изменения уже были обновлены. Перезагрузите редактор.',409);}
    if($publish&&$r['published']){$old=json_decode($r['published'],true);if(($old['route']??'')!==$data['route']&&$r['type']!=='global')q('INSERT OR REPLACE INTO redirects(route,content_id) VALUES(?,?)',[$old['route'],$id]);}
    q('INSERT INTO revisions(id,content_id,actor,action,snapshot,created_at) VALUES(?,?,?,?,?,?)',[uuid(),$id,$actor['email']??'Система',$publish?'publish':'save',$json,now()]);
    audit($publish?'publish':'save',$id,$data['title']);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return recordView(record($id));
}

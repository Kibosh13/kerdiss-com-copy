<?php
function contentDetail(array $row):array {
  global $baseline;
  $v=recordView($row); $b=$baseline[$row['template_id']];
  $v['fields']=array_map(fn($f)=>array_merge($f,['defaultValue'=>$f['value'],'value'=>$v['data']['fields'][$f['id']]??$f['value']]),$b['fields']);
  $v['images']=$b['images']??[];
  return $v;
}
function api(string $path,string $method):never {
  global $db,$baseline,$actor,$origin,$dataDir,$secret;
  if($path==='/api/health'&&$method==='GET')jsonResponse(['ok'=>true]);
  if($path==='/api/auth/session'&&$method==='GET'){
    $u=sessionUser();jsonResponse(['user'=>$u?userPublic($u):null,'csrf'=>$u?csrf():null]);
  }
  if($path==='/api/auth/login'&&$method==='POST'){
    if(($_SERVER['HTTP_ORIGIN']??$origin)!==$origin)fail('Недопустимый источник запроса.',403);
    $d=input();$email=mb_strtolower(trim(str($d['email']??'',254)));$password=str($d['password']??'',200);
    if(!rateLimit('login-ip:'.ipKey(),30,900)||!rateLimit('login-account:'.hash('sha256',$email),10,900))fail('Слишком много попыток. Попробуйте через 15 минут.',429);
    $u=one('SELECT * FROM users WHERE email=? AND active=1',[$email]);
    $hash=$u['password_hash']??'$2y$12$hUBGHkBdGiIzTCA1wYYN2uFzDTLGQy21zrzM8bIIMsl9GBhOBT7vW';
    if(!password_verify($password,$hash)||!$u)fail('Неверный логин или пароль.',401);
    if($old=sessionUser())q('DELETE FROM sessions WHERE id=?',[$old['session_id']]);
    createSession($u['id']);q('UPDATE users SET last_login=? WHERE id=?',[now(),$u['id']]);$actor=$u;audit('login',$u['id'],'Вход в админку');jsonResponse(['user'=>userPublic($u),'csrf'=>csrf()]);
  }
  if($path==='/api/auth/logout'&&$method==='POST'){$u=requireUser();q('DELETE FROM sessions WHERE id=?',[$u['session_id']]);setcookie('ursar_session','',['expires'=>1,'path'=>'/','secure'=>str_starts_with($origin,'https://'),'httponly'=>true,'samesite'=>'Lax']);jsonResponse(['ok'=>true]);}
  if($path==='/api/auth/password'&&$method==='PUT'){
    $u=requireUser();$d=input();if(!password_verify(str($d['current']??'',200),$u['password_hash']))fail('Текущий пароль неверен.');
    $hash=passwordHash(str($d['password']??'',200));q('UPDATE users SET password_hash=? WHERE id=?',[$hash,$u['id']]);q('DELETE FROM sessions WHERE user_id=?',[$u['id']]);createSession($u['id']);audit('password',$u['id'],'Смена пароля');jsonResponse(['ok'=>true,'csrf'=>csrf()]);
  }
  if($path==='/api/enquiries'&&$method==='POST'){
    if(($_SERVER['HTTP_ORIGIN']??$origin)!==$origin)fail('Отправьте форму со страницы сайта.',403);
    $d=input();if(trim((string)($d['website']??''))!=='')fail('Не удалось отправить сообщение.');
    if(($d['consent']??false)!==true)fail('Подтвердите согласие на обработку персональных данных.');
    $id=str($d['id']??'',40);if(!preg_match('/^[a-f0-9-]{32,36}$/',$id))fail('Обновите страницу и повторите отправку.');
    $name=trim(str($d['name']??'',150));$email=trim(str($d['email']??'',254));$phone=trim(str($d['phone']??'',60));$message=trim(str($d['message']??'',10000));
    if(!$name||(!$email&&!$phone))fail('Укажите имя и телефон или email.');
    if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Проверьте email.');
    if($phone&&!preg_match('/^[+()\d\s.-]{7,60}$/',$phone))fail('Проверьте номер телефона.');
    if(one('SELECT id FROM leads WHERE id=?',[$id]))jsonResponse(['ok'=>true,'message'=>settings()['formSuccess']]);
    if(!rateLimit('form:'.ipKey(),8,3600))fail('Слишком много сообщений. Свяжитесь с нами по телефону или попробуйте позже.',429);
    q('INSERT INTO leads(id,name,email,phone,message,page,form_name,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$name,$email,$phone,$message,str($d['page']??'/',500),str($d['formName']??'Форма сайта',100),now(),now()]);
    jsonResponse(['ok'=>true,'message'=>settings()['formSuccess']],201);
  }
  if(!str_starts_with($path,'/api/admin/'))fail('Не найдено.',404);
  $u=requireUser();
  if($path==='/api/admin/dashboard'&&$method==='GET'){
    $rows=all('SELECT * FROM content WHERE archived=0 AND type<>\'global\' ORDER BY updated_at DESC');$items=array_map('recordView',$rows);
    $pages=array_values(array_filter($items,fn($x)=>$x['type']!=='product'));$products=array_values(array_filter($items,fn($x)=>$x['type']==='product'));
    usort($pages,fn($a,$b)=>($a['id']==='home'?-1:($b['id']==='home'?1:strcmp($a['title'],$b['title']))));
    $canLeads=in_array($u['role'],['admin','manager']);
    jsonResponse(['counts'=>['pages'=>count($pages),'products'=>count($products),'media'=>(int)one('SELECT COUNT(*) n FROM media WHERE archived=0')['n'],'newLeads'=>$canLeads?(int)one("SELECT COUNT(*) n FROM leads WHERE archived=0 AND status='new'")['n']:null],'pages'=>$pages,'products'=>$products,'leads'=>$canLeads?all('SELECT * FROM leads WHERE archived=0 ORDER BY created_at DESC LIMIT 5'):[],'activity'=>all('SELECT * FROM audit ORDER BY created_at DESC LIMIT 8')]);
  }
  if($path==='/api/admin/content'&&$method==='GET'){requireUser('content');jsonResponse(['items'=>array_map('recordView',all('SELECT * FROM content ORDER BY updated_at DESC'))]);}
  if($path==='/api/admin/content'&&$method==='POST'){
    requireUser('content');$d=input();$source=record(str($d['templateId']??'home',200));if($source['type']==='global')fail('Нельзя копировать общие блоки.');
    $copy=($d['duplicate']??false)===true;$data=$copy?json_decode($source['draft'],true):['fields'=>[],'seo'=>$baseline[$source['template_id']]['seo'],'product'=>$baseline[$source['template_id']]['product'],'mediaMap'=>new stdClass(),'hidden'=>false];
    $id=uuid();$title=trim(str($d['title']??'Новая страница',200));$slug=trim(str($d['slug']??'new-'.substr($id,0,8),150));
    $data['title']=$title;foreach($baseline[$source['template_id']]['fields'] as $f)if($f['tag']==='h1'&&$f['kind']==='text')$data['fields'][$f['id']]=$title;$data['route']='/'.$slug.'/';$data['seo']['title']=$title.' — URSAR';$data['seo']['ogTitle']=$title.' — URSAR';$data['seo']['description']='';$data['seo']['ogDescription']='';$data['seo']['canonical']=$data['route'];
    if($source['type']==='product'){$data['product']['name']=$title;$data['product']['slug']=$slug;$data['route']='/product/'.$slug.'/';$data['seo']['canonical']=$data['route'];if(!$copy){$data['product']['description']='';$data['product']['gallery']=[];$data['product']['price']='';}}
    $data=validateData($data,['id'=>$id,'type'=>$source['type'],'template_id'=>$source['template_id']]);
    q('INSERT INTO content(id,type,template_id,draft,updated_at) VALUES(?,?,?,?,?)',[$id,$source['type'],$source['template_id'],encode($data),now()]);audit('create',$id,$title);jsonResponse(contentDetail(record($id)),201);
  }
  if(preg_match('~^/api/admin/content/([a-zA-Z0-9_-]+)(?:/(publish|archive|restore|revisions))?$~',$path,$m)){
    requireUser('content');$id=$m[1];$action=$m[2]??'';$r=record($id);
    if($method==='GET'&&$action==='')jsonResponse(contentDetail($r));
    if(($method==='PUT'&&$action==='')||($method==='POST'&&$action==='publish')){$d=input();jsonResponse(saveRecord($id,$d['data']??[],(int)($d['version']??0),$action==='publish'));}
    if($method==='GET'&&$action==='revisions')jsonResponse(['items'=>all('SELECT id,actor,action,created_at FROM revisions WHERE content_id=? ORDER BY rowid DESC LIMIT 100',[$id])]);
    if($method==='POST'&&$action==='restore'){$d=input();$rev=one('SELECT snapshot FROM revisions WHERE id=? AND content_id=?',[str($d['revisionId']??'',40),$id]);if(!$rev)fail('Версия не найдена.',404);jsonResponse(saveRecord($id,json_decode($rev['snapshot'],true),(int)($d['version']??0)));}
    if($method==='POST'&&$action==='archive'){
      if(in_array($id,['home','globals']))fail('Главную страницу и общие блоки нельзя удалить.');$d=input();$value=(bool)($d['archived']??true);
      if(!q('UPDATE content SET archived=?,version=version+1,updated_at=? WHERE id=? AND version=?',[(int)$value,now(),$id,(int)($d['version']??0)])->rowCount())fail('Страница уже изменилась. Обновите список.',409);
      audit($value?'archive':'unarchive',$id,recordView($r)['title']);jsonResponse(recordView(record($id)));
    }
  }
  if($path==='/api/admin/media'&&$method==='GET'){
    requireUser('content');if(isset($_GET['ids'])){$ids=array_values(array_filter(explode(',',str($_GET['ids'],12000)),fn($id)=>preg_match('/^[a-z0-9_]+$/',$id)));if(count($ids)>200)fail('Слишком много изображений.');$items=$ids?all('SELECT * FROM media WHERE archived=0 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids):[];jsonResponse(['items'=>$items,'total'=>count($items),'page'=>1]);}$search=mb_strtolower(mb_substr($_GET['q']??'',0,200));$page=max(1,min(10000,(int)($_GET['page']??1)));$where='archived=0 AND (mb_lower(name) LIKE ? OR mb_lower(alt) LIKE ?)';$args=['%'.$search.'%','%'.$search.'%'];
    $total=(int)one('SELECT COUNT(*) n FROM media WHERE '.$where,$args)['n'];jsonResponse(['items'=>all('SELECT * FROM media WHERE '.$where.' ORDER BY source DESC,created_at DESC,name LIMIT 48 OFFSET '.(($page-1)*48),$args),'total'=>$total,'page'=>$page]);
  }
  if($path==='/api/admin/media'&&$method==='POST'){
    requireUser('content');$f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)fail('Файл не загружен. Максимальный размер — 15 МБ.');
    if($f['size']>15*1024*1024)fail('Файл больше 15 МБ.',413);
    $info=@getimagesize($f['tmp_name']);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP,IMAGETYPE_GIF],true))fail('Выберите JPEG, PNG, WebP или GIF.');
    if($info[0]*$info[1]>25000000)fail('Размер изображения превышает 25 мегапикселей.');
    $image=@imagecreatefromstring(file_get_contents($f['tmp_name']));if(!$image)fail('Не удалось прочитать изображение.');imagepalettetotruecolor($image);imagealphablending($image,true);imagesavealpha($image,true);
    $id='upload_'.uuid();$file=$dataDir.'/uploads/'.$id.'.webp';if(!imagewebp($image,$file,92))fail('Не удалось сохранить изображение.',500);imagedestroy($image);chmod($file,0640);
    $name=mb_substr(basename($f['name']),0,250);$url='/media/'.$id.'.webp';
    q('INSERT INTO media(id,url,name,type,size,alt,source,width,height,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$url,$name,'webp',filesize($file),'','upload',$info[0],$info[1],now()]);audit('upload',$id,$name);jsonResponse(one('SELECT * FROM media WHERE id=?',[$id]),201);
  }
  if(preg_match('~^/api/admin/media/([a-z0-9_]+)(?:/(replace))?$~',$path,$m)){
    requireUser('content');$media=one('SELECT * FROM media WHERE id=?',[$m[1]]);if(!$media)fail('Изображение не найдено.',404);
    if($method==='PUT'){$d=input();q('UPDATE media SET alt=?,name=? WHERE id=?',[str($d['alt']??'',1000),str($d['name']??$media['name'],250),$m[1]]);audit('media-edit',$m[1],$media['name']);jsonResponse(one('SELECT * FROM media WHERE id=?',[$m[1]]));}
    if($method==='POST'&&($m[2]??'')==='replace'){
      $d=input();$to=str($d['targetId']??'',100);if(!one('SELECT id FROM media WHERE id=? AND archived=0',[$to]))fail('Выберите новое изображение.');
      $g=record('globals');$data=json_decode($g['draft'],true);$ids=[$media['id']];
      if(($d['family']??false)&&$media['source']==='original'){$stem=preg_replace('/(?:-\d+x\d+|-scaled)?\.[^.]+$/','',$media['url']);foreach(all("SELECT id,url FROM media WHERE source='original'") as $other)if(preg_replace('/(?:-\d+x\d+|-scaled)?\.[^.]+$/','',$other['url'])===$stem)$ids[]=$other['id'];}
      foreach(array_unique($ids) as $id)$data['mediaMap'][$id]=$to;
      $r=saveRecord('globals',$data,(int)($d['version']??0),($d['publish']??false)===true);jsonResponse(['record'=>$r,'replaced'=>count(array_unique($ids))]);
    }
  }
  if($path==='/api/admin/leads'&&$method==='GET'){
    requireUser('leads');$status=$_GET['status']??'';$search=mb_strtolower(mb_substr($_GET['q']??'',0,200));$where='archived=? AND (mb_lower(name) LIKE ? OR mb_lower(email) LIKE ? OR phone LIKE ? OR mb_lower(message) LIKE ?)';$args=[$status==='trash'?1:0,...array_fill(0,4,'%'.$search.'%')];if(in_array($status,['new','working','closed','spam'])){$where.=' AND status=?';$args[]=$status;}
    $page=max(1,(int)($_GET['page']??1));jsonResponse(['items'=>all('SELECT * FROM leads WHERE '.$where.' ORDER BY created_at DESC LIMIT 50 OFFSET '.(($page-1)*50),$args),'total'=>(int)one('SELECT COUNT(*) n FROM leads WHERE '.$where,$args)['n'],'counts'=>all('SELECT status,COUNT(*) count FROM leads WHERE archived=0 GROUP BY status')]);
  }
  if($path==='/api/admin/leads/export'&&$method==='GET'){
    requireUser('leads');header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ursar-leads-'.date('Y-m-d').'.csv"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";$fp=fopen('php://output','w');fputcsv($fp,['Дата','Имя','Телефон','Email','Сообщение','Статус','Заметка','Страница'],';', '"','');
    foreach(all('SELECT * FROM leads WHERE archived=0 ORDER BY created_at DESC') as $l){$v=array_map(fn($s)=>preg_match('/^[=+@\-\t\r]/',(string)$s)?"'".$s:$s,[$l['created_at'],$l['name'],$l['phone'],$l['email'],$l['message'],$l['status'],$l['notes'],$l['page']]);fputcsv($fp,$v,';','"','');}fclose($fp);exit;
  }
  if(preg_match('~^/api/admin/leads/([a-f0-9-]+)$~',$path,$m)){
    requireUser('leads');$l=one('SELECT * FROM leads WHERE id=?',[$m[1]]);if(!$l)fail('Обращение не найдено.',404);if($method==='GET')jsonResponse($l);
    if($method==='PUT'){$d=input();$status=$d['status']??$l['status'];if(!in_array($status,['new','working','closed','spam']))fail('Некорректный статус.');q('UPDATE leads SET status=?,notes=?,archived=?,updated_at=? WHERE id=?',[$status,str($d['notes']??$l['notes'],15000),(int)(bool)($d['archived']??$l['archived']),now(),$m[1]]);audit('lead-status',$m[1],'Обновление обращения',['status'=>$status]);jsonResponse(one('SELECT * FROM leads WHERE id=?',[$m[1]]));}
  }
  if($path==='/api/admin/settings'){
    requireUser('admin');$r=one('SELECT * FROM settings WHERE id=1');if($method==='GET')jsonResponse(['data'=>json_decode($r['value'],true),'version'=>(int)$r['version'],'origin'=>$origin]);
    if($method==='PUT'){$d=input();$v=$d['data']??[];$value=['siteName'=>str($v['siteName']??'',150),'description'=>str($v['description']??'',1000),'noindex'=>(bool)($v['noindex']??true),'formSuccess'=>str($v['formSuccess']??'',1000),'consentText'=>str($v['consentText']??'',1000)];if(!$value['siteName']||!$value['consentText']||!$value['formSuccess'])fail('Заполните название сайта и настройки форм.');if(!q('UPDATE settings SET value=?,version=version+1 WHERE id=1 AND version=?',[encode($value),(int)($d['version']??0)])->rowCount())fail('Настройки уже изменились. Обновите страницу.',409);audit('settings','site','Настройки сайта');jsonResponse(['data'=>$value,'version'=>(int)$r['version']+1,'origin'=>$origin]);}
  }
  if($path==='/api/admin/users'){
    requireUser('admin');if($method==='GET')jsonResponse(['items'=>array_map('userPublic',all('SELECT * FROM users ORDER BY created_at'))]);
    if($method==='POST'){$d=input();$email=mb_strtolower(trim(str($d['email']??'',254)));$name=trim(str($d['name']??'',150));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!$name)fail('Укажите имя и корректный email.');$role=$d['role']??'editor';if(!in_array($role,['admin','editor','manager']))fail('Выберите роль.');if(one('SELECT id FROM users WHERE email=?',[$email]))fail('Пользователь с таким email уже существует.',409);$id=uuid();q('INSERT INTO users(id,email,name,password_hash,role,created_at) VALUES(?,?,?,?,?,?)',[$id,$email,$name,passwordHash(str($d['password']??'',200)),$role,now()]);audit('user-create',$id,$name);jsonResponse(userPublic(one('SELECT * FROM users WHERE id=?',[$id])),201);}
  }
  if(preg_match('~^/api/admin/users/([a-f0-9]+)$~',$path,$m)&&$method==='PUT'){
    requireUser('admin');$d=input();$target=one('SELECT * FROM users WHERE id=?',[$m[1]]);if(!$target)fail('Пользователь не найден.',404);$role=$d['role']??$target['role'];$active=(int)(bool)($d['active']??$target['active']);if(!in_array($role,['admin','editor','manager']))fail('Выберите роль.');if($target['id']===$u['id']&&(!$active||$role!=='admin'))fail('Нельзя отключить собственную учётную запись или снять свои права администратора.');
    $name=trim(str($d['name']??$target['name'],150));$password=$d['password']??'';$hash=$password!==''?passwordHash(str($password,200)):$target['password_hash'];q('UPDATE users SET name=?,role=?,active=?,password_hash=? WHERE id=?',[$name,$role,$active,$hash,$m[1]]);if(!$active||$role!==$target['role']||$password!=='')q('DELETE FROM sessions WHERE user_id=?',[$m[1]]);audit('user-edit',$m[1],$name);jsonResponse(userPublic(one('SELECT * FROM users WHERE id=?',[$m[1]])));
  }
  if($path==='/api/admin/audit'&&$method==='GET'){requireUser('admin');jsonResponse(['items'=>all('SELECT * FROM audit ORDER BY created_at DESC LIMIT 200')]);}
  if($path==='/api/admin/export'&&$method==='GET'){
    requireUser('admin');header('Content-Disposition: attachment; filename="ursar-content-'.date('Y-m-d').'.json"');jsonResponse(['schema'=>1,'exportedAt'=>now(),'content'=>all('SELECT * FROM content'),'media'=>all('SELECT * FROM media'),'settings'=>settings()]);
  }
  fail('Действие не найдено.',404);
}

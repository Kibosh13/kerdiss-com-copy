<?php
// Run only over SSH/CLI; passwords are read from stdin and never stored in source.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../server/bootstrap.php';
$command=$argv[1]??'';
if($command==='create'){
  $email=$argv[2]??'';$name=$argv[3]??'Администратор';if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Укажите email.\n");exit(1);}if(one('SELECT id FROM users WHERE email=?',[$email])){fwrite(STDERR,"Пользователь уже существует.\n");exit(1);}
  $password=trim(fgets(STDIN));$hash=passwordHash($password);$id=uuid();q('INSERT INTO users(id,email,name,password_hash,role,created_at) VALUES(?,?,?,?,?,?)',[$id,$email,$name,$hash,'admin',now()]);echo "Администратор создан.\n";
}elseif($command==='backup'){
  $folder=$dataDir.'/backups';if(!is_dir($folder))mkdir($folder,0700,true);$file=$folder.'/ursar-'.gmdate('Ymd-His').'.sqlite';$source=new SQLite3($dataDir.'/ursar.sqlite',SQLITE3_OPEN_READONLY);$target=new SQLite3($file);if(!$source->backup($target))throw new RuntimeException('Не удалось создать резервную копию.');$target->close();$source->close();chmod($file,0600);echo $file."\n";
}else{echo "php scripts/admin.php create EMAIL NAME < password-input\nphp scripts/admin.php backup\n";exit(1);}

#!/usr/bin/env python3
"""Incremental SFTP deployment. Password is prompted, never passed on the command line.
Requires paramiko in the deployment environment; it is not a website dependency.
"""
import argparse,getpass,hashlib,json,os,posixpath,shlex,stat,sys,time
from pathlib import Path
import paramiko
ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--host',required=True);p.add_argument('--user',required=True);p.add_argument('--app-dir',required=True);p.add_argument('--webroot',required=True);p.add_argument('--origin',required=True);p.add_argument('--activate',action='store_true');p.add_argument('--initialize',action='store_true');p.add_argument('--access-file',type=Path);args=p.parse_args()
site_parent=posixpath.dirname(args.webroot.rstrip('/'))
if not args.app_dir.startswith(site_parent+'/') or args.app_dir.startswith(args.webroot.rstrip('/')+'/'):p.error('Приложение должно находиться внутри каталога этого сайта, рядом с public_html, чтобы сохранить изоляцию Beget.')
ssh=paramiko.SSHClient();ssh.load_system_host_keys();ssh.set_missing_host_key_policy(paramiko.RejectPolicy());ssh.connect(args.host,username=args.user,password=getpass.getpass('Пароль SSH хостинга: '),look_for_keys=False,allow_agent=False,timeout=30)
sftp=ssh.open_sftp()
def exists(path):
    try:return sftp.stat(path)
    except FileNotFoundError:return None
    except OSError as e:
        if e.errno==2:return None
        raise

def mkdir(path):
    if not exists(path):mkdir(posixpath.dirname(path));sftp.mkdir(path,0o750)
def run(cmd,input=None):
    stdin,stdout,stderr=ssh.exec_command(cmd,timeout=120)
    if input is not None:stdin.write(input);stdin.flush()
    stdin.channel.shutdown_write();out=stdout.read().decode();err=stderr.read().decode();code=stdout.channel.recv_exit_status()
    if code:raise RuntimeError(f'Команда завершилась с кодом {code}: {err[:2000]}')
    return out

def write(path,text,mode=0o640):
    mkdir(posixpath.dirname(path));tmp=path+'.uploading'
    with sftp.file(tmp,'w') as f:f.write(text)
    sftp.chmod(tmp,mode);sftp.posix_rename(tmp,path)

try:
    marker=args.app_dir+'/.ursar-managed'
    if exists(args.app_dir) and not exists(marker) and sftp.listdir(args.app_dir):raise RuntimeError('Каталог приложения не принадлежит этому проекту. Выберите пустой каталог.')
    mkdir(args.app_dir);write(marker,'URSAR CMS\n')
    manifestPath=args.app_dir+'/.release-manifest.json';old={}
    if exists(manifestPath):
        with sftp.file(manifestPath) as f:old=json.load(f)
    # Verify what is actually present, including a previously interrupted upload.
    code='$root=getcwd();$out=[];foreach(["site","server","cms/public","dist/admin","dist/content","dist/templates","scripts"] as $d){if(!is_dir($d))continue;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&!str_ends_with($f->getPathname(),".uploading"))$out[$f->getPathname()]=hash_file("sha256",$f->getPathname());}}echo json_encode($out);'
    actual=json.loads(run('cd '+shlex.quote(args.app_dir)+' && /usr/local/bin/php8.4 -r '+shlex.quote(code)))
    files=[]
    for folder in ['site','server','cms/public','dist/admin','dist/content','dist/templates']:
        files.extend(f for f in (ROOT/folder).rglob('*') if f.is_file())
    files.append(ROOT/'scripts/admin.php')
    manifest={};changed=[];total=0
    for f in files:
        rel=f.relative_to(ROOT).as_posix();digest=hashlib.sha256(f.read_bytes()).hexdigest();manifest[rel]=digest
        if actual.get(rel)!=digest:
            if rel in old and actual.get(rel) not in [None,old[rel]]:raise RuntimeError('Файл на сервере изменён вне публикации: '+rel)
            changed.append((f,rel));total+=f.stat().st_size
    print(f'Загрузка: {len(changed)} изменённых файлов, {total/1048576:.1f} МБ',flush=True)
    done=0;start=time.time()
    for i,(f,rel) in enumerate(changed,1):
        remote=args.app_dir+'/'+rel;mkdir(posixpath.dirname(remote));tmp=remote+'.uploading'
        sftp.put(str(f),tmp,confirm=False);sftp.chmod(tmp,0o644 if rel.startswith(('site/','dist/admin/','cms/public/')) else 0o640);sftp.posix_rename(tmp,remote);done+=f.stat().st_size
        if i%150==0 or i==len(changed):print(f'{i}/{len(changed)} · {done/1048576:.1f} МБ · {int(time.time()-start)} с',flush=True)
    mkdir(args.app_dir+'/data')
    config=args.app_dir+'/data/config.php'
    # Hosting runtime data and credentials stay outside the public directory.
    if not exists(config):write(config,"<?php return ['data_dir'=>"+repr(args.app_dir+'/data')+",'origin'=>"+repr(args.origin.rstrip('/'))+"];\n")
    print(run('cd '+shlex.quote(args.app_dir)+' && /usr/local/bin/php8.4 -l server/app.php').strip(),flush=True)
    if args.initialize:
        if not args.access_file:raise RuntimeError('Укажите локальный файл для новых данных доступа.')
        if args.access_file.exists():raise RuntimeError('Файл доступа уже существует; повторная инициализация запрещена.')
        import secrets
        password=secrets.token_urlsafe(24)
        result=run('cd '+shlex.quote(args.app_dir)+" && /usr/local/bin/php8.4 scripts/admin.php create info@ursar.ru 'Администратор URSAR'",password+'\n')
        args.access_file.parent.mkdir(parents=True,exist_ok=True)
        fd=os.open(args.access_file,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f:f.write('URSAR — доступ к админке\n\nАдрес: '+args.origin.rstrip('/')+'/admin/\nЛогин: info@ursar.ru\nПароль: '+password+'\n\nПароль можно изменить в админке: Настройки → Мой пароль.\nДанные хостинга в этом файле не хранятся.\n')
        print(result.strip(),flush=True)
    if args.activate:
        run('chmod 711 '+shlex.quote(args.app_dir)+' '+shlex.quote(args.app_dir+'/dist')+' '+shlex.quote(args.app_dir+'/cms'))
        for folder in ['site','dist/admin','cms/public']:
            path=args.app_dir+'/'+folder
            run('find '+shlex.quote(path)+' -type d -exec chmod 755 {} \; && find '+shlex.quote(path)+' -type f -exec chmod 644 {} \;')
        mkdir(args.webroot)
        run('find '+shlex.quote(args.app_dir+'/server')+' '+shlex.quote(args.app_dir+'/dist/content')+' '+shlex.quote(args.app_dir+'/dist/templates')+' -type d -exec chmod 750 {} \;')
        run('find '+shlex.quote(args.app_dir+'/server')+' '+shlex.quote(args.app_dir+'/dist/content')+' '+shlex.quote(args.app_dir+'/dist/templates')+' -type f -exec chmod 640 {} \;')
        for folder in ['data','data/uploads']:sftp.chmod(args.app_dir+'/'+folder,0o770)
        for name in ['ursar.sqlite','ursar.sqlite-wal','ursar.sqlite-shm']:
            if exists(args.app_dir+'/data/'+name):sftp.chmod(args.app_dir+'/data/'+name,0o660)
        for name in ['config.php','secret']:
            if exists(args.app_dir+'/data/'+name):sftp.chmod(args.app_dir+'/data/'+name,0o640)
        original=args.webroot+'/index.php';backup=args.app_dir+'/data/original-index.php'
        if exists(original) and not exists(backup):sftp.rename(original,backup)
        front='<?php require '+repr(args.app_dir+'/server/app.php')+';\n'
        write(original,front)
        for name,target in [('wp-content','site/wp-content'),('wp-includes','site/wp-includes'),('_preserved','site/_preserved'),('_external','site/_external'),('_admin','dist/admin')]:
            link=args.webroot+'/'+name
            try:current=sftp.lstat(link)
            except OSError:current=None
            if current:
                if not stat.S_ISLNK(current.st_mode) or sftp.readlink(link)!=args.app_dir+'/'+target:raise RuntimeError('В webroot уже существует чужой каталог: '+name)
            else:sftp.symlink(args.app_dir+'/'+target,link)
        mkdir(args.webroot+'/_cms');sftp.chmod(args.webroot+'/_cms',0o755)
        for name in ['public.js','public.css']:
            link=args.webroot+'/_cms/'+name;target=args.app_dir+'/cms/public/'+name
            try:current=sftp.lstat(link)
            except OSError:current=None
            if current:
                if not stat.S_ISLNK(current.st_mode) or sftp.readlink(link)!=target:raise RuntimeError('Чужой файл '+link)
            else:sftp.symlink(target,link)
        write(args.webroot+'/.htaccess',(ROOT/'cms/public/.htaccess').read_text())
        write(args.webroot+'/.user.ini','upload_max_filesize=16M\npost_max_size=20M\nmemory_limit=256M\ndisplay_errors=Off\nlog_errors=On\n')
        print('Сайт активирован.',flush=True)
    write(manifestPath,json.dumps(manifest,ensure_ascii=False))
    print('Готово. Передано только изменённых файлов: '+str(len(changed)),flush=True)
finally:sftp.close();ssh.close()

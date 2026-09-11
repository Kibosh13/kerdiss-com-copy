"""End-to-end API and rendering checks against an isolated temporary SQLite database."""
import base64,copy,http.cookiejar,json,os,secrets,shutil,socket,subprocess,tempfile,time,unittest,urllib.error,urllib.request,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
class Client:
    def __init__(self,origin):
        self.origin=origin;self.csrf='';self.cookies=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
    def request(self,path,method='GET',body=None,headers=None,csrf=True):
        h={'Origin':self.origin};h.update(headers or {})
        if csrf and self.csrf:h['X-CSRF-Token']=self.csrf
        if isinstance(body,(dict,list)):h['Content-Type']='application/json';body=json.dumps(body).encode()
        try:r=self.opener.open(urllib.request.Request(self.origin+path,data=body,headers=h,method=method),timeout=30)
        except urllib.error.HTTPError as e:r=e
        raw=r.read();ctype=r.headers.get('Content-Type','');data=json.loads(raw) if 'application/json' in ctype else raw.decode('utf-8','replace')
        return r.status,data,r.headers
    def login(self,email,password):
        status,d,_=self.request('/api/auth/login','POST',{'email':email,'password':password});assert status==200,(status,d);self.csrf=d['csrf'];return d
class CMS(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='ursar-tests-');cls.dir=Path(cls.tmp.name)
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        cls.origin=f'http://127.0.0.1:{port}'
        cfg=cls.dir/'config.php';cfg.write_text("<?php return ['data_dir'=>"+repr(str(cls.dir/'data'))+",'origin'=>"+repr(cls.origin)+"];")
        env={**os.environ,'URSAR_CONFIG':str(cfg)};cls.password=secrets.token_urlsafe(25)
        subprocess.run([PHP,str(ROOT/'scripts/admin.php'),'create','admin@example.test','Test Admin'],input=cls.password+'\n',env=env,text=True,check=True,capture_output=True)
        cls.log=open(cls.dir/'server.log','w');cls.server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'cms/public'),str(ROOT/'cms/public/router.php')],cwd=ROOT,env=env,stdout=cls.log,stderr=cls.log)
        for _ in range(60):
            try:urllib.request.urlopen(cls.origin+'/api/health',timeout=1);break
            except Exception:time.sleep(.1)
        cls.admin=Client(cls.origin);cls.admin.login('admin@example.test',cls.password);cls.public=Client(cls.origin)
    @classmethod
    def tearDownClass(cls):cls.server.terminate();cls.server.wait(timeout=5);cls.log.close();cls.tmp.cleanup()
    def get(self,p):
        s,d,h=self.admin.request(p);self.assertEqual(s,200,d);return d
    def test_01_auth_and_private_paths(self):
        for endpoint in ['/api/admin/content','/api/admin/media','/api/admin/leads','/api/admin/users','/api/admin/settings','/api/admin/export']:
            self.assertEqual(self.public.request(endpoint)[0],401,endpoint)
        self.assertEqual(self.public.request('/?cms_preview=home')[0],401)
        for path in ['/data/config.php','/data/ursar.sqlite','/server/bootstrap.php','/.git/config','/dist/content/index.json','/media/../data/secret','/_admin/../../data/secret']:
            self.assertEqual(self.public.request(path)[0],404,path)
        settings=self.get('/api/admin/settings')
        self.assertEqual(self.admin.request('/api/admin/settings','PUT',settings,csrf=False)[0],403)
        self.assertEqual(self.admin.request('/api/admin/settings','PUT',settings,headers={'Origin':'https://evil.example'})[0],403)
    def test_02_pages_drafts_publish_xss_history_conflicts(self):
        r=self.get('/api/admin/content/home');data=copy.deepcopy(r['data']);field=next(f for f in r['fields'] if f['kind']=='text' and f['tag']=='h2')
        value='Проверка текста <img src=x onerror=alert(1)>'
        data['fields'][field['id']]=value
        s,d,_=self.admin.request('/api/admin/content/home','PUT',{'data':data,'version':r['version']});self.assertEqual(s,200,d)
        self.assertNotIn('Проверка текста',self.public.request('/')[1]);preview=self.admin.request('/?cms_preview=home')[1];self.assertIn('Проверка текста &lt;img',preview);self.assertNotIn('<img src=x',preview)
        self.assertEqual(self.admin.request('/api/admin/content/home','PUT',{'data':data,'version':r['version']})[0],409)
        s,p,_=self.admin.request('/api/admin/content/home/publish','POST',{'data':data,'version':d['version']});self.assertEqual(s,200,p);self.assertIn('Проверка текста',self.public.request('/')[1])
        revisions=self.get('/api/admin/content/home/revisions')['items'];initial=next(x for x in revisions if x['action']=='initial')
        s,restored,_=self.admin.request('/api/admin/content/home/restore','POST',{'revisionId':initial['id'],'version':p['version']});self.assertEqual(s,200,restored);self.assertIn('Проверка текста',self.public.request('/')[1]);self.assertNotIn('Проверка текста',self.admin.request('/?cms_preview=home')[1])
        self.assertEqual(self.admin.request('/api/admin/content/home/publish','POST',{'data':restored['data'],'version':restored['version']})[0],200)
        link=next(f for f in r['fields'] if f['kind']=='link');data['fields'][link['id']]='javascript:alert(1)'
        self.assertEqual(self.admin.request('/api/admin/content/home','PUT',{'data':data,'version':restored['version']+1})[0],400)
    def test_03_globals(self):
        g=self.get('/api/admin/content/globals');f=next(f for f in g['fields'] if f['kind']=='text');d=copy.deepcopy(g['data']);d['fields'][f['id']]='Общий тест URSAR';s,r,_=self.admin.request('/api/admin/content/globals/publish','POST',{'data':d,'version':g['version']});self.assertEqual(s,200,r)
        for page in ['/','/about/','/contact/']:self.assertIn('Общий тест URSAR',self.public.request(page)[1])
    def test_04_products(self):
        items=self.get('/api/admin/content')['items'];template=next(p for p in items if p['type']=='product')
        s,r,_=self.admin.request('/api/admin/content','POST',{'templateId':template['id'],'title':'Новая газонокосилка ТЕСТ','slug':'test-mower'});self.assertEqual(s,201,r)
        self.assertEqual(self.public.request('/product/test-mower/')[0],404)
        d=r['data'];d['product'].update({'category':'lawn-mower','price':'123450','availability':'in-stock','description':'Описание тестового товара','gallery':[template['hero']]})
        s,p,_=self.admin.request('/api/admin/content/'+r['id']+'/publish','POST',{'data':d,'version':r['version']});self.assertEqual(s,200,p)
        body=self.public.request('/product/test-mower/')[1];self.assertIn('Новая газонокосилка ТЕСТ',body);self.assertIn('123 450 ₽',body);self.assertIn('Описание тестового товара',body)
        for page in ['/shop/','/product-category/lawn-mower/']:self.assertIn('/product/test-mower/',self.public.request(page)[1])
        self.assertNotIn('/product/test-mower/',self.public.request('/product-category/skid-loader/')[1])
        d['product']['slug']='test-mower-renamed';s,p2,_=self.admin.request('/api/admin/content/'+r['id']+'/publish','POST',{'data':d,'version':p['version']});self.assertEqual(s,200,p2)
        self.assertIn('Новая газонокосилка ТЕСТ',self.public.request('/product/test-mower/')[1])
        self.assertEqual(self.admin.request('/api/admin/content/'+r['id']+'/archive','POST',{'version':p2['version'],'archived':True})[0],200)
        self.assertEqual(self.public.request('/product/test-mower-renamed/')[0],404);self.assertNotIn('/product/test-mower-renamed/',self.public.request('/shop/')[1])
    def test_05_media(self):
        import zlib,struct
        def chunk(t,d):return struct.pack('!I',len(d))+t+d+struct.pack('!I',zlib.crc32(t+d)&0xffffffff)
        png=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',1,1,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(b'\0\x80\x80\x80'))+chunk(b'IEND',b'')
        boundary='----ursar'+uuid.uuid4().hex
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode())
        s,m,_=self.admin.request('/api/admin/media','POST',body,{'Content-Type':'multipart/form-data; boundary='+boundary});self.assertEqual(s,201,m)
        s,_,h=self.public.request(m['url']);self.assertEqual(s,200);self.assertEqual(h['Content-Type'],'image/webp')
        images=self.get('/api/admin/media?q=ursar-transparent')['items'];self.assertTrue(images);original=images[0]
        g=self.get('/api/admin/content/globals');s,r,_=self.admin.request('/api/admin/media/'+original['id']+'/replace','POST',{'targetId':m['id'],'family':True,'publish':True,'version':g['version']});self.assertEqual(s,200,r);self.assertIn(m['url'],self.public.request('/')[1])
        home=self.get('/api/admin/content/home');data=copy.deepcopy(home['data']);target=self.get('/api/admin/media')['items'][5]
        data['mediaMap'][original['id']]=target['id'];s,saved,_=self.admin.request('/api/admin/content/home/publish','POST',{'data':data,'version':home['version']});self.assertEqual(s,200,saved)
        import re
        homehtml=self.public.request('/')[1];abouthtml=self.public.request('/about/')[1]
        def logo(body):return next(t for t in re.findall(r'<img\b[^>]+>',body) if 'class="custom-logo"' in t)
        self.assertIn(target['url'],logo(homehtml));self.assertIn(m['url'],logo(abouthtml))
        self.assertIn('content=home',homehtml)
        from urllib.parse import quote
        css=':root{background:url("'+original['url']+'")}'
        # The brand stylesheet is served in the page's scope as well as the shared scope.
        self.assertEqual(self.public.request('/_cms/style?file='+quote('/_preserved/brand/brand.css')+'&content=home')[0],200)
        bad=body.replace(png,b'<?php echo "danger"; ?>');self.assertEqual(self.admin.request('/api/admin/media','POST',bad,{'Content-Type':'multipart/form-data; boundary='+boundary})[0],400)
    def test_06_leads_idempotency_and_roles(self):
        d={'id':str(uuid.uuid4()),'name':'Тест формы','email':'visitor@example.test','phone':'+7 900 000 00 00','message':'=HYPERLINK("bad")','page':'/contact/','formName':'Проверка','consent':False}
        self.assertEqual(self.public.request('/api/enquiries','POST',d)[0],400);d['consent']=True
        self.assertEqual(self.public.request('/api/enquiries','POST',d)[0],201);self.assertEqual(self.public.request('/api/enquiries','POST',d)[0],200)
        rows=self.get('/api/admin/leads')['items'];self.assertEqual(len(rows),1);self.assertEqual(len(self.get('/api/admin/leads?q='+urllib.parse.quote('тест'))['items']),1)
        self.assertEqual(self.admin.request('/api/admin/leads/'+d['id'],'PUT',{'status':'working','notes':'Перезвонить'})[0],200)
        csv=self.admin.request('/api/admin/leads/export')[1];self.assertIn("'=HYPERLINK",csv)
        password=secrets.token_urlsafe(20)
        for role in ['editor','manager']:
            s,u,_=self.admin.request('/api/admin/users','POST',{'name':role,'email':role+'@example.test','password':password,'role':role});self.assertEqual(s,201,u)
            c=Client(self.origin);c.login(role+'@example.test',password);self.assertEqual(c.request('/api/admin/users')[0],403);self.assertEqual(c.request('/api/admin/settings')[0],403)
            self.assertEqual(c.request('/api/admin/leads')[0],200 if role=='manager' else 403)
            self.assertEqual(c.request('/api/admin/content')[0],200 if role=='editor' else 403)
            self.admin.request('/api/admin/users/'+u['id'],'PUT',{'active':False});self.assertEqual(c.request('/api/auth/session')[1]['user'],None)
    def test_07_settings_and_resources(self):
        s=self.get('/api/admin/settings');self.assertTrue(s['data']['noindex']);self.assertIn('Disallow: /',self.public.request('/robots.txt')[1]);self.assertIn('noindex',self.public.request('/')[1])
        for url in ['/_cms/public.js','/_cms/public.css','/_preserved/runtime.js','/_preserved/orders.json','/admin/']:
            self.assertEqual(self.public.request(url)[0],200,url)
        import re,html
        body=self.public.request('/')[1];css=re.findall('href="(/_cms/style[^\"]+)"',body);self.assertTrue(css)
        for url in css:self.assertEqual(self.public.request(html.unescape(url))[0],200,url)
        export=self.get('/api/admin/export');self.assertNotIn('users',export);self.assertNotIn('leads',export);self.assertNotIn('password_hash',json.dumps(export))
    def test_08_indexing_switch_and_backup(self):
        s=self.get('/api/admin/settings');s['data']['noindex']=False
        self.assertEqual(self.admin.request('/api/admin/settings','PUT',s)[0],200)
        self.assertNotIn('Disallow: /\n',self.public.request('/robots.txt')[1]);self.assertIn('<loc>'+self.origin+'/',self.public.request('/sitemap.xml')[1]);self.assertIn('content="index,follow"',self.public.request('/about/')[1])
        env={**os.environ,'URSAR_CONFIG':str(self.dir/'config.php')}
        result=subprocess.run([PHP,str(ROOT/'scripts/admin.php'),'backup'],env=env,text=True,capture_output=True)
        self.assertEqual(result.returncode,0,result.stderr);self.assertTrue(Path(result.stdout.strip()).is_file())
if __name__=='__main__':unittest.main(verbosity=2)

"""Read-only crawl of public pages and local styles, scripts, images and fonts."""
import concurrent.futures,html,json,re,sys,urllib.error,urllib.parse,urllib.request
from html.parser import HTMLParser
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=(sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:4311').rstrip('/')
manifest=json.loads((ROOT/'dist/content/index.json').read_text())
class Assets(HTMLParser):
 def __init__(self):super().__init__();self.refs=set();self.styles=[]
 def handle_starttag(self,tag,items):
  a=dict(items)
  if tag in ['img','script','source','video','audio']:
   for key in ['src','data-src','poster']:
    if a.get(key):self.refs.add(a[key])
   for key in ['srcset','data-srcset']:
    for v in a.get(key,'').split(','):
     if v.strip():self.refs.add(v.strip().split()[0])
  if tag=='link' and any(v in a.get('rel','').split() for v in ['stylesheet','icon','preload','apple-touch-icon']):
   if a.get('href'):self.refs.add(a['href'])
  if a.get('style'):self.styles.append(a['style'])
def local(url,base=BASE+'/'):
 u=urllib.parse.urljoin(base,html.unescape(url));p=urllib.parse.urlsplit(u);target=urllib.parse.urlsplit(BASE)
 if p.scheme not in ['http','https'] or p.netloc!=target.netloc:return None
 return urllib.parse.urlunsplit((p.scheme,p.netloc,urllib.parse.quote(urllib.parse.unquote(p.path),safe='/@:+-_.~'),p.query,''))
def request(url,head=False):
 try:
  with urllib.request.urlopen(urllib.request.Request(url,method='HEAD' if head else 'GET',headers={'User-Agent':'URSAR-Verification/1.0'}),timeout=40) as r:return {'url':url,'status':r.status,'type':r.headers.get('Content-Type',''),'body':'' if head else r.read().decode('utf-8','replace')}
 except urllib.error.HTTPError as e:return {'url':url,'status':e.code,'type':'','body':''}
 except Exception as e:return {'url':url,'status':str(e),'type':'','body':''}
pages=[local(p['route']) for p in manifest['pages'] if p['type']!='global'];resources=set();failures=[];titles=[]
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
 for r in pool.map(request,pages):
  if r['status']!=200:failures.append({'url':r['url'],'status':r['status']});continue
  doc=Assets();doc.feed(r['body']);resources.update(filter(None,(local(u,r['url']) for u in doc.refs)))
  for css in doc.styles:
   for u in re.findall(r'url\(\s*[\'"]?([^\'"\)\s]+)',css):
    if v:=local(u,r['url']):resources.add(v)
  titles.append({'url':r['url'],'title':re.search(r'<title>(.*?)</title>',r['body'],re.S).group(1),'noindex':bool(re.search(r'<meta[^>]+name="robots"[^>]+noindex',r['body']))})
 cssurls=[u for u in resources if '.css' in u or '/_cms/style?' in u]
 for r in pool.map(request,cssurls):
  if r['status']!=200:failures.append({'url':r['url'],'status':r['status']});continue
  for u in re.findall(r'url\(\s*[\'"]?([^\'"\)\s]+)',r['body']):
   if v:=local(u,r['url']):resources.add(v)
 for r in pool.map(lambda u:request(u,True),resources):
  if r['status']!=200:failures.append({'url':r['url'],'status':r['status']})
report={'base':BASE,'pages':len(titles),'assets':len(resources),'noindexPages':sum(p['noindex'] for p in titles),'failures':failures,'titles':titles}
print(json.dumps({k:v for k,v in report.items() if k!='titles'},ensure_ascii=False,indent=2))
if len(sys.argv)>2:Path(sys.argv[2]).write_text(json.dumps(report,ensure_ascii=False,indent=2))
sys.exit(bool(failures))

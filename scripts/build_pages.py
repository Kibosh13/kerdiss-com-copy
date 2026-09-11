#!/usr/bin/env python3
"""Export authored static sources for a root or GitHub Pages project path."""
import argparse
from pathlib import Path
import re
import shutil

ROOT = Path(__file__).resolve().parents[1]


def rebase(text, base, origin):
    text = text.replace('https://kerdiss-preserved.nnn-iimeni.chatgpt.site', origin + base.rstrip('/'))
    # Use known site roots, so XPath expressions and JavaScript regexes stay intact.
    roots = '|'.join(re.escape(p.name) for p in (ROOT/'site').iterdir()) + '|wp-admin|wp-json'
    text = re.sub(r'''(["'=(])/(?=(?:''' + roots + r''')(?:/|[?"')]))''', lambda m: m[1] + base, text)
    text = re.sub(r'''(["'])\\/(?=(?:''' + roots + r''')(?:\\/|[?"']))''', lambda m: m[1] + base.replace('/', '\\/'), text)
    text = re.sub(r'''((?:href|action)=["'])/(?=[?#"'])''', lambda m: m[1] + base, text)
    # Additional candidates in responsive image lists and escaped HTML JSON.
    text = re.sub(r'(?<=,)/(?=wp-content/)', base, text)
    text = re.sub(r'(?<=, )/(?=wp-content/)', base, text)
    text = re.sub(r'(?<=&quot;)/(?=[A-Za-z_])', base, text)
    return text


def build(base='/kerdiss-com-copy/', origin='https://kibosh13.github.io'):
    if not base.startswith('/') or not base.endswith('/') or '..' in base:
        raise ValueError('Base must be an absolute directory path ending in /')
    out = ROOT / '.build' / 'pages'
    if out.exists(): shutil.rmtree(out)
    shutil.copytree(ROOT / 'site', out)
    for p in out.rglob('*'):
        if not p.is_file() or p.suffix not in {'.html','.css','.js','.json','.xml','.txt'}: continue
        text = rebase(p.read_text(), base, origin)
        if p.suffix == '.html':
            text = re.sub(r'<meta\b[^>]*name=["\'](?:robots|googlebot|bingbot)["\'][^>]*>', '', text, flags=re.I)
            tags = f'<meta name="robots" content="noindex, nofollow, noarchive">\n<meta name="googlebot" content="noindex, nofollow, noarchive">\n<meta name="site-base" content="{base}">'
            text = text.replace('<head>', '<head>\n' + tags, 1)
            text = re.sub(r'(http-equiv="refresh" content="0;url=)/', lambda m:m[1]+base, text)
        # Routes in catalog order data remain root based; runtime normalizes base.
        if p.name == 'orders.json': text = (ROOT / 'site' / '_preserved' / 'orders.json').read_text()
        p.write_text(text)
    # These files are search discovery data, unnecessary in a noindex demo.
    for p in out.glob('*sitemap*.xml'): p.unlink()
    (out/'robots.txt').write_text('User-agent: *\nDisallow: /\n')
    (out/'.nojekyll').touch()
    (out/'404.html').write_text(f'<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Страница не найдена</title></head><body><h1>Страница не найдена</h1><p><a href="{base}">На главную</a></p></body></html>')
    print(out)


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--base',default='/kerdiss-com-copy/')
    parser.add_argument('--origin',default='https://kibosh13.github.io')
    args=parser.parse_args()
    build(args.base,args.origin)

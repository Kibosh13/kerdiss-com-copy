#!/usr/bin/env python3
"""Publish changed static files with a normal, non-forced gh-pages push."""
from pathlib import Path
import shutil
import subprocess
from build_pages import ROOT, build


def git(*args, cwd=ROOT):
    return subprocess.check_output(['git', *args], cwd=cwd, text=True).strip()


if __name__ == '__main__':
    if git('branch','--show-current') != 'main' or git('status','--porcelain'):
        raise SystemExit('Commit your source changes on main first; the worktree must be clean.')
    git('fetch','origin','main','gh-pages')
    if git('rev-parse','HEAD') != git('rev-parse','origin/main'):
        raise SystemExit('Publish or integrate main first; local and remote main must agree.')
    build()
    checkout = ROOT/'.build'/'deploy'
    if checkout.exists():
        raise SystemExit('Previous .build/deploy worktree exists. Inspect it before removing it.')
    git('worktree','add','--detach',str(checkout),'origin/gh-pages')
    try:
        for p in checkout.iterdir():
            if p.name == '.git': continue
            if p.is_dir(): shutil.rmtree(p)
            else: p.unlink()
        shutil.copytree(ROOT/'.build'/'pages',checkout,dirs_exist_ok=True)
        git('add','--all',cwd=checkout)
        if git('status','--porcelain',cwd=checkout):
            git('commit','-m','Publish static demo from '+git('rev-parse','--short','HEAD'),cwd=checkout)
            git('push','origin','HEAD:gh-pages',cwd=checkout)
            print('Published commit',git('rev-parse','HEAD',cwd=checkout))
        else:
            print('No changed demo files.')
    finally:
        git('worktree','remove',str(checkout))

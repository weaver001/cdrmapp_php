#!/usr/bin/env python3
"""Populate Composer's dist cache from official codeload archives for locked GitHub refs.
Used when api.github.com is blocked but codeload.github.com is allowed.
Never rewrites lock metadata or disables certificate/checksum verification.
"""
import concurrent.futures, hashlib, json, pathlib, re, subprocess, sys, zipfile
root = pathlib.Path(__file__).resolve().parents[1]
cache = pathlib.Path(sys.argv[1])
lock = json.loads((root / 'backend/composer.lock').read_text())
def fetch(package):
    dist = package.get('dist', {})
    url = dist.get('url', '')
    match = re.fullmatch(r'https://api.github.com/repos/([^/]+/[^/]+)/zipball/([a-f0-9]{40})', url)
    if not match: return
    path = cache / 'files' / package['name'] / (hashlib.sha1(url.encode()).hexdigest()+'.zip')
    if path.exists(): return
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    subprocess.run(['curl', '--fail', '--silent', '--show-error', '--location', 'https://codeload.github.com/'+match[1]+'/legacy.zip/'+match[2], '-o', str(tmp)], check=True)
    with zipfile.ZipFile(tmp) as archive:
        if archive.testzip(): raise RuntimeError('Invalid archive: '+package['name'])
    if dist.get('shasum') and hashlib.sha1(tmp.read_bytes()).hexdigest()!=dist['shasum']:
        raise RuntimeError('Checksum mismatch: '+package['name'])
    tmp.replace(path)
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    list(pool.map(fetch, lock['packages']+lock.get('packages-dev', [])))
print('Locked GitHub archives cached from official codeload URLs.')

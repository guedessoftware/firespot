#!/usr/bin/env python3
"""Check publishable Git files and reachable history without printing secrets."""

import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
SAFE_UPLOADS = {
    'assets/ads/.htaccess', 'assets/ads/index.html',
    'portal-v3/uploads/.htaccess', 'portal-v3/uploads/index.html',
    'portal-v3/uploads/branding/.htaccess', 'portal-v3/uploads/branding/index.html',
}
PRIVATE_SUFFIXES = ('.key', '.pem', '.p12', '.pfx', '.jks', '.backup', '.bak',
                    '.old', '.orig', '.save', '.log', '.sqlite', '.sqlite3',
                    '.db', '.dump', '.zip', '.tar', '.tgz', '.tar.gz', '.7z', '.rar')
SECRET_PATTERNS = [
    re.compile(rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----'),
    re.compile(rb'\bgh[pousr]_[A-Za-z0-9_]{20,}\b'),
    re.compile(rb'\bgithub_pat_[A-Za-z0-9_]{20,}\b'),
    re.compile(rb'\bAKIA[0-9A-Z]{16}\b'),
    re.compile(rb'\bAPP_USR[-_][A-Za-z0-9_-]{15,}\b'),
    re.compile(rb'\bsk_(?:live|test)_[A-Za-z0-9]{12,}\b'),
    re.compile(rb'\bAIza[A-Za-z0-9_-]{20,}\b'),
]


def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=ROOT)


def private_path(name):
    parts = pathlib.PurePosixPath(name).parts
    base = parts[-1].lower()
    if name.startswith(('dev/.local/', 'releases/')):
        return True
    if any(p in {'backups', 'archive', '.vscode', 'logs', 'cache', 'storage', 'tmp', 'ops', '.well-known'} for p in parts):
        return True
    if base == '.env' or (base.startswith('.env.') and base != '.env.example'):
        return True
    if base in {'runtime-config.php', 'credentials.json'} or base.startswith(('id_rsa', 'id_ed25519', 'service-account', 'client_secret')):
        return True
    if base.endswith(PRIVATE_SUFFIXES) or '.legacy-' in base:
        return True
    if name.startswith(('assets/ads/', 'portal-v3/uploads/')) and name not in SAFE_UPLOADS:
        return True
    return False


def check_content(name, content):
    issues = []
    if any(pattern.search(content) for pattern in SECRET_PATTERNS):
        issues.append('padrão de credencial privada')
    if name.startswith('migrations/baseline/') and name.endswith('.sql'):
        if re.search(rb'^\s*(?:INSERT\s+INTO|REPLACE\s+INTO|COPY\s+\w+\s+FROM)\b', content, re.I | re.M):
            issues.append('registros em baseline de estrutura')
    if name.endswith('.env.example'):
        for line in content.decode('utf8', 'replace').splitlines():
            if '=' not in line or line.lstrip().startswith('#'):
                continue
            key, value = line.split('=', 1)
            if re.search(r'(?:PASSWORD|PASS|SECRET|TOKEN|HASH|KEY)$', key.strip()) and value.strip().strip('\"\''):
                issues.append('segredo preenchido no modelo de ambiente')
                break
    return issues


def main():
    failures = set()
    blobs = {}
    commits = git('rev-list', '--all').decode().splitlines()
    trees = [git('ls-files', '--stage', '-z')]
    trees += [git('ls-tree', '-rz', commit) for commit in commits]
    entries = 0
    for tree in trees:
        for record in tree.split(b'\0'):
            if not record:
                continue
            metadata, raw_name = record.split(b'\t', 1)
            fields = metadata.decode().split()
            mode = fields[0]
            sha = fields[2] if fields[1] == 'blob' else fields[1]
            name = raw_name.decode('utf8', 'replace')
            entries += 1
            if private_path(name):
                failures.add((name, 'arquivo privado ou de runtime versionado'))
            if mode in {'120000', '160000'}:
                failures.add((name, 'link simbólico ou submódulo exige revisão'))
                continue
            content = blobs.setdefault(sha, None)
            if content is None:
                content = git('cat-file', 'blob', sha)
                blobs[sha] = content
            for issue in check_content(name, content):
                failures.add((name, issue))
    for name, issue in sorted(failures):
        print(json.dumps({'arquivo': name, 'problema': issue}, ensure_ascii=False))
    print(f'Publicação: {len(blobs)} blobs, {len(commits)} commits, {len(failures)} problemas.')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())

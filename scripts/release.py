#!/usr/bin/env python3
"""Build, check, apply and roll back reviewed code deltas; never deploy a database."""

import argparse
import contextlib
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import uuid
import zipfile

from check_publication import check_content, private_path

ROOT = Path(__file__).resolve().parents[1]
AREAS = {'app', 'dashboard', 'host', 'conta', 'portal', 'portal-v2', 'portal-v3', 'assets'}
ROOT_TYPES = {'.php', '.html', '.css', '.js', '.svg', '.ico', '.png', '.jpg', '.webp', '.txt'}


def digest(content):
    return hashlib.sha256(content).hexdigest()


def safe_path(name):
    path = PurePosixPath(name)
    if not name or '\\' in name or '\x00' in name or path.is_absolute() or '..' in path.parts or str(path) != name:
        raise ValueError('Caminho inválido no pacote.')
    if private_path(name):
        raise ValueError('Arquivo privado/runtime recusado: ' + name)
    return path


def runtime_path(name):
    path = safe_path(name)
    if path.parts[0] in AREAS or name == '.htaccess':
        return True
    return len(path.parts) == 1 and path.suffix in ROOT_TYPES


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)


def commit(reference):
    return git('rev-parse', '--verify', '--end-of-options', reference + '^{commit}').decode().strip()


def blob(reference, name):
    entry = git('ls-tree', '-z', reference, '--', name)
    if not entry:
        return None, None
    metadata, actual = entry.rstrip(b'\0').split(b'\t', 1)
    mode, kind, object_id = metadata.split()
    if actual.decode() != name or kind != b'blob' or mode not in {b'100644', b'100755'}:
        raise ValueError('Symlink/submódulo/tipo não suportado: ' + name)
    return git('cat-file', 'blob', object_id.decode()), 0o755 if mode == b'100755' else 0o644


def build(base, head, output, allow_new_migrations=False):
    base, head = commit(base), commit(head)
    subprocess.run(['git', 'merge-base', '--is-ancestor', base, head], cwd=ROOT, check=True)
    names = git('diff', '--name-only', '--no-renames', '-z', base, head).decode().rstrip('\0').split('\0')
    changes, payload, skipped = [], {}, []
    # ls-tree recursivo conserva o caminho completo.
    migration_names = git('ls-tree', '-r', '--name-only', base, '--', 'migrations').decode().splitlines()
    latest = max([int(PurePosixPath(name).name[:3]) for name in migration_names
                  if re.match(r'^migrations/\d{3}_[a-z0-9_]+\.sql$', name)] or [0])
    for name in filter(None, names):
        path = safe_path(name)
        before, old_mode = blob(base, name)
        after, new_mode = blob(head, name)
        if path.parts[0] == 'migrations' and path.suffix in {'.sql', '.php'}:
            version = re.match(r'^migrations/(?:([0-9]{3})_[a-z0-9_]+\.sql|smoke_([0-9]{3})\.php)$', name)
            if not allow_new_migrations or before is not None or not version or int(version.group(1) or version.group(2)) <= latest:
                raise ValueError('Migração existente ou não autorizada; revisão separada necessária: ' + name)
        elif not runtime_path(name):
            skipped.append(name)
            continue
        if after is not None and check_content(name, after):
            raise ValueError('Conteúdo privado recusado: ' + name)
        changes.append({'path': name, 'before': digest(before) if before is not None else None,
                        'after': digest(after) if after is not None else None, 'mode': new_mode or old_mode})
        if after is not None:
            payload['files/' + name] = after
    if not changes:
        raise ValueError('Nenhum arquivo de aplicação para publicar; documentação/dev ficam fora do pacote.')
    manifest = {'format': 1, 'base': base, 'head': head, 'changes': changes, 'skipped': skipped}
    output = Path(output).absolute()
    checksum = output.with_name(output.name + '.sha256')
    if output.exists() or output.is_symlink() or checksum.exists() or checksum.is_symlink():
        raise ValueError('O pacote de saída já existe.')
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open('xb') as stream:
        os.chmod(output, 0o600)
        with zipfile.ZipFile(stream, 'w', zipfile.ZIP_DEFLATED) as archive:
            archive.writestr('manifest.json', json.dumps(manifest, indent=2, ensure_ascii=False))
            for name, content in payload.items():
                archive.writestr(name, content)
    with checksum.open('x') as stream:
        os.chmod(checksum, 0o600)
        stream.write(digest(output.read_bytes()) + '  ' + output.name + '\n')
    print(f'Pacote: {output}; arquivos={len(changes)}; base={base[:12]}; head={head[:12]}')


def load(package):
    with zipfile.ZipFile(package) as archive:
        infos = archive.infolist()
        names = [info.filename for info in infos]
        if len(names) != len(set(names)) or sum(info.file_size for info in infos) > 100 * 1024 * 1024:
            raise ValueError('Pacote duplicado ou maior que 100 MiB.')
        manifest = json.loads(archive.read('manifest.json'))
        if manifest.get('format') != 1 or not manifest.get('changes'):
            raise ValueError('Manifesto inválido.')
        paths, contents, expected = set(), {}, {'manifest.json'}
        for change in manifest['changes']:
            name = change['path']
            path = safe_path(name)
            if name in paths or (not runtime_path(name) and not re.match(r'^migrations/(?:\d{3}_[a-z0-9_]+\.sql|smoke_\d{3}\.php)$', name)):
                raise ValueError('Arquivo de aplicação inválido: ' + name)
            if path.parts[0] == 'migrations' and change['before'] is not None:
                raise ValueError('Migração existente não pode ser substituída.')
            paths.add(name)
            for key in ['before', 'after']:
                if change[key] is not None and not re.fullmatch(r'[a-f0-9]{64}', change[key]):
                    raise ValueError('Checksum inválido.')
            if change['before'] is None and change['after'] is None or change['mode'] not in {0o644, 0o755}:
                raise ValueError('Alteração inválida.')
            if change['after'] is not None:
                member = 'files/' + name
                expected.add(member)
                contents[name] = archive.read(member)
                if digest(contents[name]) != change['after'] or check_content(name, contents[name]):
                    raise ValueError('Checksum/conteúdo recusado: ' + name)
        if set(names) != expected:
            raise ValueError('Pacote contém arquivos fora do manifesto.')
    return manifest, contents


def target_root(target):
    target = Path(target).resolve(strict=True)
    if not target.is_dir() or not (target / 'app/db.php').is_file() or (target / 'app/db.php').is_symlink():
        raise ValueError('Destino não parece ser uma instalação FireSpot.')
    return target


def target_file(target, name):
    path = target.joinpath(*safe_path(name).parts)
    cursor = path
    while cursor != target:
        if cursor.is_symlink():
            raise ValueError('Symlink recusado no destino: ' + name)
        cursor = cursor.parent
    if path.exists() and not path.is_file():
        raise ValueError('Destino não é arquivo regular: ' + name)
    return path


def fingerprint(path):
    return digest(path.read_bytes()) if path.exists() else None


def check(manifest, target, key='before'):
    mismatches = [item['path'] for item in manifest['changes']
                  if fingerprint(target_file(target, item['path'])) != item[key]]
    if mismatches:
        raise ValueError('Arquivos divergentes; nenhuma aplicação autorizada: ' + ', '.join(mismatches))


def write_file(path, content, mode, owner=None):
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix='.firespot-release-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        if owner is not None:
            os.chown(temporary, *owner)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


@contextlib.contextmanager
def backup_lock(directory, target):
    directory = Path(directory).absolute()
    if directory.is_symlink():
        raise ValueError('Backup não pode ser symlink.')
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory = directory.resolve()
    if directory == target or target in directory.parents or stat.S_IMODE(directory.stat().st_mode) & 0o077:
        raise ValueError('Backup deve ficar fora do projeto e ter permissões 700.')
    lock = directory / '.deploy.lock'
    if lock.is_symlink():
        raise ValueError('Lock inválido.')
    with lock.open('a') as stream:
        os.chmod(lock, 0o600)
        fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield directory


def apply(manifest, contents, target, backup_directory):
    with backup_lock(backup_directory, target) as base:
        check(manifest, target)
        label = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:8]
        backup = base / label
        backup.mkdir(mode=0o700)
        metadata = {}
        for change in manifest['changes']:
            path = target_file(target, change['path'])
            if path.exists():
                info = path.stat()
                metadata[change['path']] = {'mode': stat.S_IMODE(info.st_mode), 'uid': info.st_uid, 'gid': info.st_gid}
                destination = backup / 'files' / change['path']
                destination.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
                destination.write_bytes(path.read_bytes())
                destination.chmod(0o600)
        saved = dict(manifest, target=str(target), metadata=metadata)
        (backup / 'manifest.json').write_text(json.dumps(saved, indent=2))
        (backup / 'manifest.json').chmod(0o600)
        print('Backup privado: ' + str(backup), flush=True)
        applied = []
        try:
            for change in manifest['changes']:
                path = target_file(target, change['path'])
                if fingerprint(path) != change['before']:
                    raise ValueError('Arquivo mudou durante a publicação: ' + change['path'])
                if change['after'] is None:
                    path.unlink()
                else:
                    previous = metadata.get(change['path'])
                    write_file(path, contents[change['path']], previous['mode'] if previous else change['mode'],
                               (previous['uid'], previous['gid']) if previous else None)
                applied.append(change)
            check(manifest, target, 'after')
        except Exception:
            restore(saved, backup, target, applied)
            raise
    print('Código aplicado. Banco, configuração privada e equipamentos não foram alterados pelo comando.')
    import shlex
    print('Rollback: python3 ' + shlex.quote(str(Path(__file__).resolve())) + ' rollback --backup '
          + shlex.quote(str(backup)) + ' --target ' + shlex.quote(str(target)))


def restore(manifest, backup, target, changes):
    for change in reversed(changes):
        path = target_file(target, change['path'])
        if fingerprint(path) != change['after']:
            raise ValueError('Rollback interrompido: arquivo mudou depois da release: ' + change['path'])
        if change['before'] is None:
            path.unlink()
        else:
            content = (backup / 'files' / change['path']).read_bytes()
            if digest(content) != change['before']:
                raise ValueError('Backup divergente: ' + change['path'])
            previous = manifest['metadata'][change['path']]
            write_file(path, content, previous['mode'], (previous['uid'], previous['gid']))


def rollback(backup, target):
    backup = Path(backup).resolve(strict=True)
    with backup_lock(backup.parent, target):
        manifest = json.loads((backup / 'manifest.json').read_text())
        if manifest.get('format') != 1 or manifest.get('target') != str(target):
            raise ValueError('Backup pertence a outro destino/formato.')
        check(manifest, target, 'after')
        # Confira todos os backups antes da primeira escrita.
        for change in manifest['changes']:
            if change['before'] is not None and fingerprint(backup / 'files' / change['path']) != change['before']:
                raise ValueError('Backup incompleto ou divergente.')
        restore(manifest, backup, target, manifest['changes'])
    print('Código anterior restaurado. Rollback não reverte banco ou operações remotas.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    actions = parser.add_subparsers(dest='action', required=True)
    create = actions.add_parser('build')
    create.add_argument('--base', required=True)
    create.add_argument('--head', default='HEAD')
    create.add_argument('--output', required=True)
    create.add_argument('--allow-new-migrations', action='store_true')
    for action in ['check', 'apply']:
        command = actions.add_parser(action)
        command.add_argument('--package', required=True)
        command.add_argument('--target', required=True)
        if action == 'apply':
            command.add_argument('--backup-dir', required=True)
    undo = actions.add_parser('rollback')
    undo.add_argument('--backup', required=True)
    undo.add_argument('--target', required=True)
    args = parser.parse_args()
    try:
        if args.action == 'build':
            build(args.base, args.head, args.output, args.allow_new_migrations)
        elif args.action == 'rollback':
            rollback(args.backup, target_root(args.target))
        else:
            manifest, contents = load(args.package)
            target = target_root(args.target)
            check(manifest, target)
            if args.action == 'apply':
                apply(manifest, contents, target, args.backup_dir)
            else:
                print(f'Conferência aprovada: {len(manifest["changes"])} arquivos; nenhuma alteração executada.')
    except (ValueError, OSError, KeyError, TypeError, zipfile.BadZipFile, subprocess.CalledProcessError) as error:
        parser.exit(2, str(error) + '\n')


if __name__ == '__main__':
    main()

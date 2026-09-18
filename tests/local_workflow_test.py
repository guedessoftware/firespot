#!/usr/bin/env python3
"""Test deployment failure paths against temporary Git repositories, never live files."""

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import socket
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

PROJECT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PROJECT / 'scripts'))
import release

spec = importlib.util.spec_from_file_location('local_setup', PROJECT / 'dev/setup.py')
local_setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(local_setup)


class SetupTest(unittest.TestCase):
    def test_private_independent_keys_and_idempotency(self):
        with tempfile.TemporaryDirectory() as temporary, socket.socket() as listener:
            directory = Path(temporary) / '.local'
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
            with self.assertRaisesRegex(ValueError, 'já está em uso'):
                local_setup.setup(directory, port)
            self.assertFalse(directory.exists())
            listener.close()
            with contextlib.redirect_stdout(io.StringIO()):
                local_setup.setup(directory, port)
            before = {file.name: file.read_bytes() for file in directory.iterdir()}
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700)
            self.assertTrue(all(stat.S_IMODE(file.stat().st_mode) == 0o600 for file in directory.iterdir()))
            values = dict(line.split('=', 1) for line in (directory / 'firespot.env').read_text().splitlines())
            self.assertEqual(values['APP_URL'], f'http://localhost:{port}')
            keys = [values[key] for key in ['APP_KEY', 'PERSONAL_DATA_KEY', 'PAYMENT_CREDENTIAL_KEY', 'AD_PROOF_KEY']]
            keys.append((directory / 'master.key').read_text().strip())
            self.assertEqual(len(keys), len(set(keys)))
            self.assertNotIn('MERCADOPAGO_ACCESS_TOKEN', values)
            with socket.socket() as running:
                running.bind(('127.0.0.1', port))
                with contextlib.redirect_stdout(io.StringIO()):
                    local_setup.setup(directory)
            self.assertEqual(before, {file.name: file.read_bytes() for file in directory.iterdir()})

    def test_partial_setup_does_not_replace_secrets(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / '.local'
            directory.mkdir()
            (directory / 'master.key').write_text('existing-key')
            with self.assertRaisesRegex(ValueError, 'parcial'):
                local_setup.setup(directory)
            self.assertEqual((directory / 'master.key').read_text(), 'existing-key')


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.root_patch = patch.object(release, 'ROOT', self.repo)
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Test')
        self.git('config', 'user.email', 'test@example.invalid')
        for name, text in {'app/db.php': '<?php // test database connector\n',
                           'dashboard/page.php': '<?php echo "before";\n',
                           'assets/remove.css': 'body { color: black; }\n',
                           'migrations/057_existing.sql': 'SELECT 1;\n',
                           'README.md': 'Before\n'}.items():
            self.write(self.repo, name, text)
        self.base = self.save('base')
        self.target = self.root / 'target'
        self.target.mkdir()
        for file in self.repo.rglob('*'):
            if file.is_file() and '.git' not in file.parts:
                self.write(self.target, str(file.relative_to(self.repo)), file.read_text())
        self.write(self.target, '.env', 'PRIVATE_CONFIGURATION=preserve\n')
        self.write(self.target, 'portal-v3/uploads/content/fixture.txt', 'private-media')
        (self.target / 'dashboard/page.php').chmod(0o640)
        self.backups = self.root / 'backups'

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.repo, stderr=subprocess.DEVNULL).decode().strip()

    def write(self, root, name, text):
        file = root / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(text)

    def save(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)
        return self.git('rev-parse', 'HEAD')

    def package(self):
        self.write(self.repo, 'dashboard/page.php', '<?php echo "after";\n')
        self.write(self.repo, 'assets/new.css', 'body { color: blue; }\n')
        (self.repo / 'assets/remove.css').unlink()
        self.write(self.repo, 'README.md', 'After\n')
        self.write(self.repo, 'dev/Dockerfile', 'FROM ubuntu:22.04\n')
        self.save('change')
        output = self.root / 'release.zip'
        with contextlib.redirect_stdout(io.StringIO()):
            release.build(self.base, 'HEAD', output)
        return output, *release.load(output)

    def test_apply_rollback_preserve_configuration_media_and_modes(self):
        package, manifest, contents = self.package()
        self.assertEqual(len(manifest['changes']), 3)
        self.assertIn('README.md', manifest['skipped'])
        self.assertEqual(stat.S_IMODE(package.stat().st_mode), 0o600)
        release.check(manifest, self.target)
        with contextlib.redirect_stdout(io.StringIO()):
            release.apply(manifest, contents, self.target, self.backups)
        self.assertEqual((self.target / 'dashboard/page.php').read_bytes(), contents['dashboard/page.php'])
        self.assertFalse((self.target / 'assets/remove.css').exists())
        self.assertEqual(stat.S_IMODE((self.target / 'dashboard/page.php').stat().st_mode), 0o640)
        self.assertEqual((self.target / '.env').read_text(), 'PRIVATE_CONFIGURATION=preserve\n')
        self.assertEqual((self.target / 'portal-v3/uploads/content/fixture.txt').read_text(), 'private-media')
        backup = next(file for file in self.backups.iterdir() if file.is_dir())
        with contextlib.redirect_stdout(io.StringIO()):
            release.rollback(backup, self.target)
        self.assertIn('before', (self.target / 'dashboard/page.php').read_text())
        self.assertTrue((self.target / 'assets/remove.css').exists())
        self.assertFalse((self.target / 'assets/new.css').exists())

    def test_conflict_refuses_all_writes(self):
        _, manifest, contents = self.package()
        self.write(self.target, 'dashboard/page.php', 'production-customization')
        with self.assertRaisesRegex(ValueError, 'divergentes'):
            release.apply(manifest, contents, self.target, self.backups)
        self.assertFalse((self.target / 'assets/new.css').exists())
        self.assertTrue((self.target / 'assets/remove.css').exists())
        self.assertEqual((self.target / 'dashboard/page.php').read_text(), 'production-customization')

    def test_failure_restores_already_written_files(self):
        _, manifest, contents = self.package()
        writer = release.write_file
        calls = 0

        def fail_once(*args, **kwargs):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise OSError('simulated write failure')
            return writer(*args, **kwargs)

        with patch.object(release, 'write_file', fail_once), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(OSError, 'simulated'):
                release.apply(manifest, contents, self.target, self.backups)
        release.check(manifest, self.target)

    def test_rollback_refuses_later_changes(self):
        _, manifest, contents = self.package()
        with contextlib.redirect_stdout(io.StringIO()):
            release.apply(manifest, contents, self.target, self.backups)
        backup = next(file for file in self.backups.iterdir() if file.is_dir())
        self.write(self.target, 'dashboard/page.php', 'later-change')
        with self.assertRaisesRegex(ValueError, 'divergentes'):
            release.rollback(backup, self.target)
        self.assertTrue((self.target / 'assets/new.css').exists())
        self.assertEqual((self.target / 'dashboard/page.php').read_text(), 'later-change')

    def test_payload_tampering_and_symlink_refused(self):
        package, manifest, _ = self.package()
        with zipfile.ZipFile(package) as original, zipfile.ZipFile(self.root / 'tampered.zip', 'w') as changed:
            for name in original.namelist():
                changed.writestr(name, b'altered' if name == 'files/dashboard/page.php' else original.read(name))
        with self.assertRaisesRegex(ValueError, 'Checksum'):
            release.load(self.root / 'tampered.zip')
        (self.target / 'dashboard/page.php').unlink()
        (self.target / 'dashboard/page.php').symlink_to(self.target / '.env')
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            release.check(manifest, self.target)

    def test_private_paths_and_traversal_refused(self):
        for name in ['../outside.php', '/outside.php', 'app/../outside.php', 'dev/.local/firespot.env',
                     '.env', 'portal-v3/uploads/content/photo.png', 'assets/ads/client.png']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                release.safe_path(name)

    def test_historical_migration_edit_refused(self):
        self.write(self.repo, 'migrations/057_existing.sql', 'SELECT 2;\n')
        self.save('edit migration')
        with self.assertRaisesRegex(ValueError, 'Migração'):
            release.build(self.base, 'HEAD', self.root / 'bad.zip', True)

    def test_new_migration_requires_explicit_option(self):
        self.write(self.repo, 'migrations/058_new.sql', 'SELECT 1;\n')
        self.save('new migration')
        with self.assertRaisesRegex(ValueError, 'Migração'):
            release.build(self.base, 'HEAD', self.root / 'blocked.zip')
        with contextlib.redirect_stdout(io.StringIO()):
            release.build(self.base, 'HEAD', self.root / 'allowed.zip', True)
        manifest, _ = release.load(self.root / 'allowed.zip')
        self.assertIsNone(manifest['changes'][0]['before'])


if __name__ == '__main__':
    unittest.main(verbosity=2)

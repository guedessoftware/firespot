#!/usr/bin/env python3
"""Generate local-only secrets without copying any production configuration."""

import argparse
import base64
import os
from pathlib import Path
import secrets
import socket


def radius_settings(directory):
    names = ['radius-db-password', 'radius-probe-secret']
    present = [(directory / name).exists() for name in names]
    if any(present) and not all(present):
        raise ValueError('Configuração RADIUS parcial; preserve as chaves e revise os arquivos.')
    if not any(present):
        for name in names:
            descriptor = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, 'w') as output:
                output.write(secrets.token_hex(24) + '\n')
    for name in names:
        if (directory / name).is_symlink() or not (directory / name).is_file():
            raise ValueError('Arquivo de segredo RADIUS inválido.')
    path = directory / 'firespot.env'
    content = path.read_text()
    values = {'COURTESY_RADIUS_PROBE_HOST': 'radius', 'COURTESY_RADIUS_PROBE_PORT': '1812',
              'COURTESY_RADIUS_PROBE_SECRET': (directory / 'radius-probe-secret').read_text().strip()}
    existing = {line.split('=', 1)[0] for line in content.splitlines() if '=' in line}
    addition = ''.join(f'{key}={value}\n' for key, value in values.items() if key not in existing)
    if addition:
        with path.open('a') as output:
            output.write(('' if content.endswith('\n') else '\n') + addition)


def setup(directory, port=None):
    directory = Path(directory)
    if directory.exists():
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError('Diretório local inválido.')
        required = ['db-password', 'db-root-password', 'admin-password', 'master.key', 'firespot.env', 'compose.env']
        if all((directory / name).is_file() and not (directory / name).is_symlink() for name in required):
            current = (directory / 'compose.env').read_text().strip()
            if not current.startswith('FIRESPOT_LOCAL_PORT=') or not current.split('=', 1)[1].isdigit():
                raise ValueError('Configuração de porta inválida.')
            current_port = int(current.split('=', 1)[1])
            if not 1024 <= current_port <= 65535:
                raise ValueError('Configuração de porta inválida.')
            if port is not None and port != current_port:
                raise ValueError('Ambiente já configurado. Consulte a troca de porta em docs/LOCAL_DEVELOPMENT.md.')
            radius_settings(directory)
            print('Ambiente local já existe; credenciais e porta preservadas.')
            return
        raise ValueError('Configuração local parcial; revise antes de gerar novas chaves.')
    port = port if port is not None else 8090
    if not 1024 <= port <= 65535:
        raise ValueError('Use uma porta entre 1024 e 65535.')
    with socket.socket() as probe:
        try:
            probe.bind(('127.0.0.1', port))
        except OSError:
            raise ValueError(f'Porta {port} já está em uso. Escolha outra com --port NUMERO.') from None
    directory.mkdir(mode=0o700, parents=True)
    password = secrets.token_hex(32)
    admin_password = secrets.token_urlsafe(24)
    encoded_key = lambda: base64.b64encode(secrets.token_bytes(32)).decode()
    values = {
        'APP_ENV': 'local', 'APP_DEBUG': 'false', 'APP_TZ': 'America/Manaus',
        'APP_URL': f'http://localhost:{port}',
        'DB_HOST': 'db', 'DB_DATABASE': 'firespot_local',
        'DB_USERNAME': 'firespot_local', 'DB_PASSWORD': password,
        'RADIUS_TIMEZONE': 'America/Manaus',
        'APP_KEY': encoded_key(), 'PAYMENT_CREDENTIAL_KEY': encoded_key(),
        'PERSONAL_DATA_KEY': encoded_key(), 'AD_PROOF_KEY': encoded_key(),
        'INTERNAL_API_KEY': secrets.token_urlsafe(48),
        'ACCOUNT_DELETION_AUDIT_KEY': encoded_key(),
        'LOCAL_ADMIN_PASSWORD': admin_password,
        'PROMO_ENABLED': 'false', 'HOST_PASSWORD_RESET_ENABLED': 'false',
    }
    files = {
        'db-password': password + '\n',
        'db-root-password': secrets.token_hex(32) + '\n',
        'admin-password': admin_password + '\n',
        'master.key': encoded_key() + '\n',
        'compose.env': f'FIRESPOT_LOCAL_PORT={port}\n',
        'firespot.env': ''.join(f'{key}={value}\n' for key, value in values.items()),
    }
    for name, content in files.items():
        descriptor = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            output.write(content)
    radius_settings(directory)
    print('Ambiente local criado. Senha do admin_local: dev/.local/admin-password')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int)
    options = parser.parse_args()
    try:
        setup(Path(__file__).resolve().parent / '.local', options.port)
    except (OSError, ValueError) as error:
        raise SystemExit(str(error))

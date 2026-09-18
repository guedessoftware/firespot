#!/usr/bin/env python3
"""Generate only synthetic lab configuration; never read production files."""
import importlib.util
import os
from pathlib import Path
import secrets
import string

ROOT = Path(__file__).resolve().parents[2]

def setup():
    spec = importlib.util.spec_from_file_location('local_setup', ROOT / 'dev/setup.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    directory = ROOT / 'dev/.local'
    module.setup(directory)
    names = ['chr-radius-secret', 'chr-admin-password', 'client-vnc-password', 'lab-payment-token', 'lab-webhook-secret']
    present = [(directory / name).exists() for name in names]
    if any(present) and not all(present):
        raise ValueError('Configuração parcial do laboratório; preserve os segredos e revise.')
    if not any(present):
        for name in names:
            value = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(8)) if name == 'client-vnc-password' else secrets.token_hex(24)
            with os.fdopen(os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as output:
                output.write(value + '\n')
    for name in names:
        if (directory / name).is_symlink() or not (directory / name).is_file():
            raise ValueError('Segredo local inválido.')
    values = dict(line.split('=', 1) for line in (directory / 'firespot.env').read_text().splitlines() if '=' in line and not line.startswith('#'))
    values.update(APP_URL='http://10.203.30.3', FIRESPOT_LAB='1', COURTESY_RADIUS_PROBE_HOST='web',
        MERCADOPAGO_API_BASE='http://payments:8080', MERCADOPAGO_IDENTITY_API_BASE='http://payments:8080',
        MERCADOPAGO_ACCESS_TOKEN=(directory / 'lab-payment-token').read_text().strip(),
        MERCADOPAGO_PUBLIC_KEY='lab-public-key', MP_ENV='sandbox',
        MERCADOPAGO_WEBHOOK_SECRET=(directory / 'lab-webhook-secret').read_text().strip())
    path = directory / 'lab.env'
    if path.is_symlink(): raise ValueError('Arquivo de ambiente inválido.')
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as output:
        output.write(''.join(f'{key}={value}\n' for key, value in values.items()))
    print('Laboratório preparado; senhas privadas em dev/.local/. Pagamentos são simulados e sem valor.')

if __name__ == '__main__':
    try: setup()
    except (ValueError, OSError) as error: raise SystemExit(str(error))

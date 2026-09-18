#!/usr/bin/env python3
"""Configure only the FreeRADIUS image in the local Docker environment."""

import os
from pathlib import Path
import re
import shutil
import socket


def configure(directory, secrets_directory, source_directory):
    if os.environ.get('FIRESPOT_RADIUS_LOCAL') != '1':
        raise ValueError('Configuração exclusiva da imagem RADIUS local.')
    directory, secrets_directory, source_directory = map(Path, [directory, secrets_directory, source_directory])
    password = (secrets_directory / 'radius_db_password').read_text().strip()
    probe = (secrets_directory / 'radius_probe_secret').read_text().strip()
    if not all(re.fullmatch(r'[a-f0-9]{48}', key) for key in [password, probe]):
        raise ValueError('Segredos RADIUS locais ausentes/inválidos.')
    sql = (directory / 'mods-available/sql').read_text()
    sql = re.sub(r'^\tmysql\s*\{.*?^\t\}', '', sql, count=1, flags=re.M | re.S)
    for name, value in [('dialect', 'mysql'), ('driver', 'rlm_sql_mysql'), ('server', 'db'),
                        ('login', 'firespot_radius'), ('password', password), ('radius_db', 'firespot_local')]:
        sql, count = re.subn(r'^(\s*)#?\s*' + name + r'\s*=.*$', lambda match: match.group(1) + name + ' = "' + value + '"', sql, count=1, flags=re.M)
        if count != 1:
            raise ValueError('Template SQL sem diretiva: ' + name)
    sql = re.sub(r'^(\s*)#?\s*port\s*=.*$', r'\1port = 3306', sql, count=1, flags=re.M)
    sql = re.sub(r'^(\s*)#?\s*read_clients\s*=.*$', r'\1read_clients = yes', sql, count=1, flags=re.M)
    (directory / 'mods-available/sql').write_text(sql)
    (directory / 'mods-available/sql').chmod(0o640)
    shutil.chown(directory / 'mods-available/sql', group='freerad')
    counter = (directory / 'mods-available/sqlcounter').read_text()
    counter = re.sub(r'(check_name = Max-All-Session\n)(?:\s*reply_name = Session-Timeout\n)?', r'\1\treply_name = Session-Timeout\n', counter)
    (directory / 'mods-available/sqlcounter').write_text(counter)
    modules = directory / 'mods-enabled'
    for file in modules.iterdir():
        file.unlink()
    for name in ['sql', 'sqlcounter', 'expiration', 'preprocess', 'chap', 'pap', 'always', 'expr', 'exec']:
        (modules / name).symlink_to('../mods-available/' + name)
    for file in (directory / 'sites-enabled').iterdir():
        file.unlink()
    shutil.copyfile(source_directory / 'site.conf', directory / 'sites-available/firespot-local')
    (directory / 'sites-enabled/firespot-local').symlink_to('../sites-available/firespot-local')
    web = socket.gethostbyname('web')
    clients = ''
    for name, address in [('probe', '127.0.0.1'), ('web_probe', web)]:
        clients += f'client {name} {{\n ipaddr = {address}\n secret = {probe}\n require_message_authenticator = yes\n}}\n'
    (directory / 'clients.conf').write_text(clients)
    (directory / 'clients.conf').chmod(0o640)
    shutil.chown(directory / 'clients.conf', group='freerad')


if __name__ == '__main__':
    configure('/etc/freeradius/3.0', '/run/secrets', Path(__file__).resolve().parent)

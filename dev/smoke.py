#!/usr/bin/env python3
"""Exercise only the loopback development instance; never a production URL."""

from html.parser import HTMLParser
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, build_opener


class LoginForm(HTMLParser):
    token = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name') == 'csrf':
            self.token = attrs.get('value')


def main():
    directory = Path(__file__).resolve().parent / '.local'
    setting, port = (directory / 'compose.env').read_text().strip().split('=', 1)
    if setting != 'FIRESPOT_LOCAL_PORT' or not port.isdigit() or not 1024 <= int(port) <= 65535:
        raise SystemExit('Porta local inválida.')
    base = 'http://127.0.0.1:' + port
    opener = build_opener(HTTPCookieProcessor(CookieJar()))
    for path in ['/dev/.local/firespot.env', '/dev/.local/master.key', '/.git/config', '/app/env.php', '/migrations/001_initial_schema.sql', '/scripts/release.py']:
        try:
            opener.open(base + path, timeout=15).close()
        except HTTPError as error:
            if error.code in {403, 404}:
                continue
        raise SystemExit('Arquivo privado/interno exposto: ' + path)
    with opener.open(base + '/dashboard/login.php', timeout=15) as response:
        form = LoginForm()
        form.feed(response.read().decode())
    if not form.token:
        raise SystemExit('Formulário de login sem CSRF.')
    data = urlencode({'csrf': form.token, 'usuario': 'admin_local',
                      'senha': (directory / 'admin-password').read_text().strip()}).encode()
    with opener.open(base + '/dashboard/login.php', data=data, timeout=30) as response:
        response.read()
        if '/dashboard/login.php' in response.url or response.status != 200:
            raise SystemExit('Login do administrador local falhou.')
    print('Ambiente local: login com CSRF aprovado; arquivos internos protegidos.')


if __name__ == '__main__':
    main()

# FireSpot Ubuntu Installer

Este diretório contém um instalador assistido para provisionar o FireSpot Hotspot em uma instância **Ubuntu Server 22.04 LTS** (ou superior). Ele prepara Nginx + PHP-FPM, MariaDB + FreeRADIUS, importa o schema oficial (`schema.sql`) e publica o código da aplicação em `/var/www/hotspot`.

> **Importante:** o instalador não altera o projeto-fonte existente no repositório. Ele simplesmente copia a árvore para o destino final. Execute-o apenas em máquinas limpas ou em servidores de staging preparados para receber o FireSpot.

## Conteúdo

| Arquivo | Descrição |
|---------|-----------|
| `install.sh` | Script principal. Requer privilégios de superusuário. |

## Pré-requisitos

1. **Sistema**
   - Ubuntu Server 22.04 LTS (recomendado) com acesso root.
   - DNS apontando o domínio do hotspot para o servidor (ex.: `hotspot.exemplo.com`).
   - Acesso SSH liberado.

2. **Credenciais necessárias** (serão solicitadas durante a instalação)
   - Nome/secret do NAS (compartilhado com o MikroTik).
   - Domínio público da aplicação.
   - Credenciais do banco de dados (o script pode criá-las).
   - (Opcional) Token Mercado Pago, credenciais RouterOS, etc. — são adicionados depois via `.env`.

3. **Pendências para download posterior**
   - Certificado TLS (ex.: Let's Encrypt via `certbot`).
   - Pacotes do MikroTik (RouterOS v7+ com API habilitada).
   - Extensões PlaySMS/WhatsApp gateway, caso utilize campanhas promocionais.
   - Ferramentas opcionais: `pm2` (para workers Node), `nginx certbot plugin`.

## Dependências instaladas

O script provisiona automaticamente:

- `nginx`, `php8.1-fpm`, `php8.1-cli`, `php8.1-mysql`, `php8.1-xml`, `php8.1-curl`, `php8.1-gd`, `php8.1-zip`, `php8.1-mbstring`, `php8.1-bcmath`, `php-ssh2`
- `mariadb-server`, `mariadb-client`
- `freeradius`, `freeradius-mysql`, `freeradius-utils`
- `git`, `curl`, `unzip`, `ufw`
- (Opcional, via flag `--with-pm2`) `nodejs` LTS + `pm2`

## Como usar

```bash
cd installers/ubuntu
sudo ./install.sh
```

### Modo não interativo

Forneça os valores via variáveis de ambiente e use `--non-interactive`:

```bash
sudo FS_DB_PASS="senhaSegura" FS_RADIUS_SECRET="segredo123" \
     FS_APP_DOMAIN="hotspot.exemplo.com" FS_ADMIN_EMAIL="admin@exemplo.com" \
     ./install.sh --non-interactive
```

Variáveis aceitas:

- `FS_DB_HOST` (default `localhost`)
- `FS_DB_PORT` (default `3306`)
- `FS_DB_NAME` (default `firespot`)
- `FS_DB_USER` (default `firespot`)
- `FS_DB_PASS` (obrigatória)
- `FS_APP_DOMAIN` (obrigatória)
- `FS_RADIUS_SECRET` (obrigatória)
- `FS_RADIUS_NAS_NAME` (default `hotspot-nas`)
- `FS_ADMIN_EMAIL` (obrigatória, usada apenas para registro)
- `WITH_PM2=1` ou flag `--with-pm2`

## Estrutura gerada

- `/var/www/hotspot/` — cópia do projeto
- `/var/www/hotspot/.env` — arquivo de configuração principal
- `/etc/nginx/sites-available/firespot.conf` — virtual host Nginx
- `/etc/php/8.1/fpm/pool.d/firespot.conf` — pool isolado para a aplicação
- `/etc/freeradius/3.0/mods-available/sql` — conexão com o banco das tabelas RADIUS
- `/etc/freeradius/3.0/clients.conf` — cliente padrão (localhost + wildcard)

## Pós-instalação recomendada

1. **TLS** — Utilize `certbot` ou ACME alternativo para emitir certificados e atualizar o virtual host.
2. **Atualizar `.env`** — Inclua tokens Mercado Pago (`MP_ACCESS_TOKEN`), credenciais RouterOS (`ROS_HOST`, `ROS_USER`, `ROS_PASS`) e parâmetros de promoção.
3. **FreeRADIUS** — Adicione NAS reais através do dashboard FireSpot ou diretamente na tabela `nas`.
4. **Firewall** — Ajuste `ufw` conforme política interna.
5. **Backups** — Agende `mysqldump` + snapshots dos diretórios `backups/` e `/var/log/freeradius/`.
6. **Monitoramento** — Integre `systemd` journald, `nginx` e `freeradius` com sua stack (Prometheus, ELK, etc.).

## Reversão

Não deletamos as versões originais de arquivos Nginx/PHP/FreeRADIUS. Para reverter:

- Remova o symlink `sites-enabled/firespot.conf` e reinicie Nginx.
- Apague `/var/www/hotspot` caso queira reinstalar.
- Restaure `clients.conf`/`sql` originais (backup manual recomendado antes da instalação).

## Suporte

- Script validado em Ubuntu 22.04 LTS recém-instalado.
- Para customizações (multitenant, PM2 workers, etc.) adapte o script ou crie um arquivo `post-install.sh` em sua automação.

Boa instalação! ✨

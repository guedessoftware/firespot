#!/usr/bin/env bash
# FireSpot Hotspot platform installer for Ubuntu Server (tested on 22.04 LTS)
# This script prepares an application server with Nginx, PHP-FPM, MariaDB and FreeRADIUS,
# imports the FireSpot schema and wires the RADIUS configuration to match the application.
#
# USAGE
#   sudo ./install.sh [--non-interactive] [--with-pm2]
#
# The script is intentionally verbose and will ask for confirmation before applying
# any destructive changes. When --non-interactive is passed, required values must be
# provided through environment variables (see the README alongside this script).

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOG_FILE="/var/log/firespot-installer.log"
RADIUS_TEMPLATE_DIR="${SCRIPT_DIR}/radius-config"

msg(){ printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE"; }
err(){ printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE" >&2; }

require_root(){
  if [[ $(id -u) -ne 0 ]]; then
    err "Execute este instalador como root ou usando sudo."
    exit 1
  fi
}

confirm(){
  local question="$1"
  local default_answer="${2:-n}"
  if [[ ${NON_INTERACTIVE:-0} -eq 1 ]]; then
    [[ "$default_answer" =~ ^[Yy]$ ]]
    return
  fi
  read -r -p "${question} " answer || true
  answer="${answer:-$default_answer}"
  [[ "$answer" =~ ^[Yy]$ ]]
}

prompt_value(){
  local var_name="$1"
  local prompt="$2"
  local default_value="${3:-}"
  local hidden="${4:-0}"
  local value="${!var_name:-}"
  if [[ -n "$value" ]]; then
    export "$var_name"="$value"
    return
  fi
  if [[ ${NON_INTERACTIVE:-0} -eq 1 ]]; then
    if [[ -z "$default_value" ]]; then
      err "Variável obrigatória não informada: $var_name"
      exit 1
    fi
    export "$var_name"="$default_value"
    return
  fi
  while true; do
    if [[ "$hidden" -eq 1 ]]; then
      read -r -s -p "$prompt" value || true
      printf '\n'
    else
      read -r -p "$prompt" value || true
    fi
    value="${value:-$default_value}"
    if [[ -n "$value" ]]; then
      export "$var_name"="$value"
      break
    fi
    printf "Valor obrigatório. Tente novamente.\n"
  done
}

trap 'err "Instalação interrompida."' INT TERM
require_root

touch "$LOG_FILE"
chmod 600 "$LOG_FILE"

if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  source /etc/os-release
  OS_PRETTY_NAME="${PRETTY_NAME:-${NAME:-Ubuntu}}"
  OS_VERSION_CODENAME="${VERSION_CODENAME:-unknown}"
else
  OS_PRETTY_NAME="Desconhecido"
  OS_VERSION_CODENAME="n/a"
fi

msg "Sistema detectado: ${OS_PRETTY_NAME} (codename: ${OS_VERSION_CODENAME})"

render_radius_template(){
  local template="$1"
  local destination="$2"
  if [[ ! -f "$template" ]]; then
    err "Template RADIUS não encontrado: $template"
    exit 1
  fi
  DB_HOST="$FS_DB_HOST" \
  DB_PORT="$FS_DB_PORT" \
  DB_NAME="$FS_DB_NAME" \
  DB_USER="$FS_DB_USER" \
  DB_PASS="$FS_DB_PASS" \
  RADIUS_SECRET="$FS_RADIUS_SECRET" \
  RADIUS_NAS_NAME="$FS_RADIUS_NAS_NAME" \
  envsubst < "$template" > "$destination"
}

pkg_version(){
  local pkg="$1"
  dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || echo 'não instalado'
}

NON_INTERACTIVE=0
WITH_PM2=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --non-interactive) NON_INTERACTIVE=1 ; shift ;;
    --with-pm2) WITH_PM2=1 ; shift ;;
    -h|--help)
      cat <<'EOF'
FireSpot Installer
  --non-interactive   Executa em modo não interativo. Use variáveis de ambiente*
  --with-pm2          Instala Node.js + PM2 (para workers opcionais)
  -h, --help          Exibe esta ajuda

*Variáveis esperadas no modo não interativo:
  FS_DB_HOST, FS_DB_PORT, FS_DB_NAME, FS_DB_USER, FS_DB_PASS,
  FS_APP_DOMAIN, FS_RADIUS_SECRET, FS_RADIUS_NAS_NAME, FS_ADMIN_EMAIL
EOF
      exit 0
      ;;
    *) err "Argumento desconhecido: $1"; exit 1 ;;
  esac
done

# Valores padrão sugeridos
prompt_value FS_DB_HOST "Host do banco de dados [localhost]: " "localhost"
prompt_value FS_DB_PORT "Porta do banco de dados [3306]: " "3306"
prompt_value FS_DB_NAME "Nome do banco (será criado se não existir) [firespot]: " "firespot"
prompt_value FS_DB_USER "Usuário do banco (será criado se não existir) [firespot]: " "firespot"
prompt_value FS_DB_PASS "Senha do usuário do banco: " "" 1
prompt_value FS_APP_DOMAIN "Domínio (ex: hotspot.suaempresa.com): "
prompt_value FS_RADIUS_SECRET "Shared Secret padrão para acesso RADIUS/NAS: " "" 1
prompt_value FS_RADIUS_NAS_NAME "Nome padrão para o NAS principal [hotspot-nas]: " "hotspot-nas"
prompt_value FS_ADMIN_EMAIL "Email do admin para certificados/alerts: "

APP_WEB_ROOT="/var/www/hotspot"
NGINX_SITE="/etc/nginx/sites-available/firespot.conf"
NGINX_LINK="/etc/nginx/sites-enabled/firespot.conf"
PHP_POOL="/etc/php/8.1/fpm/pool.d/firespot.conf"
ENV_FILE="$APP_WEB_ROOT/.env"

msg "==> Atualizando repositórios APT"
apt-get update -y >> "$LOG_FILE" 2>&1

msg "==> Instalando pacotes base"
apt-get install -y nginx php8.1-fpm php8.1-cli php8.1-mbstring php8.1-xml php8.1-curl \
  php8.1-zip php8.1-gd php8.1-mysql php8.1-bcmath php-ssh2 mariadb-server mariadb-client \
  freeradius freeradius-mysql freeradius-utils gettext-base unzip git curl ufw >> "$LOG_FILE" 2>&1

if [[ $WITH_PM2 -eq 1 ]]; then
  msg "==> Instalando Node.js LTS e PM2"
  curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >> "$LOG_FILE" 2>&1
  apt-get install -y nodejs >> "$LOG_FILE" 2>&1
  npm install -g pm2 >> "$LOG_FILE" 2>&1
fi

msg "==> Habilitando serviços"
systemctl enable nginx php8.1-fpm mariadb freeradius >> "$LOG_FILE" 2>&1

msg "==> Configurando firewall UFW (HTTP/HTTPS, SSH, RADIUS)"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 80/tcp >/dev/null 2>&1 || true
ufw allow 443/tcp >/dev/null 2>&1 || true
ufw allow 1812/udp >/dev/null 2>&1 || true
ufw allow 1813/udp >/dev/null 2>&1 || true

msg "==> Preparando diretório da aplicação em $APP_WEB_ROOT"
mkdir -p "$APP_WEB_ROOT"
rsync -a --delete --exclude 'installers/' --exclude '.git/' --exclude 'backups/' --exclude 'vendor/' \
  "$PROJECT_ROOT/" "$APP_WEB_ROOT/" >> "$LOG_FILE" 2>&1
chown -R www-data:www-data "$APP_WEB_ROOT"
find "$APP_WEB_ROOT" -type f -exec chmod 640 {} +
find "$APP_WEB_ROOT" -type d -exec chmod 750 {} +

msg "==> Configurando PHP-FPM pool"
cat > "$PHP_POOL" <<EOF
[firespot]
user = www-data
group = www-data
listen = /run/php/php8.1-fpm-firespot.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 25
pm.start_servers = 6
pm.min_spare_servers = 4
pm.max_spare_servers = 12
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
php_admin_value[memory_limit] = 256M
php_admin_value[date.timezone] = America/Manaus
EOF

msg "==> Configurando Nginx"
cat > "$NGINX_SITE" <<EOF
server {
    listen 80;
    server_name ${FS_APP_DOMAIN};

    root ${APP_WEB_ROOT};
    index index.php index.html;

    client_max_body_size 32m;

    access_log /var/log/nginx/firespot_access.log;
    error_log  /var/log/nginx/firespot_error.log;

    location / {
        try_files \$uri \$uri/ /portal/index.php?$query_string;
    }

    location ^~ /dashboard/ {
        try_files \$uri \$uri/ /dashboard/index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm-firespot.sock;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 30d;
        access_log off;
    }

    location ~ /\.(?!well-known/) {
        deny all;
    }
}
EOF
ln -sf "$NGINX_SITE" "$NGINX_LINK"

msg "==> Reiniciando Nginx e PHP-FPM"
systemctl restart php8.1-fpm
systemctl restart nginx

msg "==> Configurando MariaDB"
systemctl restart mariadb
mysql -e "CREATE DATABASE IF NOT EXISTS \`$FS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$LOG_FILE" 2>&1
mysql -e "CREATE USER IF NOT EXISTS '$FS_DB_USER'@'$FS_DB_HOST' IDENTIFIED BY '$FS_DB_PASS';" >> "$LOG_FILE" 2>&1 || true
mysql -e "GRANT ALL ON \`$FS_DB_NAME\`.* TO '$FS_DB_USER'@'$FS_DB_HOST'; FLUSH PRIVILEGES;" >> "$LOG_FILE" 2>&1
mysql "$FS_DB_NAME" < "$APP_WEB_ROOT/schema.sql" >> "$LOG_FILE" 2>&1

msg "==> Ajustando arquivo .env"
cat > "$ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${FS_APP_DOMAIN}
DB_HOST=${FS_DB_HOST}
DB_PORT=${FS_DB_PORT}
DB_DATABASE=${FS_DB_NAME}
DB_USERNAME=${FS_DB_USER}
DB_PASSWORD=${FS_DB_PASS}
APP_TZ=America/Manaus
PAYMENT_CREDENTIAL_KEY=$(openssl rand -base64 32)
ROS_HOST=127.0.0.1
ROS_USER=api
ROS_PASS=changeme
MP_ACCESS_TOKEN=
PROMO_ENABLED=false
EOF
chown www-data:www-data "$ENV_FILE"
chmod 600 "$ENV_FILE"

msg "==> Configurando limpeza de acessos anônimos expirados"
touch /var/log/firespot-guest-access.log
chown www-data:www-data /var/log/firespot-guest-access.log
chmod 0640 /var/log/firespot-guest-access.log
cat > /etc/cron.d/firespot-guest-access <<EOF
* * * * * www-data /usr/bin/php ${APP_WEB_ROOT}/app/cli/cleanup_guest_access.php >> /var/log/firespot-guest-access.log 2>&1
EOF
chmod 644 /etc/cron.d/firespot-guest-access

msg "==> Configurando FreeRADIUS (modulo sql, clients.conf e default)"
SQL_MOD="/etc/freeradius/3.0/mods-available/sql"
cat > "$SQL_MOD" <<EOF
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    tls {
    }
    pool {
        start = 5
        min = 3
        max = 32
        spare = 3
        uses = 0
        lifetime = 0
        cleanup_interval = 30
        idle_timeout = 60
        retry_delay = 5
    }
    read_clients = yes
    server = "${FS_DB_HOST}"
    port = ${FS_DB_PORT}
    login = "${FS_DB_USER}"
    password = "${FS_DB_PASS}"
    radius_db = "${FS_DB_NAME}"
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    client_table = "nas"
    nas_table = "nas"
}
EOF
ln -sf "$SQL_MOD" /etc/freeradius/3.0/mods-enabled/sql

CLIENTS_CONF="/etc/freeradius/3.0/clients.conf"
cat > "$CLIENTS_CONF" <<EOF
client localhost {
    ipaddr = 127.0.0.1
    secret = ${FS_RADIUS_SECRET}
    require_message_authenticator = no
    nastype = other
}
client hotspot-nas {
    ipaddr = 0.0.0.0/0
    secret = ${FS_RADIUS_SECRET}
    shortname = ${FS_RADIUS_NAS_NAME}
    require_message_authenticator = no
    nastype = other
}
EOF

DEFAULT_SITE="/etc/freeradius/3.0/sites-available/default"
if ! grep -q 'sql' "$DEFAULT_SITE"; then
  err "Não foi possível localizar configuração do FreeRADIUS padrão. Verifique manualmente."
else
  sed -i 's/^\s*#*\s*sql$/        sql/' "$DEFAULT_SITE"
  sed -i 's/^\s*#*\s*sql$/        sql/' /etc/freeradius/3.0/sites-available/inner-tunnel || true
fi

msg "==> Reiniciando FreeRADIUS"
systemctl restart freeradius

msg "==> Limpeza e resumo"
cat <<EOF

Instalação concluída. Resumo:
  App root:      $APP_WEB_ROOT
  Nginx site:    $NGINX_SITE
  PHP pool:      $PHP_POOL
  banco:         $FS_DB_NAME (usuario $FS_DB_USER)
  .env:          $ENV_FILE
  Radius secret: ${FS_RADIUS_SECRET}

Próximos passos recomendados:
  1. Ajuste ROS_HOST/ROS_PASS no .env para o roteador MikroTik.
  2. Configure certificados TLS (Let's Encrypt) para ${FS_APP_DOMAIN}.
  3. Atualize MP_ACCESS_TOKEN com seu token Mercado Pago.
  4. Revise /etc/freeradius/3.0/mods-available/sql conforme necessário.
  5. Adicione registros NAS reais na tabela nas através do dashboard.

Logs detalhados: $LOG_FILE
EOF

#!/bin/sh
set -eu
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
configuration="$project_directory/dev/.local/compose.env"
if [ ! -f "$configuration" ]; then
    printf 'Execute primeiro: python3 dev/setup.py --port 8090\n' >&2
    exit 64
fi
IFS='=' read -r setting local_port < "$configuration"
case "$local_port" in ''|*[!0-9]*) printf 'Porta inválida.\n' >&2; exit 64 ;; esac
if [ "$setting" != FIRESPOT_LOCAL_PORT ] || [ "$local_port" -lt 1024 ] || [ "$local_port" -gt 65535 ]; then
    printf 'Configuração de porta inválida.\n' >&2
    exit 64
fi
export FIRESPOT_LOCAL_PORT="$local_port"
exec docker compose --env-file "$configuration" -f "$project_directory/compose.local.yaml" "$@"

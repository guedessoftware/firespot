#!/bin/sh
set -eu
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_directory"
configuration="$project_directory/dev/.local/compose.env"
if [ "${1:-}" = start ]; then
    python3 dev/lab/setup.py
    python3 dev/lab/preflight.py
    shift
    set -- up -d --build --wait --wait-timeout 720 "$@"
fi
if [ ! -f "$configuration" ] || [ ! -f dev/.local/lab.env ]; then
    printf 'Execute primeiro: bash dev/lab.sh start\n' >&2; exit 64
fi
IFS='=' read -r setting local_port < "$configuration"
case "$local_port" in ''|*[!0-9]*) exit 64 ;; esac
if [ "$setting" != FIRESPOT_LOCAL_PORT ] || [ "$local_port" -lt 1024 ] || [ "$local_port" -gt 65535 ]; then exit 64; fi
export FIRESPOT_LOCAL_PORT="$local_port"
if [ "${1:-}" = approve ]; then
    shift
    case "${1:-}" in ''|*[!0-9]*) printf 'Informe o ID numérico do pagamento de teste.\n' >&2; exit 64 ;; esac
    set -- exec -T payments python3 /opt/lab/payments.py approve "$1"
elif [ "${1:-}" = preset ]; then
    shift
    set -- exec -T web php dev/lab/preset.php "${1:-hybrid}"
elif [ "${1:-}" = test ]; then
    exec python3 dev/lab/validate.py
fi
exec docker compose --env-file "$configuration" -p firespot-lab -f compose.local.yaml -f compose.lab.yaml "$@"

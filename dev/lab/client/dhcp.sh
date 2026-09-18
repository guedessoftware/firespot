#!/bin/sh
set -eu
case "$1" in
  bound|renew)
    ip -4 addr flush dev hotspot
    ip -4 addr add "$ip/24" dev hotspot
    ip route replace default via "${router%% *}" dev hotspot
    printf 'nameserver %s\n' "${dns%% *}" > /etc/resolv.conf
    printf '%s\n' "$ip" > /tmp/hotspot-ip
    touch /tmp/hotspot-ready
    ;;
esac

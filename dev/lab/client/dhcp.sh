#!/bin/sh
set -eu
case "$1" in
  bound|renew)
    ip -4 addr flush dev hotspot
    ip -4 addr add "$ip/24" dev hotspot
    ip route replace default via "${router%% *}" dev hotspot
    # The GUI interface shares the synthetic WAN subnet. Its connected route
    # must not take precedence over the Hotspot path to the test destination.
    ip route replace 10.203.40.10/32 via "${router%% *}" dev hotspot
    printf 'nameserver %s\n' "${dns%% *}" > /etc/resolv.conf
    printf '%s\n' "$ip" > /tmp/hotspot-ip
    touch /tmp/hotspot-ready
    ;;
esac

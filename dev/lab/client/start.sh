#!/bin/sh
set -eu
case "$LAB_VLAN" in 10|20) ;; *) exit 64 ;; esac
trunk_interface=$(ip -j addr | python3 -c 'import json,sys; print(next(i["ifname"] for i in json.load(sys.stdin) if any(a.get("local", "").startswith("10.203.254.") for a in i["addr_info"])))')
ip addr flush dev "$trunk_interface"
ip route del default 2>/dev/null || true
ip link add link "$trunk_interface" name hotspot type vlan id "$LAB_VLAN"
ip link set hotspot up
# Management responses may return to noVNC. Every new client connection must use the VLAN.
iptables -P OUTPUT DROP
iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A OUTPUT -o hotspot -j ACCEPT
udhcpc -i hotspot -s /opt/lab/dhcp.sh -t 20 -T 3 -n -q
install -o client -g client -m 600 /run/secrets/client_vnc_password /home/client/vnc-password
su-exec client Xvfb :99 -screen 0 1280x800x24 -nolisten tcp &
sleep 1
su-exec client fluxbox &
su-exec client x11vnc -display :99 -localhost -rfbport 5900 -passwdfile /home/client/vnc-password -forever -shared -quiet &
su-exec client firefox --no-remote "http://10.203.40.10/" > /home/client/firefox.log 2>&1 &
printf '%s\n' "$!" > /tmp/gui-firefox.pid
exec su-exec client websockify --web=/usr/share/novnc 0.0.0.0:6080 127.0.0.1:5900

#!/bin/sh
set -eu
printf 'Message-Authenticator = 0x00000000000000000000000000000000\n' \
    | radclient -q -r 1 -t 2 -S /run/secrets/radius_probe_secret 127.0.0.1:1812 status

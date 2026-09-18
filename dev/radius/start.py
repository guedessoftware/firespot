#!/usr/bin/env python3
import os
from pathlib import Path
import subprocess
from configure import configure

configure('/etc/freeradius/3.0','/run/secrets',Path(__file__).resolve().parent)
check = subprocess.run(['freeradius','-XC'],capture_output=True,text=True)
if check.returncode:
    output=check.stdout+check.stderr
    for name in ['radius_db_password','radius_probe_secret']:
        output=output.replace(Path('/run/secrets/'+name).read_text().strip(),'[redacted]')
    for line in output.splitlines():
        if any(word in line.lower() for word in ['error','failed','invalid','not found','unknown']): print('RADIUS configuration: '+line,flush=True)
    raise SystemExit('RADIUS configuration rejected.')
os.execvp('freeradius',['freeradius','-f','-l','stdout'])

"""Expose useful CI errors, including Docker failures outside container logs."""
from collections import deque
import os
from pathlib import Path
import subprocess
import sys

mode=sys.argv[1]
command=['bash','dev/lab.sh']+(['build'] if mode=='build' else ['up','-d','--wait','--wait-timeout','720'])
environment=os.environ.copy(); environment['BUILDKIT_PROGRESS']='plain'
process=subprocess.Popen(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,env=environment)
recent=deque(maxlen=50)
for line in process.stdout:
    print(line,end='',flush=True); recent.append(line)
result=process.wait()
if result:
    output=''.join(recent)
    for path in Path('dev/.local').iterdir():
        if path.is_file():
            for line in path.read_text().splitlines():
                value=line.split('=',1)[-1]
                if len(value)>=8:output=output.replace(value,'[redacted]')
    print('::error::Lab '+mode+': '+output.replace('%','%25').replace('\r','%0D').replace('\n','%0A')[-9000:],flush=True)
raise SystemExit(result)

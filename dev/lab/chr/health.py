from pathlib import Path
import socket
if not Path('/data/ready').exists(): raise SystemExit(1)
try:
    with socket.create_connection(('10.203.30.2',22),2): pass
except OSError: raise SystemExit(1)

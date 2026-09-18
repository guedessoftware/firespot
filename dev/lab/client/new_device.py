"""Give only the isolated VLAN-20 test client a second device identity."""
import os
import subprocess
if os.getuid()!=0 or os.environ.get('LAB_VLAN')!='20':raise SystemExit(64)
def run(*args):subprocess.run(args,check=True)
run('ip','link','set','hotspot','down')
run('ip','addr','flush','dev','hotspot')
run('ip','link','set','hotspot','address','02:00:00:00:20:02')
run('ip','link','set','hotspot','up')
run('udhcpc','-i','hotspot','-s','/opt/lab/dhcp.sh','-t','20','-T','3','-n','-q')

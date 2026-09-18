"""Run official CHR in QEMU and attach two TAPs to private Docker bridges."""
import functools
from http.server import BaseHTTPRequestHandler,HTTPServer
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import threading
import time
from pexpect.fdpexpect import fdspawn
import pexpect
from router import command

DATA=Path('/data'); DATA.mkdir(exist_ok=True); DATA.chmod(0o700)
PASSWORD=Path('/run/secrets/chr_admin_password').read_text().strip()
SECRET=Path('/run/secrets/chr_radius_secret').read_text().strip()
if not all(re.fullmatch('[a-f0-9]{48}',value) for value in [PASSWORD,SECRET]): raise SystemExit('Segredos do CHR inválidos.')
READY=DATA/'ready'; READY.unlink(missing_ok=True)

def run(*args): subprocess.run(args,check=True,stdout=subprocess.DEVNULL)

def interface(address):
    items=json.loads(subprocess.check_output(['ip','-j','address']))
    return next(item['ifname'] for item in items if any(a.get('local')==address for a in item['addr_info']))

def bridge(address,name,tap,host_address=None):
    nic=interface(address)
    run('ip','addr','flush','dev',nic)
    run('ip','link','add',name,'type','bridge')
    run('ip','link','set',nic,'master',name)
    run('ip','tuntap','add','dev',tap,'mode','tap')
    run('ip','link','set',tap,'master',name)
    for device in [nic,tap,name]: run('ip','link','set',device,'up')
    if host_address: run('ip','addr','add',host_address,'dev',name)

def login_page(vlan):
    code='LAB-A' if vlan==10 else 'LAB-B'
    fields={key:'$('+key+')' for key in ['mac','ip','chap-id','chap-challenge','link-login-only','link-orig-esc','error']}
    fields.update(hotspot='$(server-name)',fast_id=code)
    inputs=''.join(f'<input type="hidden" name="{key}" value="{value}">' for key,value in fields.items())
    return ('<!doctype html><html><meta charset="utf-8"><title>FireSpot LAB</title>'
        '<form name="redirect" action="http://10.203.30.3/portal/hotspot_capture.php" method="post">'+inputs+
        '<button>Entrar no FireSpot de teste</button></form><script>document.redirect.submit()</script></html>').encode()

def other_page(name):
    if name=='alogin': return b'<html><meta http-equiv="refresh" content="0;url=$(link-redirect)"><a href="$(link-redirect)">Continuar</a></html>'
    if name=='status': return b'<html><meta charset="utf-8"><h1>FireSpot LAB</h1><p>Usuario: $(username)</p><p>Tempo: $(uptime) / $(session-time-left)</p><a href="http://10.203.40.10/">Internet de teste</a><p><a href="$(link-logout)">Desconectar</a></p></html>'
    return b'<html><meta charset="utf-8"><p>$(error)</p><a href="$(link-login)">Voltar ao portal</a></html>'

def config():
    lines=[
        '/system identity set name=FireSpot-LAB',
        '/system clock set time-zone-name=America/Manaus',
        f'/user set [find name="admin"] password="{PASSWORD}"',
        '/ip address add address=10.203.30.2/24 interface=ether2 comment=FireSpot-LAB',
        '/ip dns set allow-remote-requests=yes servers=10.0.2.3',
        '/ip firewall nat add chain=srcnat out-interface=ether1 action=masquerade comment=FireSpot-LAB',
        '/ip firewall address-list add list=lab-clients address=10.203.10.0/24',
        '/ip firewall address-list add list=lab-clients address=10.203.20.0/24',
        '/ip firewall nat add chain=srcnat out-interface=ether2 src-address-list=lab-clients action=masquerade comment=FireSpot-LAB-portal-return',
        f'/radius add address=10.203.30.3 src-address=10.203.30.2 secret="{SECRET}" service=hotspot authentication-port=1812 accounting-port=1813 timeout=3s comment="FireSpot Base"',
        '/radius incoming set accept=yes port=3799',
        '/ip service set [find name=ssh] address=10.203.30.0/24',
        '/ip service disable [find name!=ssh]',
        '/ip hotspot user profile set [find name=default] add-mac-cookie=yes mac-cookie-timeout=20m shared-users=1',
        ':if ([:len [/file find name="hotspot"]]=0) do={/file add name=hotspot type=directory}',
    ]
    for vlan in [10,20]:
        gateway=f'10.203.{vlan}.1'; code='LAB-A' if vlan==10 else 'LAB-B'
        lines += [
            f'/interface vlan add name=lab-{vlan} interface=ether3 vlan-id={vlan}',
            f'/ip address add address={gateway}/24 interface=lab-{vlan}',
            f'/ip pool add name=lab-{vlan} ranges=10.203.{vlan}.100-10.203.{vlan}.199',
            f'/ip dhcp-server add name=lab-{vlan} interface=lab-{vlan} address-pool=lab-{vlan} lease-time=10m disabled=no',
            f'/ip dhcp-server network add address=10.203.{vlan}.0/24 gateway={gateway} dns-server={gateway}',
            f'/file add name=hotspot/lab-{vlan} type=directory',
            f'/tool fetch url="http://10.0.2.2:8765/login-{vlan}.html" dst-path=hotspot/lab-{vlan}/login.html',
            f'/ip hotspot profile add name=lab-{vlan} hotspot-address={gateway} dns-name="" html-directory=hotspot/lab-{vlan} login-by=http-chap,cookie,mac-cookie http-cookie-lifetime=20m use-radius=yes radius-accounting=yes radius-interim-update=15s',
            f'/ip hotspot add name={code} interface=lab-{vlan} address-pool=lab-{vlan} profile=lab-{vlan} disabled=no',
        ]
        for page in ['alogin','status','logout','error','rlogin']:
            lines.append(f'/tool fetch url="http://10.0.2.2:8765/{page}.html" dst-path=hotspot/lab-{vlan}/{page}.html')
    # Hotspot is enforced before normal forwarding; only the public portal is in the garden.
    lines += [
        '/ip hotspot walled-garden ip add action=accept dst-address=10.203.30.3 protocol=tcp dst-port=80 comment=FireSpot-LAB',
        '/ip firewall filter add chain=forward connection-state=established,related action=accept',
        '/ip firewall filter add chain=forward src-address=10.203.10.0/24 dst-address=10.203.20.0/24 action=drop',
        '/ip firewall filter add chain=forward src-address=10.203.20.0/24 dst-address=10.203.10.0/24 action=drop',
        '/ip firewall filter add chain=forward dst-address=10.203.30.0/24 dst-address-list=!lab-portal action=drop',
        '/ip firewall address-list add list=lab-portal address=10.203.30.3',
        '/ip firewall filter add chain=input protocol=udp dst-port=3799 src-address=10.203.30.0/24 action=accept',
        '/ip firewall filter add chain=input protocol=udp dst-port=3799 action=drop',
        ':put "FIRESPOT-LAB-CONFIGURED"',
    ]
    return ('\n'.join(lines)+'\n').encode()

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        files={'/bootstrap.rsc':config(),'/login-10.html':login_page(10),'/login-20.html':login_page(20)}
        files.update({'/'+name+'.html':other_page(name) for name in ['alogin','status','logout','error','rlogin']})
        body=files.get(self.path)
        if body is None: self.send_error(404); return
        self.send_response(200); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)

def console_login():
    serial=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
    serial.connect(str(DATA/'serial.sock'))
    child=fdspawn(os.dup(serial.fileno()),encoding='utf-8',codec_errors='replace',timeout=360)
    child.linesep='\r'
    console_log=(DATA/'console.log').open('w')
    os.chmod(DATA/'console.log',0o600)
    child.logfile_read=console_log
    child.sendline('')
    patterns=[r'(?i)login\s*:',r'(?i)password\s*[:>]',r'(?i)new password\s*[:>]',r'(?i)(?:repeat|retype)(?: new)? password\s*[:>]',r'(?i)software license.*\[Y/n\]:',r'\] >',pexpect.EOF,pexpect.TIMEOUT]
    fresh=NEW_DISK
    login_count=0
    deadline=time.monotonic()+360
    while time.monotonic()<deadline:
        # New-password prompts precede the generic password prompt.
        index=child.expect([patterns[0],patterns[2],patterns[3],patterns[1],patterns[4],patterns[5],patterns[6],patterns[7]],timeout=3)
        if index==0: child.sendline('admin+ct'); login_count+=1
        elif index in [1,2]: child.sendline(PASSWORD)
        elif index==3: child.sendline('' if fresh or (login_count==2 and not (DATA/'configured').exists()) else PASSWORD)
        elif index==4: child.sendline('n')
        elif index==5: return child,serial
        elif index==7: child.sendline('')  # Console activation may become available only after boot.
        else: raise RuntimeError('Console do CHR desconectado.')
    raise RuntimeError('Login do console do CHR não concluiu.')

bridge('10.203.30.2','br-service','tap-service','10.203.30.254/24')
bridge('10.203.254.2','br-trunk','tap-trunk')
disk=DATA/'router.qcow2'
NEW_DISK=not disk.exists()
if NEW_DISK:
    run('qemu-img','convert','-f','raw','-O','qcow2','/opt/chr.img',str(disk)); disk.chmod(0o600)
    run('qemu-img','resize',str(disk),'256M')
args=['qemu-system-x86_64','-accel','tcg','-cpu','max','-m','512','-smp','2','-display','none','-monitor','none',
    '-serial','unix:'+str(DATA/'serial.sock')+',server=on,wait=off',
    '-drive','file='+str(disk)+',format=qcow2,if=virtio',
    '-netdev','user,id=wan','-device','virtio-net-pci,netdev=wan,mac=02:00:00:00:00:01',
    '-netdev','tap,id=service,ifname=tap-service,script=no,downscript=no','-device','virtio-net-pci,netdev=service,mac=02:00:00:00:00:02',
    '-netdev','tap,id=trunk,ifname=tap-trunk,script=no,downscript=no','-device','virtio-net-pci,netdev=trunk,mac=02:00:00:00:00:03']
log=(DATA/'qemu.log').open('a'); os.chmod(DATA/'qemu.log',0o600)
guest=subprocess.Popen(args,stdout=log,stderr=log)
def stop(*_):
    READY.unlink(missing_ok=True); guest.terminate()
    try: guest.wait(timeout=15)
    except subprocess.TimeoutExpired: guest.kill(); guest.wait()
    raise SystemExit(0)
signal.signal(signal.SIGTERM,stop); signal.signal(signal.SIGINT,stop)
try:
    for _ in range(60):
        if (DATA/'serial.sock').exists(): break
        if guest.poll() is not None: raise RuntimeError('QEMU encerrou antes da inicialização.')
        time.sleep(.5)
    child,serial=console_login()
    if not (DATA/'configured').exists():
        server=HTTPServer(('127.0.0.1',8765),Handler)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        child.sendline(':if ([:len [/ip dhcp-client find interface=ether1]]=0) do={/ip dhcp-client add interface=ether1 disabled=no}')
        child.expect(r'\] >'); time.sleep(4)
        child.sendline('/tool fetch url="http://10.0.2.2:8765/bootstrap.rsc" dst-path=bootstrap.rsc')
        child.expect(r'\] >',timeout=60)
        child.sendline('/import file-name=bootstrap.rsc')
        child.expect(r'\] >',timeout=120)
        if 'FIRESPOT-LAB-CONFIGURED' not in child.before:
            error=child.before.replace(PASSWORD,'[redacted]').replace(SECRET,'[redacted]')
            lines=[line for line in error.splitlines() if any(word in line.lower() for word in ['failure','expected','syntax','error','not allowed'])]
            raise RuntimeError('Importação rejeitada: '+' '.join(lines)[-1500:])
        child.sendline('/file remove [find name=bootstrap.rsc]'); child.expect(r'\] >')
        server.shutdown(); (DATA/'configured').write_text('1\n')
    child.close(); serial.close()
    for _ in range(30):
        try:
            result=command(':put [/ip hotspot print count-only]; :put [/system identity get name]')
            if 'FireSpot-LAB' in result and re.search(r'(?m)^2\s*$',result): break
        except (OSError,RuntimeError): pass
        time.sleep(2)
    else: raise RuntimeError('CHR não confirmou os dois servidores Hotspot.')
    READY.write_text('1\n'); print('CHR pronto: VLAN 10, VLAN 20, Hotspot, RADIUS e CoA.',flush=True)
    guest.wait(); READY.unlink(missing_ok=True); raise SystemExit(guest.returncode)
except Exception as error:
    READY.unlink(missing_ok=True); guest.terminate(); guest.wait(timeout=15)
    # Do not print serial output, which contains passwords and shared secrets.
    print('CHR initialization failed: '+type(error).__name__+': '+str(error),flush=True)
    console=DATA/'console.log'
    if console.exists():
        tail=console.read_text(errors='replace')[-1800:].replace(PASSWORD,'[redacted]').replace(SECRET,'[redacted]')
        tail=re.sub(r'\x1b\[[0-9;?]*[A-Za-z]','',tail)
        print('CHR console diagnostic: '+tail.replace('\r',' ').replace('\n',' | '),flush=True)
    raise SystemExit(1)

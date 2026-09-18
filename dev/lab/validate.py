"""Validate the isolated lab from the Linux host. Uses no production settings."""
import json
from pathlib import Path
import subprocess
import time

ROOT=Path(__file__).resolve().parents[2]

def execute(*args,capture=False,allow_failure=False):
    result=subprocess.run(['bash','dev/lab.sh',*args],cwd=ROOT,capture_output=capture,text=True)
    if result.returncode and not allow_failure:raise RuntimeError('Falhou: '+' '.join(args[:6]))
    return result.stdout if capture else ''

def state():return json.loads(execute('exec','-T','web','php','dev/lab/state.php',capture=True))
def router(text):return execute('exec','-T','chr','python3','/opt/lab/router.py',text,capture=True).strip()
def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label,flush=True)

try:
    execute('exec','-T','web','php','dev/radius/smoke.php')
    execute('exec','-T','web','php','tests/guest_payment_radius_test.php')
    for client in ['client-a','client-b']: execute('exec','-T',client,'python3','/opt/lab/smoke.py','portal')
    execute('exec','-T','client-a','python3','/opt/lab/smoke.py','paid')
    deadline=time.monotonic()+35
    while time.monotonic()<deadline:
        orders=state()['orders']
        if orders and orders[0]['acctsessionid'] and orders[0]['acctstoptime'] is None:break
        time.sleep(2)
    else:raise RuntimeError('Accounting da sessão provisória ausente.')
    before=orders[0]; check(before['status']=='pending' and before['radius_phase']=='provisional','Pedido pendente com sessão RADIUS provisória')
    # Firefox was closed by smoke.py: promotion must happen entirely through webhook and CoA.
    execute('approve',str(before['provider_payment_id']))
    after=state()['orders'][0]
    check(after['status']=='paid' and after['radius_coa_status']=='applied' and after['radius_phase']=='paid_active','Webhook e CoA-ACK promovem acesso com navegador fechado')
    check(after['acctsessionid']==before['acctsessionid'] and after['acctstoptime'] is None,'Pagamento mantém a mesma sessão autenticada')
    check(after['radius_paid_baseline_seconds'] is not None,'Tempo anterior ao pagamento registrado como baseline')
    execute('approve',str(before['provider_payment_id']))
    duplicate=state()['orders'][0]
    check(duplicate['radius_paid_baseline_seconds']==after['radius_paid_baseline_seconds'],'Webhook repetido preserva baseline pago')
    # An administrative reset erases the MAC cookie. Simulate a client leaving
    # the network instead, with the lab's short keepalive and unchanged DHCP.
    check(int(router(':put [:len [/ip hotspot cookie find mac-address="02:00:00:00:10:01"]]'))>0,'Cookie do cliente pago criado')
    execute('exec','-T','client-a','ip','link','set','hotspot','down')
    try:
        deadline=time.monotonic()+75
        while time.monotonic()<deadline:
            if router(':put [:len [/ip hotspot active find server="LAB-A"]]')=='0' and state()['orders'][0]['acctstoptime'] is not None:break
            time.sleep(2)
        else:raise RuntimeError('Keepalive não encerrou a sessão do cliente desconectado.')
        check(int(router(':put [:len [/ip hotspot cookie find mac-address="02:00:00:00:10:01"]]'))>0,'Ausência curta preserva cookie e encerra accounting')
    finally:
        execute('exec','-T','client-a','ip','link','set','hotspot','up')
        # The valid lease/address survives link down. Restore the routes without
        # a DHCP address flush racing the RouterOS MAC-cookie login.
        execute('exec','-T','client-a','ip','route','replace','default','via','10.203.10.1','dev','hotspot')
        execute('exec','-T','client-a','ip','route','replace','10.203.40.10/32','via','10.203.10.1','dev','hotspot')
    response=''
    for _ in range(15):
        response=execute('exec','-T','client-a','curl','--silent','--max-time','3','http://10.203.40.10/',capture=True,allow_failure=True)
        if 'FIRESPOT-LAB-INTERNET-OK' in response:break
        time.sleep(1)
    check('FIRESPOT-LAB-INTERNET-OK' in response,'MAC cookie reconecta o acesso pago')
    check(router(':put [/ip hotspot active get [find server="LAB-A"] login-by]')=='mac-cookie','Reconexão autenticada por MAC cookie, sem navegador')
    execute('exec','-T','client-b','python3','/opt/lab/smoke.py','courtesy')
    check(state()['active_accounting']>=2,'Duas instalações têm accounting ativo')
    execute('preset','free_sponsored')
    execute('exec','-T','chr','python3','/opt/lab/router.py','/ip hotspot active remove [find server="LAB-B"]')
    execute('exec','-T','client-b','python3','/opt/lab/new_device.py')
    execute('exec','-T','client-b','python3','/opt/lab/smoke.py','sponsored')
    execute('preset','hybrid')
except (RuntimeError,ValueError) as error:
    print('::error::Lab validation: '+str(error),flush=True); raise SystemExit(2)

"""Validate the isolated lab from the Linux host. Uses no production settings."""
import json
from pathlib import Path
import subprocess
import time

ROOT=Path(__file__).resolve().parents[2]

def execute(*args,capture=False):
    result=subprocess.run(['bash','dev/lab.sh',*args],cwd=ROOT,capture_output=capture,text=True)
    if result.returncode:raise RuntimeError('Falhou: '+' '.join(args[:5]))
    return result.stdout if capture else ''

def state():return json.loads(execute('exec','-T','web','php','dev/lab/state.php',capture=True))
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
    execute('exec','-T','chr','python3','/opt/lab/router.py','/ip hotspot active remove [find server="LAB-A"]')
    time.sleep(2)
    response=''
    for _ in range(15):
        response=execute('exec','-T','client-a','curl','--silent','--max-time','10','http://10.203.40.10/',capture=True)
        if 'FIRESPOT-LAB-INTERNET-OK' in response:break
        time.sleep(1)
    check('FIRESPOT-LAB-INTERNET-OK' in response,'MAC cookie reconecta o acesso pago')
    execute('exec','-T','client-b','python3','/opt/lab/smoke.py','courtesy')
    check(state()['active_accounting']>=2,'Duas instalações têm accounting ativo')
    execute('preset','free_sponsored')
    execute('exec','-T','chr','python3','/opt/lab/router.py','/ip hotspot active remove [find server="LAB-B"]')
    execute('exec','-T','client-b','python3','/opt/lab/new_device.py')
    execute('exec','-T','client-b','python3','/opt/lab/smoke.py','sponsored')
    execute('preset','hybrid')
except (RuntimeError,ValueError) as error:
    print('::error::Lab validation: '+str(error),flush=True); raise SystemExit(2)

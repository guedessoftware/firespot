"""Drive real Firefox through WebDriver using only Python's standard library."""
import json
import os
from pathlib import Path
import re
import subprocess
import signal
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

if os.getuid()==0:
    os.setgroups([]); os.setgid(1000); os.setuid(1000)
os.environ['HOME']='/home/client'
VLAN=int(os.environ['LAB_VLAN']); CODE='LAB-A' if VLAN==10 else 'LAB-B'
WAN='http://10.203.40.10/'
MODE=sys.argv[1] if len(sys.argv)>1 else 'portal'

def assert_ok(condition,label):
    if not condition: raise RuntimeError(label)
    print('PASS '+label,flush=True)

def wait(predicate,label,seconds=45):
    until=time.monotonic()+seconds
    while time.monotonic()<until:
        try:
            value=predicate()
            if value:return value
        except (urllib.error.URLError,RuntimeError,KeyError):pass
        time.sleep(.5)
    raise RuntimeError('Timeout: '+label)

driver=None; session=None
driver_log=Path('/tmp/geckodriver.log')
def request(method,path,body=None):
    data=json.dumps(body).encode() if body is not None else None
    req=urllib.request.Request('http://127.0.0.1:4444'+path,data=data,method=method,headers={'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=45) as response: result=json.load(response)
    except urllib.error.HTTPError as error:
        try: detail=json.load(error).get('value',{})
        except (ValueError,TypeError): detail={}
        message=str(detail.get('error',''))+': '+str(detail.get('message',''))
        raise RuntimeError('WebDriver HTTP '+str(error.code)+' '+message[:400]) from None
    if isinstance(result.get('value'),dict) and 'error' in result['value']:raise RuntimeError('WebDriver: '+result['value']['error'])
    return result.get('value')

def wd(method,path,body=None):return request(method,'/session/'+session+path,body)
def script(value):return wd('POST','/execute/sync',{'script':value,'args':[]})
def click(selector):
    element=wait(lambda:script('const e=document.querySelector('+json.dumps(selector)+'); return !!e && !e.hidden && !e.disabled && !!e.getClientRects().length;'),'botão '+selector)
    value=wd('POST','/element',{'using':'css selector','value':selector})
    return wd('POST','/element/'+value['element-6066-11e4-a52e-4f735466cecf']+'/click',{})

try:
    # Free memory for the automated Firefox and ensure approval runs with all browsers closed.
    gui=Path('/tmp/gui-firefox.pid')
    if gui.exists():
        try: os.kill(int(gui.read_text()),signal.SIGTERM)
        except ProcessLookupError: pass
        time.sleep(1)
    assert_ok(Path('/tmp/hotspot-ip').read_text().strip().startswith(f'10.203.{VLAN}.'),'DHCP da VLAN '+str(VLAN))
    route=json.loads(subprocess.check_output(['ip','-j','route','get','10.203.40.10']))
    assert_ok(route[0]['dev']=='hotspot','Destino WAN usa o gateway do Hotspot')
    interfaces=json.loads(subprocess.check_output(['ip','-j','-4','addr']))
    management=next(item['ifname'] for item in interfaces if any(a.get('local','').startswith('10.203.40.') for a in item['addr_info']))
    bypass=subprocess.run(['curl','--silent','--max-time','3','--interface',management,WAN],capture_output=True)
    assert_ok(bypass.returncode!=0,'Interface da tela não contorna o Hotspot')
    log=driver_log.open('w');driver_log.chmod(0o600)
    driver=subprocess.Popen(['geckodriver','--host','127.0.0.1','--port','4444'],stdout=log,stderr=log)
    wait(lambda:request('GET','/status'),'WebDriver')
    value=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'firefox','moz:firefoxOptions':{'binary':'/usr/bin/firefox','args':['-headless']}}}})
    session=value['sessionId']
    wd('POST','/url',{'url':WAN})
    wait(lambda: '/portal-v3/' in wd('GET','/url'),'Redirecionamento para o portal')
    url=wd('GET','/url'); assert_ok('hotspot='+CODE in url,'Instalação correta '+CODE)
    assert_ok('hotspot.internal' not in url,'Login pelo IP do gateway')
    if MODE=='paid':
        wd('POST','/url',{'url':'http://10.203.30.3/portal-v3/index.php?hotspot='+CODE+'&step=plans'})
        click('form.plan-card button[type=submit]'); click('#pay-with-pix')
        wait(lambda: '/sucesso.php?' in wd('GET','/url'),'Pix simulado criado')
        assert_ok('SEM VALOR' in script('return document.body.textContent;'),'Pix de teste sem valor')
        click('#retry-window-button')
        wait(lambda: b'FIRESPOT-LAB-INTERNET-OK' in subprocess.run(['curl','--silent','--max-time','3',WAN],capture_output=True).stdout,'Autenticação provisória',seconds=60)
        assert_ok(True,'Firefox autenticou a sessão provisória por CHAP')
    elif MODE in ['courtesy','sponsored']:
        wd('POST','/url',{'url':'http://10.203.30.3/portal-v3/courtesy.php?hotspot='+CODE})
        if MODE=='sponsored':assert_ok(script('return !!document.querySelector(".sponsored-media") && document.getElementById("courtesy-submit").disabled;'),'Patrocinado exige anúncio antes de liberar')
        click('#courtesy-submit')
        wait(lambda: b'FIRESPOT-LAB-INTERNET-OK' in subprocess.run(['curl','--silent','--max-time','3',WAN],capture_output=True).stdout,'Cortesia conectada',seconds=60)
        assert_ok(True,'Cortesia '+MODE+' via Firefox e RADIUS')
    elif MODE!='portal':raise RuntimeError('Modo de teste desconhecido.')
    if MODE!='portal':
        wait(lambda:b'FIRESPOT-LAB-INTERNET-OK' in subprocess.run(['curl','--silent','--max-time','3',WAN],capture_output=True).stdout,'Tráfego liberado pelo Hotspot',seconds=60)
        assert_ok(True,'Tráfego liberado pelo Hotspot')
except Exception as error:
    detail=type(error).__name__+': '+str(error)
    if session and os.environ.get('LAB_CI_DIAGNOSTIC')=='true':
        try:
            detail+=' | page='+urllib.parse.urlsplit(wd('GET','/url')).path+' | '+str(script('return document.body.innerText.slice(0,600);'))
        except Exception:pass
    if driver_log.exists():detail+=' | '+driver_log.read_text(errors='replace')[-1600:]
    detail=re.sub(r'[a-f0-9]{8,}','[redacted]',detail)
    detail=re.sub(r'https?://[^\s\"\']+','[lab-url]',detail)
    print('::error::Linux '+CODE+' '+MODE+': '+detail.replace('%','%25').replace('\r','%0D').replace('\n','%0A'),flush=True)
    raise SystemExit(2)
finally:
    if session:
        try:wd('DELETE','',None)
        except (RuntimeError,urllib.error.URLError):pass
    if driver:
        driver.terminate()
        try:driver.wait(timeout=10)
        except subprocess.TimeoutExpired:driver.kill();driver.wait()

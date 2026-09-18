"""Drive real Firefox through WebDriver using only Python's standard library."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.error
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
def request(method,path,body=None):
    data=json.dumps(body).encode() if body is not None else None
    req=urllib.request.Request('http://127.0.0.1:4444'+path,data=data,method=method,headers={'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=45) as response: result=json.load(response)
    except urllib.error.HTTPError as error:
        raise RuntimeError('WebDriver recusou a operação HTTP '+str(error.code)) from None
    if isinstance(result.get('value'),dict) and 'error' in result['value']:raise RuntimeError('WebDriver: '+result['value']['error'])
    return result.get('value')

def wd(method,path,body=None):return request(method,'/session/'+session+path,body)
def script(value):return wd('POST','/execute/sync',{'script':value,'args':[]})
def click(selector):
    element=wait(lambda:script('const e=document.querySelector('+json.dumps(selector)+'); return !!e && !e.hidden && !e.disabled && !!e.getClientRects().length;'),'botão '+selector)
    value=wd('POST','/element',{'using':'css selector','value':selector})
    return wd('POST','/element/'+value['element-6066-11e4-a52e-4f735466cecf']+'/click',{})

try:
    assert_ok(Path('/tmp/hotspot-ip').read_text().strip().startswith(f'10.203.{VLAN}.'),'DHCP da VLAN '+str(VLAN))
    interfaces=json.loads(subprocess.check_output(['ip','-j','-4','addr']))
    management=next(item['ifname'] for item in interfaces if any(a.get('local','').startswith('10.203.40.') for a in item['addr_info']))
    bypass=subprocess.run(['curl','--silent','--max-time','3','--interface',management,WAN],capture_output=True)
    assert_ok(bypass.returncode!=0,'Interface da tela não contorna o Hotspot')
    driver=subprocess.Popen(['geckodriver','--host','127.0.0.1','--port','4444'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
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
        wait(lambda: 'Internet liberada' in script('return document.body.textContent;') or 'Internet temporária liberada' in script('return document.body.textContent;'),'Autenticação provisória',seconds=60)
        assert_ok(True,'Firefox autenticou a sessão provisória por CHAP')
    elif MODE in ['courtesy','sponsored']:
        wd('POST','/url',{'url':'http://10.203.30.3/portal-v3/courtesy.php?hotspot='+CODE})
        click('#courtesy-submit')
        wait(lambda: b'FIRESPOT-LAB-INTERNET-OK' in subprocess.run(['curl','--silent','--max-time','3',WAN],capture_output=True).stdout,'Cortesia conectada',seconds=60)
        assert_ok(True,'Cortesia '+MODE+' via Firefox e RADIUS')
    elif MODE!='portal':raise RuntimeError('Modo de teste desconhecido.')
    if MODE!='portal':
        reply=subprocess.run(['curl','--silent','--max-time','10',WAN],capture_output=True)
        assert_ok(reply.returncode==0 and b'FIRESPOT-LAB-INTERNET-OK' in reply.stdout,'Tráfego liberado pelo Hotspot')
finally:
    if session:
        try:wd('DELETE','',None)
        except (RuntimeError,urllib.error.URLError):pass
    if driver:
        driver.terminate()
        try:driver.wait(timeout=10)
        except subprocess.TimeoutExpired:driver.kill();driver.wait()

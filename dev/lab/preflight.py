#!/usr/bin/env python3
"""Read-only host checks before building or starting the lab."""
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess

ROOT=Path(__file__).resolve().parents[2]
setup_spec=importlib.util.spec_from_file_location('firespot_local_setup',ROOT/'dev/setup.py')
local_setup=importlib.util.module_from_spec(setup_spec)
setup_spec.loader.exec_module(local_setup)
try:
    info=subprocess.run(['docker','info','--format','{{json .SecurityOptions}}'],capture_output=True,text=True)
    if info.returncode:raise ValueError('Docker indisponível ou sem permissão. Instale/inicie o Engine e confirme docker info.')
    if 'rootless' in info.stdout:raise ValueError('Este laboratório requer Docker Engine com daemon root, para TAP e VLANs.')
    version=subprocess.check_output(['docker','compose','version','--short'],text=True).strip()
    numbers=re.search(r'(\d+)\.(\d+)\.(\d+)',version)
    if not numbers or tuple(map(int,numbers.groups()))<(2,24,4):raise ValueError('Atualize o Compose para 2.24.4 ou superior.')
    if not os.access('/dev/net/tun',os.R_OK|os.W_OK):raise ValueError('/dev/net/tun ausente/inacessível. Verifique o módulo tun.')
    command=['docker','compose','--env-file',str(ROOT/'dev/.local/compose.env'),'-p','firespot-lab','-f',str(ROOT/'compose.local.yaml'),'-f',str(ROOT/'compose.lab.yaml')]
    running=subprocess.check_output(command+['ps','--status','running','--services'],text=True).splitlines()
    port=int((ROOT/'dev/.local/compose.env').read_text().strip().split('=',1)[1])
    for number,service in [(port,'proxy'),(6081,'client-a'),(6082,'client-b')]:
        if service in running:continue
        try:local_setup.check_port(number)
        except OSError:raise ValueError(f'Porta {number} ocupada. Pare o serviço que a usa; para o ambiente básico: bash dev/local.sh down.') from None
    # Ignore bridges belonging to an existing lab; never alter host interfaces/routes.
    lab_devices=set()
    ids=subprocess.check_output(['docker','network','ls','--filter','label=com.docker.compose.project=firespot-lab','--quiet'],text=True).splitlines()
    if ids:
        for network in json.loads(subprocess.check_output(['docker','network','inspect',*ids],text=True)):
            lab_devices.add(network['Options'].get('com.docker.network.bridge.name','br-'+network['Id'][:12]))
    reserved=[ipaddress.ip_network(f'10.203.{number}.0/24') for number in [10,20,30,40,254]]
    for route in json.loads(subprocess.check_output(['ip','-j','-4','route','show','table','all'],text=True)):
        destination=route.get('dst','default')
        if destination=='default' or route.get('dev') in lab_devices:continue
        candidate=ipaddress.ip_network(destination,strict=False)
        if any(candidate.overlaps(network) for network in reserved):raise ValueError('Rede do laboratório coincide com rota local/VPN: '+destination+'. Revise antes de iniciar.')
    print('Docker, TAP, portas e redes disponíveis para o laboratório.')
except (OSError,ValueError,subprocess.CalledProcessError) as error:raise SystemExit(str(error))

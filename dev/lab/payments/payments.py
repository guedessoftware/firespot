"""Offline Pix simulator. No endpoint communicates with a bank or creates a payable Pix."""
import base64
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
from pathlib import Path
import secrets
import sys
import time
import urllib.parse
import urllib.request

DATA = Path('/data/payments.json')
TOKEN = Path('/run/secrets/lab_payment_token').read_text().strip()
WEBHOOK = Path('/run/secrets/lab_webhook_secret').read_text().strip()

def load():
    return json.loads(DATA.read_text()) if DATA.exists() else {}

def save(value):
    temporary = DATA.with_suffix('.tmp')
    temporary.write_text(json.dumps(value))
    temporary.chmod(0o600)
    temporary.replace(DATA)

def notify(payment):
    parts = urllib.parse.urlsplit(payment['notification_url'])
    if parts.scheme != 'http' or parts.hostname != '10.203.30.3' or parts.port not in [None,80] or parts.path != '/portal-v3/api/webhook.php':
        raise ValueError('Destino de webhook fora do laboratório.')
    payment_id = str(payment['id'])
    timestamp, request_id = str(int(time.time())), secrets.token_hex(16)
    signature = hmac.new(WEBHOOK.encode(), f'id:{payment_id};request-id:{request_id};ts:{timestamp};'.encode(), hashlib.sha256).hexdigest()
    url = payment['notification_url'] + '&data.id=' + payment_id
    request = urllib.request.Request(url, data=json.dumps({'type':'payment','data':{'id':payment_id}}).encode(),
        headers={'Content-Type':'application/json','X-Request-Id':request_id,'X-Signature':f'ts={timestamp},v1={signature}'})
    with urllib.request.urlopen(request, timeout=15) as response:
        if response.status != 200 or not json.load(response).get('ok'): raise ValueError('Webhook recusado.')

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def reply(self, status, data):
        body=json.dumps(data).encode()
        self.send_response(status); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        if self.headers.get('Authorization') != 'Bearer ' + TOKEN: return self.reply(401,{'error':'unauthorized'})
        if self.path == '/users/me': return self.reply(200,{'id':123456,'site_id':'MLB','nickname':'FIRESPOT-LAB'})
        key=self.path.removeprefix('/v1/payments/')
        payment=load().get(key)
        self.reply(200,payment) if payment else self.reply(404,{'error':'not_found'})
    def do_POST(self):
        if self.headers.get('Authorization') != 'Bearer ' + TOKEN: return self.reply(401,{'error':'unauthorized'})
        if self.path != '/v1/payments': return self.reply(404,{'error':'not_found'})
        try:
            size=int(self.headers.get('Content-Length','0'))
            if not 0 < size <= 65536: return self.reply(400,{'error':'invalid_body'})
            payload=json.loads(self.rfile.read(size)); state=load()
            if payload.get('payment_method_id') != 'pix': return self.reply(422,{'message':'O laboratório simula somente Pix. Cartão exige sandbox real.'})
            key=self.headers.get('X-Idempotency-Key','')
            for payment in state.values():
                if key and payment.get('_idempotency') == key: return self.reply(200,payment)
            payment_id=str(secrets.randbelow(90000000)+10000000)
            while payment_id in state: payment_id=str(secrets.randbelow(90000000)+10000000)
            payment=dict(payload,id=int(payment_id),status='pending',status_detail='pending_waiting_transfer',_idempotency=key,
                fee_details=[],point_of_interaction={'transaction_data':{'qr_code':'FIRESPOT LAB — SEM VALOR — '+payment_id,'qr_code_base64':'','ticket_url':''}})
            state[payment_id]=payment; save(state); self.reply(201,payment)
        except (ValueError,KeyError,TypeError): self.reply(400,{'error':'invalid_body'})

if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == 'approve':
        key=sys.argv[2]
        if not key.isdigit() or key not in load(): raise SystemExit('Pagamento de teste não encontrado.')
        state=load(); state[key]['status']='approved'; state[key]['status_detail']='accredited'; save(state)
        try: notify(state[key])
        except (ValueError,urllib.error.URLError): raise SystemExit('Pagamento marcado aprovado; webhook falhou. Repita approve para tentar novamente.')
        print('Pagamento simulado aprovado e webhook aceito. Nenhum valor foi cobrado.')
    elif len(sys.argv) == 1:
        HTTPServer(('0.0.0.0',8080),Handler).serve_forever()
    else: raise SystemExit('Uso: payments.py approve ID')

"""Read/control only the isolated CHR, using its private Docker secret."""
from pathlib import Path
import paramiko
import sys

def command(text):
    client=paramiko.SSHClient()
    # This fixed address exists only inside the lab; record its first host key privately.
    known=Path('/data/known_hosts')
    if known.exists(): client.load_host_keys(str(known))
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect('10.203.30.2',username='admin',password=Path('/run/secrets/chr_admin_password').read_text().strip(),
        allow_agent=False,look_for_keys=False,timeout=8,banner_timeout=8)
    if not known.exists(): client.save_host_keys(str(known)); known.chmod(0o600)
    _, stdout, stderr=client.exec_command(text,timeout=15)
    output=stdout.read().decode()+stderr.read().decode()
    code=stdout.channel.recv_exit_status(); client.close()
    if code: raise RuntimeError('Comando rejeitado pelo CHR do laboratório.')
    return output

if __name__ == '__main__':
    if len(sys.argv) != 2: raise SystemExit('Informe um único comando RouterOS.')
    print(command(sys.argv[1]))

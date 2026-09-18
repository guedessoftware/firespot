# Laboratório completo de Hotspot

Este laboratório roda no seu Linux local. Usa banco vazio, segredos aleatórios e um projeto Docker separado (`firespot-lab`). Não copia clientes, configuração, credenciais ou backups da produção. O FreeRADIUS é real; o pagamento Pix é **simulado, sem valor e sem comunicação com bancos**.

## Componentes

| Componente | Função |
|---|---|
| Apache/PHP + MariaDB | Aplicação e banco `firespot_local` |
| FreeRADIUS 3 | SQL, PAP, CHAP, saldo de tempo e accounting UDP 1812/1813 |
| MikroTik CHR 7.24.4 oficial | VM QEMU com WAN, rede de serviços e trunk 802.1Q |
| Cliente A / Alpine + Firefox | DHCP e Hotspot na VLAN 10, gateway `10.203.10.1` |
| Cliente B / Alpine + Firefox | DHCP e Hotspot na VLAN 20, gateway `10.203.20.1` |
| Simulador Pix | Criação, consulta, aprovação e webhook com HMAC |
| Destino WAN | Página para comprovar bloqueio e liberação de tráfego |

Os clientes são containers Linux leves com navegador e MAC/IP próprios. O CHR é uma máquina virtual. A comunicação dos clientes usa interfaces VLAN reais sobre uma bridge Docker privada; não depende de VLAN na sua placa física. A interface de acesso à tela tem firewall para impedir que o Firefox navegue por ela e contorne o Hotspot. IPv6 é desabilitado nos clientes deste laboratório.

## Requisitos

- Linux x86_64, Docker Engine com acesso ao daemon e Compose **2.24.4 ou superior**; Docker rootless não atende às interfaces TAP deste laboratório.
- `/dev/net/tun` disponível. Se estiver ausente: `sudo modprobe tun`.
- Cerca de 4 GB de RAM livres para o conjunto, 10 GB livres de disco e acesso à internet para baixar as imagens. Cada cliente com Firefox tem limite de 1 GB; o CHR usa 512 MB.
- Portas HTTP configurada (padrão **8090**) e telas **6081/6082** livres. A porta 8080 não é utilizada.
- As redes `10.203.10.0/24`, `10.203.20.0/24`, `10.203.30.0/24`, `10.203.40.0/24` e `10.203.254.0/24` não devem coincidir com LAN/VPN em uso. O Docker cria as bridges; não modifique a interface da sua conexão remota.

O QEMU usa emulação por software; não exige KVM. O primeiro boot pode demorar alguns minutos. A imagem oficial é baixada no build e conferida por SHA-256. O disco do CHR fica num volume privado persistente.

## Instalação e início

Se sua pasta ainda não é um repositório, clone primeiro numa pasta nova:

```bash
mkdir -p ~/dev
git clone https://github.com/guedessoftware/firespot.git ~/dev/firespot-local
cd ~/dev/firespot-local
```

Se já clonou, use `cd ~/dev/firespot-local` e `git pull --ff-only`. Para iniciar:

```bash
python3 dev/setup.py --port 8090
bash dev/lab.sh start
bash dev/lab.sh ps
```

Se o ambiente básico já estiver rodando na mesma porta, pare-o antes com `bash dev/local.sh down`. Seus volumes são preservados. O laboratório usa outros volumes, mesmo com o mesmo nome de banco.

| Acesso no navegador da sua máquina | Credencial |
|---|---|
| `http://localhost:8090/dashboard/login.php` | `admin_local`; senha em `dev/.local/admin-password` |
| `http://localhost:6081/vnc.html` | Cliente A; senha em `dev/.local/client-vnc-password` |
| `http://localhost:6082/vnc.html` | Cliente B; mesma senha de tela |

Todas as portas publicadas escutam somente em `127.0.0.1`. Leia suas senhas localmente; não copie os arquivos para issues, commits ou chats. `dev/.local/` é privado e ignorado pelo Git. As telas têm Firefox e terminal (`xterm`, disponível no menu Fluxbox).

**Abra o portal dentro do Firefox do cliente**, digitando `http://10.203.40.10/`. O CHR intercepta o HTTP e encaminha para o Portal V3 da instalação correta. O navegador principal do seu PC serve para abrir as telas e o painel; ele não está atrás do Hotspot.

## RADIUS e CoA

A instalação gera contas SQL separadas para a aplicação e o RADIUS. O FreeRADIUS lê NAS e credenciais no banco e escreve accounting com permissões limitadas. O CHR envia pacotes com origem `10.203.30.2`; o destino RADIUS é `10.203.30.3`. No laboratório, PHP e FreeRADIUS compartilham o endereço de rede, de modo que o CoA enviado pela aplicação tem a origem reconhecida pelo RouterOS. Os processos e contas SQL continuam separados. O secret NAS é gerado em `dev/.local/chr-radius-secret`. Não use segredos da produção.

O CHR recebe CoA em UDP **3799**, somente pela rede de serviços. O tempo provisório e o tempo pago são geridos pelas funções existentes da aplicação. A promoção do acesso passa pelo webhook e pelo receptor CoA real do RouterOS. O intervalo de accounting é 15 segundos.

```bash
# Teste UDP real: PAP, CHAP, senha/MAC inválidos, contador e Start/Interim/Stop.
bash dev/lab.sh exec -T web php dev/radius/smoke.php
# Ver somente o estado dos pedidos fictícios e accounting, sem senhas/tokens.
bash dev/lab.sh exec -T web php dev/lab/state.php
# Ler o RouterOS do laboratório, sem publicar SSH no host.
bash dev/lab.sh exec -T chr python3 /opt/lab/router.py '/ip hotspot active print'
```

O ambiente local básico também inicia FreeRADIUS. Para testes com CHR/clientes, use `dev/lab.sh`, não misture os projetos. A configuração é exclusiva do Docker; não substitui `/etc/freeradius` do servidor em produção.

## Roteiro de testes

1. **Bloqueio e contexto:** no cliente A, abra `http://10.203.40.10/`. Deve aparecer LAB-A. No B, LAB-B. Confirme os endereços com `ip addr show hotspot`. O login final deve usar o gateway correto, sem exigir DNS `*.hotspot.internal`.
2. **Cortesia direta:** escolha a cortesia no portal. A fixture concede 2 minutos pelo RADIUS. Confirme navegação no destino WAN e accounting no painel/banco. Após o fim do tempo, o acesso deve voltar a ser bloqueado.
3. **Pix simulado:** num cliente sem cortesia ativa, escolha o plano de 5 minutos, clique em Pix e libere a janela temporária. O código contém **SEM VALOR** e não é um Pix pagável. Consulte o ID numérico com `dev/lab/state.php`, depois execute `bash dev/lab.sh approve ID`. O simulador notifica o webhook e a aplicação promove a sessão por CoA. Repita a aprovação para testar idempotência. Feche o Firefox antes da aprovação para testar a confirmação sem navegador aberto.
4. **Reconexão:** interrompa/retome o cliente sem excluir os cookies do RouterOS. O retorno curto usa cookie/MAC cookie por até 20 minutos, sujeito ao saldo/validade RADIUS. Não é habilitado login automático apenas por MAC.
5. **Patrocinado:** execute `bash dev/lab.sh preset free_sponsored`. Há anúncio demonstrativo local de 5 segundos. Use um cliente sem concessão ativa, aguarde o anúncio e confirme a liberação. Retorne ao modo inicial com `bash dev/lab.sh preset hybrid`. As políticas especializadas por instalação permanecem editáveis no painel.
6. **Duas instalações:** repita nas duas VLANs. Sessões e limites precisam corresponder à instalação e ao dispositivo, sem aceitar destino de login de outro gateway.

O cliente com cortesia já ativa não é ideal para iniciar a compra: os fluxos de retorno têm prioridade. Para repetir os testes desde o início, encerre e remova **somente os volumes do laboratório** conforme abaixo.

O teste automatizado usa um laboratório recém-criado e realiza DHCP/contexto nas duas VLANs, Firefox, Pix provisório, webhook autenticado, CoA-ACK, preservação da sessão e do baseline pago, aprovação duplicada, cortesia e accounting:

```bash
python3 dev/lab/validate.py
```

Não o execute enquanto estiver usando as mesmas sessões para testes manuais. O fluxo de cartão não é simulado; testes com o provedor real e Android físico precisam de homologação separada.

## Operação e diagnóstico

```bash
bash dev/lab.sh logs --tail 50 radius
bash dev/lab.sh logs --tail 50 chr
bash dev/lab.sh logs --tail 50 client-a
bash dev/lab.sh stop
bash dev/lab.sh start
```

`stop`/`down` preservam o banco e disco. O cadastro inicial não é refeito e as senhas não são trocadas. Para recriar um laboratório vazio, este comando **exclui os dados fictícios, sessões e configuração do CHR do projeto firespot-lab**:

```bash
bash dev/lab.sh down -v
bash dev/lab.sh start
```

Não publique o disco, console serial, credenciais ou logs privados do CHR. Se você alterar manualmente o RouterOS e quebrar o laboratório, recrie seus volumes em vez de apontar o configurador para um equipamento físico. Os botões que dependem dos helpers de produção em `/opt/firespot-ops` não são instalados por este laboratório; a configuração inicial do CHR é feita pelo configurador de teste.

A licença gratuita do [CHR limita a transmissão a 1 Mbps por interface](https://manual.mikrotik.com/docs/getting-started/routeros-licensing/chr/chr-licensing/), suficiente para estes testes. O laboratório verifica rede cabeada/VLAN, portal e RADIUS; não emula rádio Wi-Fi ou o comportamento do portal cativo do Android.

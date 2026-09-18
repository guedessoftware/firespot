# Preparação-base de NAS MikroTik

> Regra obrigatória: antes de alterar provisionamento, DNS, Hotspot, RADIUS ou conexão, leia também `docs/MIKROTIK_HOTSPOT_CONNECTION_SAFETY.md`. A resolução correta no NAS não garante que um Android com DNS particular resolverá `*.hotspot.internal`. A zona automática não deve ser publicada no `dns-name` do RouterOS; instalações legadas ainda precisam do fallback seguro para o gateway no login final.

## Objetivo

Separar a configuração global do equipamento da configuração de cada instalação Hotspot. Um NAS novo deve estar com a base FireSpot pronta antes que uma instalação possa ser aplicada.

## Fluxo operacional

1. Cadastre primeiro o destino RADIUS em **Infraestrutura > Destinos RADIUS**.
2. Cadastre o NAS como **MikroTik / RouterOS**, informando endereço, usuário, senha e porta SSH, shared secret e o RADIUS da base.
3. O FireSpot conecta por SSH, identifica a versão e aceita somente RouterOS 6 ou 7.
4. O FireSpot cria ou atualiza uma única entrada `/radius` para o serviço `hotspot`, marcada com o comentário `FireSpot Base`.
5. Habilita `/radius incoming` na porta UDP 3799 para promover sessões Pix por CoA.
6. A leitura de confirmação precisa retornar exatamente uma entrada gerenciada, com o destino esperado, e confirmar `accept=yes`/porta 3799. Somente então o NAS fica com estado **Pronta**.
7. Depois disso, as instalações do estabelecimento podem definir e aplicar VLAN, rede, DHCP, Hotspot, portal, walled garden e NAT.

## Política aplicada por instalação V3

Ao aplicar uma instalação do Portal V3, o FireSpot deve manter separadas duas
responsabilidades:

- o gateway da instalação abre o portal cativo; nomes automáticos
  `*.hotspot.internal` ficam apenas como identificação no banco e resultam em
  `dns-name=""` no perfil RouterOS;
- domínios públicos personalizados podem ser aplicados e validados normalmente;
- `cookie` e `mac-cookie` são habilitados por 20 minutos para reconexões curtas;
- o perfil de usuário Hotspot recebe `add-mac-cookie=yes` e
  `mac-cookie-timeout=20m`;
- `login-by=mac` não deve ser habilitado;
- o RADIUS continua autorizando a credencial e limitando saldo, validade,
  aparelho e tempo de sessão.

O MAC cookie é somente uma credencial de retorno de curta duração. Depois de 20
minutos, o Portal V3 revalida a modalidade e mostra a confirmação “Bem-vindo de
volta” quando ainda houver direito de acesso.

## Sincronização do inventário

O botão **Sincronizar** é diferente de **Preparar base**:

- **Sincronizar** faz somente leituras no RouterOS e atualiza o inventário local do FireSpot;
- consulta versão, modelo, uptime, CPU, memória, saúde disponível, latência, clientes, servidores Hotspot, VLANs, entradas RADIUS, recebimento CoA e interfaces;
- usa comandos de leitura compatíveis com RouterOS 6 e 7; a versão é obtida com saída explícita para funcionar também via SSH não interativo;
- informa separadamente se a entrada `FireSpot Base` foi realmente encontrada no RouterOS;
- reconhece cadastros legados marcados como `other` somente após o equipamento confirmar RouterOS 6 ou 7;
- cria e atualiza interfaces de forma idempotente, mantendo seus IDs;
- ignora interfaces dinâmicas, como sessões PPPoE, para que elas não sejam oferecidas como interface-base de uma instalação;
- persiste as sessões de `/ppp active` em um snapshot separado, identificado como serviço externo ao FireSpot;
- classifica as interfaces estáticas pelo tipo do RouterOS. Ethernet e bridge aparecem como recomendadas nas instalações; VLAN, EoIP, VPN, WireGuard e outros tipos permanecem disponíveis como opções avançadas;
- remove uma interface antiga somente quando ela não está vinculada a estabelecimento ou instalação;
- **Preparar base** é a ação separada que cria ou atualiza o RADIUS no MikroTik.

Abrir **Detalhes** nunca dispara uma consulta automaticamente. A sincronização exige um clique explícito e informa sucesso ou falha na própria tela.

O cadastro do NAS é preservado quando a conexão ou a preparação falha. O painel registra o estado e oferece **Preparar base** para uma nova tentativa. A operação é idempotente: **Reaplicar base** atualiza a entrada gerenciada, sem criar uma segunda entrada.

## Limites de segurança

A preparação-base não cria VLAN, endereço IP, pool, DHCP, perfil Hotspot, regras de pagamento, NAT ou arquivos de portal. Ela cria/atualiza somente a entrada RADIUS global e habilita o receptor CoA nativo do RouterOS. Também não tenta autenticar com o shared secret nem troca silenciosamente para o usuário `admin`: usa apenas a credencial SSH cadastrada.

O firewall e o roteamento entre o servidor RADIUS e o NAS precisam permitir UDP
3799. A confirmação local de `/radius incoming` prova que o RouterOS está
escutando, mas não prova o caminho de rede; a validação final é um `CoA-ACK` de
uma sessão de teste. Não abra essa porta para a internet: limite a origem ao
servidor RADIUS pela rede de gerenciamento/VPN.

A prontidão CoA é independente da revisão histórica da base. Isso permite
sincronizar e preparar os NAS gradualmente sem bloquear a manutenção de
instalações que ainda não usam pagamento. A tela mostra `pronto`,
`indisponível` ou `ainda não conferido` conforme a leitura real do equipamento.

Se já existirem várias entradas RADIUS para `hotspot` e nenhuma estiver marcada como `FireSpot Base`, a automação para e solicita revisão manual. Isso evita desabilitar ou sobrescrever uma configuração que não pertence ao FireSpot.

O RADIUS passa a ser canônico no NAS. O campo legado `radius_ip` das instalações é sincronizado automaticamente para rastreabilidade e compatibilidade; a tela da instalação apenas exibe o destino herdado.

## Implantação e validação

```bash
php migrations/apply_028.php
php migrations/smoke_028.php
php tests/nas_base_provisioning_test.php
php tests/nas_sync_test.php
php migrations/apply_029.php
php migrations/smoke_029.php
php migrations/apply_030.php
php migrations/smoke_030.php
php migrations/apply_041.php
php migrations/smoke_041.php
php tests/guest_payment_radius_test.php
```

A migração apenas cria o controle e associa destinos já conhecidos. Ela não acessa nem modifica equipamentos. NAS existentes ficam pendentes até uma preparação explícita pelo painel.

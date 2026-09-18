# Segurança da conexão entre FireSpot e MikroTik Hotspot

Status: regra operacional e arquitetural obrigatória.

Datas dos incidentes de referência: 2026-08-13 e 2026-08-14.

## 1. Objetivo

Evitar regressões em que o FireSpot concede corretamente o acesso no RADIUS, mas o cliente não consegue concluir o login no MikroTik por depender da resolução de um nome DNS privado.

Este documento deve ser consultado antes de qualquer alteração relacionada a:

- cadastro, sincronização ou preparação de NAS MikroTik;
- VLAN, DHCP, DNS, perfil ou servidor Hotspot;
- arquivos de portal instalados no RouterOS;
- captura de `link-login` ou `link-login-only`;
- conexão posterior a cortesia, propaganda ou pagamento;
- login, reconexão, RADIUS ou redirecionamento pós-autenticação;
- suporte a uma nova versão do RouterOS.

## 2. Incidentes e causa confirmada

O cliente foi redirecionado depois da concessão para uma URL semelhante a:

```text
http://codigo.hotspot.internal/login
```

O Android exibiu:

```text
net::ERR_NAME_NOT_RESOLVED
```

Na verificação do NAS, todos os itens abaixo estavam corretos:

- `allow-remote-requests=yes`;
- DHCP entregando somente o gateway da instalação como DNS;
- registro DNS dinâmico do perfil Hotspot apontando para o gateway;
- `ip-of-dns-name` preenchido;
- resolução do nome funcionando quando consultada diretamente no gateway;
- perfil e servidor Hotspot ativos.

A causa foi o comportamento do dispositivo: alguns aparelhos Android mantêm DNS particular, DNS sobre TLS ou outro resolvedor durante o portal cativo e não consultam o DNS recebido por DHCP. O domínio privado não existe no DNS público e, por isso, falha mesmo com o MikroTik corretamente configurado.

No incidente de 2026-08-14 a falha aconteceu ainda antes do carregamento do
HTML externo: o próprio RouterOS abriu inicialmente
`http://codigo.hotspot.internal/login`. Nesse ponto o fallback do Portal V3
ainda não está em execução e, portanto, não pode corrigir a URL.

Conclusões obrigatórias:

> DNS correto no NAS não garante que o navegador do cliente resolverá um domínio privado durante o portal cativo.

> Corrigir somente o formulário final não protege a primeira abertura gerada pelo próprio RouterOS.

### 2.1 Incidente HTTP-CHAP de 2026-08-14

O benefício FIRENETWORK foi reconhecido e a credencial individual foi criada
corretamente no FreeRADIUS, mas o navegador voltou para “Bem-vindo de volta”.
O RouterOS registrou `login failed: invalid username or password` e o
FreeRADIUS confirmou `chap: Password comparison failed`; não houve abertura de
`radacct`.

A causa foi o formulário externo enviar o MD5 do desafio no campo `response`.
No endpoint `/login` do RouterOS, tanto a senha PAP quanto o resultado
HTTP-CHAP devem ser enviados no campo chamado `password`. Em CHAP, o valor é:

```text
MD5(chap-id + senha + chap-challenge)
```

Os bytes de `chap-id` e `chap-challenge` também não podem passar por `trim()`:
bytes de controle válidos seriam removidos e produziriam outro hash. A montagem
foi centralizada em `fs_hotspot_login_password()` e deve ser usada pelas três
jornadas do Portal V3: compra paga, cortesia/patrocinado e benefício
FIRENETWORK.

### 2.2 Incidentes da janela Pix no Android de 2026-08-20 e 2026-08-21

O Pix foi aprovado, a credencial paga foi criada no FreeRADIUS e o binding
temporário foi removido, mas o aparelho continuou mostrando a rede como se
tivesse internet sem conseguir navegar. Não houve abertura de `radacct` para a
credencial paga; desligar e ligar o Wi-Fi fazia o acesso funcionar.

A sequência confirmada foi:

1. a janela `ip-binding type=bypassed` fez o Android validar a rede;
2. ao abrir o aplicativo bancário, a WebView do portal foi suspensa ou fechada;
3. o webhook confirmou o pagamento sem que o navegador chamasse `connect.php`;
4. remover somente o binding deixou uma entrada antiga e não autorizada em
   `/ip hotspot host`;
5. como o Android ainda considerava a rede validada, ele não iniciou uma nova
   captura automaticamente.

A primeira correção, que encerrava a janela pelo webhook e reciclava o host,
foi insuficiente. Um novo teste com outro aparelho em 2026-08-21 confirmou que
isso apenas interrompia o bypass enquanto o aplicativo bancário estava em
primeiro plano. A credencial existia no RADIUS, mas não havia chamada a
`connect.php` nem abertura de `radacct`.

A correção definitiva adotada em 2026-08-21 remove essa dependência do Android:

1. antes de abrir o aplicativo bancário, o aparelho autentica normalmente no
   Hotspot com uma credencial RADIUS provisória, curta e vinculada ao MAC;
2. a sessão recebe `Session-Timeout`, `Max-All-Session`, baixa velocidade e
   accounting a cada 15 segundos;
3. o webhook confirma o Pix, transforma as credenciais no acesso comprado e
   envia CoA para a sessão exata já ativa;
4. o RouterOS aplica o novo tempo e a nova velocidade sem derrubar o aparelho,
   sem depender da WebView e sem pedir nova captura do portal;
5. falha ou atraso de CoA fica registrado e é tentado novamente pelo status e
   pelo reconciliador do servidor. O crédito pago permanece disponível para o
   login tradicional como contingência.

O reconciliador está integrado a `app/cli/cleanup_guest_access.php`, já
executado a cada minuto. Ele seleciona no máximo 20 pedidos por ciclo, respeita
o intervalo entre tentativas e não depende de a WebView continuar aberta.

O tempo usado antes da aprovação não pode consumir o plano comprado. Na
primeira promoção, o FireSpot congela a linha de base do accounting e define
`Max-All-Session = linha de base + duração comprada`; o CoA recebe a duração
comprada integral. O saldo mostrado ao cliente também é limitado à duração do
plano enquanto o primeiro interim accounting ainda estiver atrasado.

O destino do CoA é resolvido pela cadeia imutável do pedido:

```text
guest_order.hotspot_id -> partner_hotspots.nas_id -> nas.nasname
                                  +
radacct(username, NAS, MAC, IP, sessão ativa)
```

Mais de uma sessão correspondente, NAS divergente ou ausência do accounting
falham de forma fechada. O shared secret é entregue ao `radclient` por arquivo
temporário `0600`, nunca por argumento de processo ou log.

Janelas `ip-binding` abertas antes da migração 041 são marcadas como
`legacy_binding` e concluem pelo mecanismo antigo. Pedidos novos não criam
`type=bypassed`.

## 3. Decisão arquitetural

O nome `*.hotspot.internal` continua válido no banco como identificador lógico
da instalação e como compatibilidade com instalações ainda não reaplicadas. Ele
não deve ser publicado no campo `dns-name` do perfil RouterOS.

Para a zona automática, a aplicação deve gravar `dns-name=""`. Sem um nome DNS,
o próprio RouterOS usa o `hotspot-address`/gateway como host da tela de login e
o portal abre por IP antes mesmo de o FireSpot ser carregado.

Domínios públicos personalizados são exceção: podem continuar no RouterOS
quando possuem resolução e operação próprias. Eles precisam ser validados
separadamente e nunca podem ser substituídos por entrada fornecida pelo cliente.

O fallback abaixo continua obrigatório para instalações legadas ou para um
`link-login` capturado antes da reaplicação:

Quando o `link-login` recebido do MikroTik usar o domínio automático `*.hotspot.internal`, o FireSpot deve:

1. validar que o host recebido corresponde ao `dns_name` da instalação atual;
2. obter o `gateway_ip` da mesma instalação, nunca de outro ponto do estabelecimento;
3. validar que o gateway é um IPv4 válido;
4. preservar o caminho `/login` e os parâmetros necessários;
5. enviar a autenticação para `http://GATEWAY_DA_INSTALACAO/login`;
6. recusar hosts externos, caminhos diferentes de `/login` ou contexto de outra instalação.

Exemplo:

```text
Recebido: http://codigo.hotspot.internal/login?dst=...
Usado:    http://10.115.0.1/login?dst=...
```

Essa regra está centralizada em `app/hotspot_login.php`. O wrapper `v3_hotspot_login_url()` atende cortesia, acesso pago e assinantes; a janela Pix reutiliza o mesmo núcleo por `fs_v3_payment_window_reauth_url()`.

### 3.1 Reconexão curta e retorno longo

Reconexão automática não deve usar `login-by=mac`. O modo `mac` autentica o
cliente sem a credencial individual usada pelo FireSpot e muda o modelo de
segurança.

O Portal V3 usa `cookie` e `mac-cookie` por uma janela curta padrão de 20
minutos:

- `login-by` deve conter `cookie` e `mac-cookie`;
- `http-cookie-lifetime=20m`;
- o perfil de usuário Hotspot precisa de `add-mac-cookie=yes` e
  `mac-cookie-timeout=20m`;
- o RADIUS permanece obrigatório e continua impondo senha, MAC associado,
  `Expiration`, `Max-All-Session`, `Session-Timeout` e simultaneidade conforme a
  modalidade;
- término do benefício, limite ou sessão não pode ser prolongado pelo cookie.

Assim, uma saída curta do Wi-Fi pode ser reconectada pelo próprio MikroTik sem
mostrar o portal. Depois da janela curta, o aparelho pode ser reconhecido pelo
FireSpot, mas cortesia, crédito pago ou benefício FIRENETWORK precisam ser
revalidados antes de exibir “Bem-vindo de volta” e pedir a confirmação.

No retorno de uma compra paga, a janela temporária do Pix não faz mais parte da
jornada e nunca pode ser oferecida novamente. O FireSpot procede nesta ordem:

1. confirma uma sessão ativa usando simultaneamente credencial, NAS da
   instalação, MAC e IP informados pelo Hotspot atual;
2. se a sessão exata existe, segue diretamente para o destino original seguro;
3. se não existe sessão, mas o MAC atual é o aparelho da compra, consome uma
   única tentativa automática para o desafio HTTP-CHAP atual;
4. se a tentativa já foi usada ou não pode ser feita, mostra somente o tempo
   restante e o botão **Liberar acesso**.

A chave da tentativa automática inclui o desafio e o contexto capturado pelo
RouterOS e expira rapidamente. Reabrir o mesmo desafio não pode repetir o POST
automaticamente, evitando loops entre o gateway e o Portal V3. O indicador
agregado “há uma sessão deste usuário” não substitui a validação exata do
aparelho atual.

## 4. Invariantes que não podem ser removidas

- A concessão no RADIUS e a autenticação no Hotspot são etapas diferentes. Sucesso no RADIUS não prova que o navegador alcançou `/login`.
- Em HTTP-CHAP, o hash deve ser enviado em `password`, nunca em `response`; contexto CHAP parcial ou inválido deve falhar fechado.
- `chap-id` e `chap-challenge` são bytes de protocolo e não podem ser normalizados com `trim()`.
- O destino final deve pertencer à instalação que originou a jornada: `partner_id`, `hotspot_id`, NAS e gateway precisam permanecer correlacionados.
- Um domínio automático `*.hotspot.internal` não pode ser publicado como `dns-name` no RouterOS; o perfil deve abrir inicialmente pelo gateway.
- Um `link-login` legado com `*.hotspot.internal` deve possuir fallback para o gateway da instalação no login final.
- O cabeçalho HTTP `Host`, parâmetros livres do visitante e URLs externas nunca podem definir o destino das credenciais.
- O fallback não pode usar o gateway do hotspot principal quando a operação veio de uma instalação secundária.
- A validação de host permitido e do caminho `/login` deve acontecer antes da substituição pelo gateway.
- Cortesia, propaganda, pagamento e reconexão devem usar a mesma estratégia de destino.
- Reconexão curta deve usar MAC cookie com prazo limitado, nunca autenticação pura por MAC.
- Cookie HTTP e MAC cookie devem expirar juntos na janela curta para que um cookie de navegador não contorne a tela de retorno longo.
- Reconexão por cookie nunca amplia o saldo ou a validade devolvidos pelo RADIUS.
- Retorno pago não pode renderizar, consultar ou reabrir a janela temporária do Pix.
- Uma sessão paga só fecha automaticamente o portal atual quando credencial, NAS, MAC e IP coincidem; sessão do mesmo usuário em outro aparelho não é suficiente.
- A autenticação automática de retorno pode ocorrer uma única vez por desafio válido e somente para o MAC associado à compra; falha deve cair no botão manual, nunca repetir em loop.
- O pré-acesso Pix novo é uma autenticação RADIUS real; não usar `ip-binding type=bypassed`, redução de lease DHCP ou lista manual de IPs bancários como transporte principal.
- A credencial provisória precisa estar vinculada ao MAC, expirar no RADIUS e possuir tempo, velocidade e interim accounting próprios.
- A promoção só pode atingir a sessão ativa que corresponda simultaneamente ao usuário, NAS da instalação, MAC e IP do pedido.
- Os segundos anteriores ao pagamento nunca podem reduzir a duração comprada.
- CoA duplicado precisa ser idempotente. Depois de `CoA-ACK`, webhooks posteriores não reenviam a promoção.
- A ausência de `radacct` nunca pode ser interpretada como acesso final ativo; deve aguardar e reconciliar no servidor, oferecendo o login tradicional após tentativas limitadas.
- Falha de CoA não cancela pagamento nem apaga credenciais. O pedido continua pago e recuperável.
- O segredo RADIUS não pode aparecer na linha de comando, saída do processo ou log.
- O fluxo legado de `ip-binding` só pode existir para pedidos iniciados antes da migração; não misturar os dois transportes no mesmo pedido.
- A correção do destino não autoriza fallback de concessão: falha no RADIUS continua falhando de forma fechada.
- Não tentar corrigir a zona automática com uma entrada DNS estática manual. Ela continuaria dependendo do resolvedor escolhido pelo aparelho e não protegeria a abertura inicial.

## 5. Checklist obrigatório antes de alterar conexão ou MikroTik

### Contexto

- [ ] Ler este documento e `docs/NAS_MIKROTIK_BASE_PROVISIONING.md`.
- [ ] Identificar estabelecimento, instalação, `hotspot_id`, NAS, interface, VLAN e gateway afetados.
- [ ] Confirmar a versão real do RouterOS pela sincronização do NAS.
- [ ] Mapear os fluxos atingidos: pago, cortesia, patrocinado, reconexão e oferta pós-conexão.

### Inspeção somente leitura

- [ ] Conferir perfil e servidor Hotspot corretos.
- [ ] Conferir `hotspot-address`, `dns-name`, `login-by` e `http-cookie-lifetime`.
- [ ] Para zona automática, confirmar `dns-name` vazio e abertura por gateway.
- [ ] Conferir `add-mac-cookie` e `mac-cookie-timeout` no perfil de usuário usado pelo RADIUS.
- [ ] Conferir DHCP entregando o gateway como DNS.
- [ ] Conferir `allow-remote-requests` e DNS upstream.
- [ ] Resolver pelo gateway somente quando houver domínio público personalizado.
- [ ] Não concluir que o fluxo móvel está aprovado somente porque essa resolução funcionou no NAS.

### Validação da aplicação

- [ ] URL com gateway configurado é aceita.
- [ ] URL com `*.hotspot.internal` é convertida para o gateway da mesma instalação.
- [ ] Perfil novo com zona automática deixa `dns-name` vazio no RouterOS.
- [ ] A primeira URL exibida pelo sistema cativo usa o gateway, antes do Portal V3.
- [ ] Caminho diferente de `/login` é recusado.
- [ ] Host externo é recusado.
- [ ] Domínio personalizado válido conserva seu comportamento.
- [ ] Instalações diferentes do mesmo estabelecimento usam gateways diferentes.
- [ ] Cortesia e pagamento produzem o mesmo destino seguro.
- [ ] A reconexão de uma concessão ativa continua funcionando.
- [ ] O botão da janela Pix cria credencial provisória e envia HTTP-CHAP ao gateway validado da instalação.
- [ ] A sessão provisória aparece no `radacct` com usuário, NAS, MAC e IP esperados.
- [ ] A aprovação com a WebView suspensa recebe `CoA-ACK` e mantém a mesma sessão navegando.
- [ ] A velocidade e o `Session-Timeout` mudam para os valores do plano sem novo DHCP, troca de IP ou alternância do Wi-Fi.
- [ ] O saldo após a promoção contém integralmente os minutos comprados.
- [ ] Webhooks duplicados mantêm um único baseline e não reenviam CoA depois do ACK.
- [ ] Sem accounting ou sem CoA, o reconciliador tenta novamente e o login tradicional continua disponível.
- [ ] O cron de `cleanup_guest_access.php` continua ativo a cada minuto.
- [ ] Pedido legado com `legacy_binding` continua fechando seu binding sem entrar no fluxo novo.
- [ ] Ausência inferior a 20 minutos reconecta por cookie/MAC cookie sem nova jornada.
- [ ] Depois de 20 minutos o direito é revalidado e a tela “Bem-vindo de volta” exige confirmação.
- [ ] Benefício, cortesia ou crédito expirado não reconecta por cookie.
- [ ] Retorno de compra paga não mostra “Internet para o Pix”, tentativas nem duração da janela temporária.
- [ ] Sessão ativa no NAS, MAC ou IP divergente não encerra o portal do aparelho atual.
- [ ] Sessão exata já ativa segue ao destino original; sem sessão, ocorre no máximo um POST automático por desafio e depois aparece saldo + **Liberar acesso**.

### Teste físico

- [ ] Esquecer a rede ou renovar o DHCP antes do teste.
- [ ] Testar Android com DNS particular automático e, quando possível, ativo.
- [ ] Testar abertura do portal, concessão, POST para `/login` e navegação após autenticação.
- [ ] Confirmar no RADIUS/RouterOS que a sessão iniciou.
- [ ] Confirmar que a tela não termina em `ERR_NAME_NOT_RESOLVED`.

## 6. Diagnóstico rápido

Se a concessão foi criada, mas o cliente parou antes de navegar:

1. veja a URL exibida no navegador;
2. se ela terminar em `*.hotspot.internal/login` antes de abrir o FireSpot,
   confirme que o perfil RouterOS ainda possui `dns-name` privado e reaplique a
   política segura;
3. confirme que `dns-name` ficou vazio e que `hotspot-address` é o gateway da
   instalação correta;
4. se a falha ocorrer apenas no envio final, confira o formulário renderizado;
   sua ação deve usar o gateway para a zona privada automática legada;
5. se a reconexão curta não ocorrer, confira `login-by`, os dois timeouts e
   `add-mac-cookie`; não habilite `login-by=mac` como atalho;
6. somente depois investigue senha, CHAP/PAP, RADIUS e accounting.

Isso evita confundir uma falha de resolução no navegador com uma falha de concessão ou de regra RADIUS.

## 7. Testes de não regressão

O teste `tests/partner_admin_security_test.php` deve continuar cobrindo, no mínimo:

- aceitação do gateway configurado;
- conversão de `*.hotspot.internal` para o gateway;
- remoção da zona privada do `dns-name` aplicado ao RouterOS;
- prazo comum de 20 minutos para cookie HTTP e MAC cookie;
- ausência de autenticação pura por MAC;
- preservação de domínio personalizado;
- bloqueio de host externo;
- bloqueio de caminho arbitrário.
- conversão do retorno da janela Pix para o gateway da instalação do pedido;
- falha fechada quando uma zona `*.hotspot.internal` não possui gateway IPv4 válido;
- gateways distintos no retorno de pedidos pertencentes a instalações diferentes.
- cálculo HTTP-CHAP com entradas hexadecimal, octal do RouterOS e bytes puros;
- uso exclusivo do campo `password` nos formulários finais de compra, cortesia e benefício.
- pré-autenticação vinculada ao MAC, com tempo e velocidade provisórios;
- baseline separado do saldo comprado e limite inicial exatamente igual ao plano;
- seleção estrita da sessão por usuário, NAS, MAC e IP;
- payload CoA com `Acct-Session-Id`, `Session-Timeout` e `Mikrotik-Rate-Limit` finais;
- idempotência de webhooks depois do `CoA-ACK`;
- fallback recuperável quando accounting ou CoA não estiver disponível;
- compatibilidade isolada com pedidos `legacy_binding`.
- separação completa entre retorno de crédito pago e a política de janela Pix;
- detecção de sessão ativa por credencial, NAS, MAC e IP, além da trava de uma tentativa automática por desafio.

Qualquer mudança no construtor de URL, no contexto multi-hotspot ou no processo de autenticação precisa executar esse teste, `tests/payment_window_status_test.php`, `tests/guest_payment_radius_test.php` e também renderizar o formulário final com uma instalação real ou fixture equivalente.

O construtor central está em `app/hotspot_login.php`. `v3_hotspot_login_url()` e `fs_v3_payment_window_reauth_url()` devem apenas delegar a ele; não copie novamente a validação de host, caminho ou fallback para outro fluxo.

## 8. Encerramento administrativo de sessão

O botão administrativo para derrubar um cliente também é uma operação multi-NAS e não pode usar o MikroTik global configurado no ambiente. A origem confiável é sempre o accounting do RADIUS:

```text
radacctid -> radacct.nasipaddress -> nas.nasname -> credencial de gestão do NAS
```

Regras obrigatórias:

- Tratar `radacctid` como identificador autoritativo quando estiver disponível; usuário, MAC, IP e NAS enviados pelo navegador não podem substituir os valores recuperados do `radacct`.
- Resolver o equipamento pela correspondência exata entre `radacct.nasipaddress` e `nas.nasname` e falhar de forma fechada se o NAS estiver ausente, duplicado ou não for MikroTik.
- Montar a conexão com a credencial de gestão cadastrada no NAS da sessão. Não usar `ROS_HOST`, `ROS_USER`, `ROS_PASS` ou outro destino global nesse fluxo.
- Consultar e validar a versão real do RouterOS durante a operação, mantendo compatibilidade com RouterOS 6 e 7.
- Preferir o MAC registrado no accounting para atingir somente o aparelho correto; usar usuário ou IP apenas quando o MAC válido não existir.
- Remover primeiro o cookie Hotspot e depois a sessão ativa. Remover apenas a sessão permite que o mesmo cookie reconecte o aparelho automaticamente.
- Confirmar no RouterOS que cookie e sessão não permaneceram antes de fechar o accounting.
- Marcar o `radacct` como encerrado com `Admin-Reset` somente depois da confirmação remota. Falha de conexão, autenticação, comando ou validação não pode produzir um falso sucesso no RADIUS.
- Não devolver a saída bruta do RouterOS, credenciais ou detalhes internos ao navegador. O operador deve receber uma mensagem pública acionável e o servidor deve registrar somente código, NAS e classe segura do erro.

Antes de alterar esse fluxo, execute `tests/session_kick_test.php` e `tests/partner_admin_security_test.php`. Em uma validação real, faça primeiro consultas somente leitura para confirmar NAS, versão e quantidade de correspondências; não derrube uma sessão de produção sem solicitação explícita.

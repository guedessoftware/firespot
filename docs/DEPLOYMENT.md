# Publicar alterações do desenvolvimento local

O fluxo é: **clone local → branch/PR → GitHub → pacote de alterações → conferência no servidor → aplicação e validação**.

O repositório público foi preparado com histórico limpo e arquivos sanitizados. Uma instalação antiga pode ter outro histórico Git e diferenças em arquivos que contêm padrões privados. Por isso, não substitua toda a pasta de produção nem execute `git pull` sobre ela esperando que os históricos sejam equivalentes.

`scripts/release.py` monta um pacote entre dois commits e verifica o conteúdo anterior de cada arquivo antes de escrever. A ferramenta não acessa SSH, não roda SQL e não aplica configurações em equipamentos. O operador executa a publicação no servidor.

## 1. Registrar a base e revisar

Registre o commit público que corresponde à última versão de código adotada pela instalação. A primeira base deve ser escolhida comparando o snapshot publicado com o servidor; ela não pode ser inferida pelo `HEAD` do Git antigo de produção. Nas próximas publicações, registre o `head` do pacote aplicado e mantenha esse registro operacional fora do Git público.

Confira a branch integrada e os checks do GitHub:

```bash
git switch main
git pull --ff-only
FIRESPOT_BASE_COMMIT=<commit-publico-da-ultima-versao-adotada>
git diff --stat "$FIRESPOT_BASE_COMMIT" HEAD
git diff "$FIRESPOT_BASE_COMMIT" HEAD -- app dashboard host conta portal portal-v2 portal-v3 assets
```

`<...>` indica um valor a preencher; não execute o placeholder literalmente. A base precisa ser ancestral do commit final.

Revise a alteração e os testes relevantes. Mudanças no fluxo MikroTik/RADIUS precisam da homologação indicada em `AGENTS.md`, inclusive cenários pagos, cortesia, patrocinado, reconexão e múltiplas instalações. O sucesso do ambiente Docker não substitui esses testes.

## 2. Construir o pacote na máquina local

```bash
python3 scripts/release.py build \
  --base "$FIRESPOT_BASE_COMMIT" \
  --head HEAD \
  --output releases/firespot-change.zip
```

São produzidos `firespot-change.zip` e `firespot-change.zip.sha256`. A pasta `releases` fica fora do Git. Use um nome novo por publicação; a ferramenta recusa sobrescrever um pacote existente.

O manifesto registra commits, caminhos, SHA-256 antes/depois e os arquivos omitidos. Entram arquivos alterados da aplicação e os arquivos públicos da raiz. Documentação, CI, ferramentas e configuração de desenvolvimento ficam fora. Arquivos privados, uploads de clientes, dumps e symlinks são recusados.

Para inspecionar a lista sem extrair o ZIP:

```bash
python3 -m zipfile -l releases/firespot-change.zip
```

Se só houver documentação ou ambiente local, não há pacote de aplicação a publicar. As melhorias já estarão disponíveis no GitHub.

### Migrações

Migrações existentes não podem ser modificadas/deletadas pelo pacote. Uma nova migração com número superior ao último da base só pode entrar com opção explícita:

```bash
python3 scripts/release.py build \
  --base "$FIRESPOT_BASE_COMMIT" --head HEAD \
  --allow-new-migrations --output releases/firespot-change-with-migration.zip
```

Essa opção permite transportar o arquivo; **não executa SQL**. Revise separadamente a compatibilidade, backup do banco, migração, ordem de ativação do código e procedimento de recuperação. Siga o framework de migrações do [README](../README.md#operação-atualizações-e-backup), sem resetar o ledger/checksums. O rollback de arquivos não desfaz DDL nem restaura dados.

## 3. Transferir para uma pasta privada do servidor

Use seu acesso SSH existente. Mantenha a verificação da chave do servidor habilitada. Não coloque o pacote ou os backups dentro do DocumentRoot.

Exemplo com uma pasta de preparação previamente criada com permissão `700`, acessível ao seu usuário SSH:

```bash
scp releases/firespot-change.zip releases/firespot-change.zip.sha256 \
  usuario@servidor:/caminho/privado/firespot-release/
scp scripts/release.py scripts/check_publication.py \
  usuario@servidor:/caminho/privado/firespot-release/
```

Troque usuário, servidor e caminho pelos valores da instalação, sem gravá-los no repositório público. Os dois scripts precisam ficar juntos, pois a ferramenta reutiliza o verificador de privacidade. Eles requerem Python 3 no Linux, sem pacotes Python adicionais.

No servidor, confira o checksum na pasta que contém os arquivos:

```bash
cd /caminho/privado/firespot-release
sha256sum --check firespot-change.zip.sha256
python3 release.py check \
  --package firespot-change.zip \
  --target /var/www/html/hotspot
```

`check` apenas lê os arquivos. Ele confere o hash de cada arquivo anterior, a integridade do pacote e os caminhos permitidos. Arquivos a criar devem estar ausentes. Um conflito impede a aplicação inteira antes da primeira escrita.

Se aparecer divergência, compare o arquivo indicado com a base pública e revise a adaptação. Isso pode representar uma alteração legítima do servidor ou uma sanitização do snapshot. Não há opção `--force`. Não copie diferenças privadas para GitHub nem substitua arquivos para mascarar a divergência.

## 4. Aplicar na janela de manutenção

Faça backup do banco e da configuração privada conforme o README. Combine a janela de manutenção e controle os jobs/atendimentos que dependam dos arquivos alterados. Cada arquivo é substituído individualmente; o conjunto inteiro não é uma troca atômica de versão.

Use um diretório de backup fora da aplicação e com modo `700`. O comando precisa de permissões para escrever nos arquivos de destino e preservar seus donos; em instalações administradas por root, execute com `sudo`:

```bash
sudo install -d -m 700 /var/backups/firespot-code
sudo python3 /caminho/privado/firespot-release/release.py apply \
  --package /caminho/privado/firespot-release/firespot-change.zip \
  --target /var/www/html/hotspot \
  --backup-dir /var/backups/firespot-code
```

A ferramenta verifica novamente os arquivos, mantém lock no diretório de backups e guarda conteúdo, permissões e donos anteriores em uma subpasta privada. O caminho e o comando de rollback são exibidos. Em uma falha de escrita tratável, restaura os arquivos já aplicados; uma queda de energia/processo exige inspeção do backup e recuperação operacional.

Configurações privadas, chaves, uploads e migrações históricas ficam preservados. Apenas os caminhos do manifesto são alterados. Nenhum banco, credencial RADIUS, NAS, DHCP ou perfil Hotspot é alterado pelo comando.

Valide sintaxe PHP dos arquivos modificados e o comportamento afetado. Se a instalação usar OPcache ou processos persistentes, recarregue os serviços relevantes conforme a operação do servidor para ativar o código. Verifique login, permissões, logs e os cenários da alteração antes de encerrar a janela.

Registre o `head` do manifesto como a nova base **depois** da validação. Não faça commit de backups/manifestos privados nem tente substituir o histórico Git antigo durante o deploy.

## 5. Restaurar o código

Use o caminho exato do backup exibido na aplicação:

```bash
sudo python3 /caminho/privado/firespot-release/release.py rollback \
  --backup /var/backups/firespot-code/<pasta-do-backup> \
  --target /var/www/html/hotspot
```

O rollback normal verifica todos os arquivos e backups antes de restaurar. Se alguém alterou o código depois da release, ele recusa sobrescrever essa alteração. Recarregue os processos necessários e valide a versão restaurada.

O rollback restaura arquivos removidos e remove os arquivos criados pelo pacote. Não reverte banco, pagamento, sessão de internet nem operações feitas em equipamentos pela aplicação. Esses efeitos precisam de procedimento próprio, planejado antes da publicação.

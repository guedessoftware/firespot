# Banco de dados e migrações FireSpot

## Instalação nova

Siga primeiro os passos de contas SQL e ambiente privado no
[README principal](../README.md#instalação-completa). Com o banco já criado,
**completamente vazio**, use:

```bash
cd /var/www/firespot
sudo php app/cli/install_fresh_database.php \
  --confirm-empty-database=FIRESPOT_EMPTY_DATABASE
sudo -u www-data php app/cli/migrations.php --status
```

O bootstrap usa a conexão `DB_*`, que precisa de permissões DDL temporárias.
Ele recusa tabelas, views, rotinas e eventos existentes e usa o bloqueio de
migração. Não é um comando de atualização ou restauração.

O procedimento importa o baseline 056 de estrutura, recupera somente os
defaults públicos necessários do histórico 016–040 e completa o SQL
idempotente 044–057. Isso inicializa planos/capacidades, apresentações de
portal e configuração publicitária segura, sem inventar clientes, contas ou
equipamentos. Nenhum comando RouterOS é executado.

O ledger registra 001–056 como `adopted`, representadas pela estrutura e
inicialização do banco novo, e 057 como `applied`. Registra os checksums reais
dos artefatos desta distribuição. Isso **não significa que os smokes
históricos de implantação foram executados**. O bootstrap está limitado à
release até 057 e precisa de nova homologação antes de aceitar versões
posteriores.

Depois da inicialização, retire DDL da conta da aplicação conforme o README.
DDL no MariaDB não é totalmente transacional: se ocorrer falha após iniciar a
importação, pode existir estrutura parcial. Não use esse banco como produção
nem tente sobrescrever um banco existente com o bootstrap.

## Atualização e adoção de uma instalação existente

O ponto de entrada para o histórico é `app/cli/migrations.php`. Os arquivos
`apply_NNN.php` pertencem ao legado 001–043 e não devem ser executados
diretamente. Comandos antigos nos documentos de incidentes/provisionamento
registram contexto histórico; não são o procedimento atual de atualização.

| Ação | Uso |
| --- | --- |
| `--status` | Lista versões, pendências e divergências de checksum. Não altera o banco. |
| `--verify` | Valida checksums e executa os smokes registrados. Alguns são específicos de implantação. |
| `--adopt-current` | Adota 001–043 num banco legado após fingerprint e smokes; exige confirmação e root. |
| `--apply` | Aplica migrações modernas pendentes, após adoção do legado; exige root. |

`--status` e `--verify` usam a conexão da aplicação. `--adopt-current` e
`--apply` usam o socket administrativo MariaDB local. Não existe suporte
automático a administração de banco remoto nesse runner.

Antes da adoção, faça backup e valide uma cópia isolada. Gere o fingerprint:

```bash
php app/cli/schema_baseline.php --fingerprint
php app/cli/migrations.php --status
```

Depois de revisar o estado do banco, a adoção controlada usa:

```bash
sudo php app/cli/migrations.php --adopt-current \
  --schema-sha256=FINGERPRINT_REVISADO_DE_64_CARACTERES \
  --confirm-adoption=FIRESPOT_CURRENT_SCHEMA
```

Este comando não reaplica o SQL legado. Migrações modernas serão aplicadas
depois, em manutenção, com `--apply`. Resolva erros de smoke ou checksum antes
de promover a atualização; não edite o ledger para ocultar divergências.

Automações em `/opt/firespot-ops`, mencionadas no histórico operacional, não
acompanham a distribuição pública e não são requisito executável disponível
para instalar um banco novo.

## Limites dos smokes e baselines públicos

- Smokes 053–055 validam cenários fictícios com estabelecimento/NAS e
  associações específicos. Ausência desses cenários num banco novo pode
  resultar em falha de `--verify`; isso não autoriza inserir equipamentos
  fictícios na produção. Outros smokes também pressupõem defaults ou estados
  de rollout.
- O baseline mais recente versionado é `baseline/056_schema.sql`, acompanhado
  dos metadados JSON. Contém somente DDL, sem registros ou credenciais.
- O CLI de baseline usa a versão mais recente inventariada, atualmente 057.
  Portanto `schema_baseline.php --check` solicita um baseline 057 ainda não
  incluído. Use `--fingerprint` para inspecionar o schema atual; gerar um novo
  baseline é tarefa de desenvolvimento/revisão, não de instalação.
- A publicação anonimiza a migração 053 e cenários antigos. **Não substitua
  artefatos já registrados numa instalação real por versões públicas**:
  checksums podem diferir. Preserve o histórico original e teste a integração
  numa cópia.

## Regras para novas migrações

- CLI apenas; execução HTTP é proibida.
- Use `NNN_nome.sql` e declare idempotência em
  `app/cli/migration_framework.php`; quando aplicável, adicione `smoke_NNN.php`.
- Não altere SQL/aplicadores já registrados: isso gera divergência de checksum.
- SQL com `DELIMITER`, trigger, procedure, function ou event é recusado pelo
  parser atual e exige procedimento específico revisado.
- DML transacional registra a alteração e a versão na mesma transação;
  operações DDL exigem tratamento de falha parcial e backup.
- Preserve baselines sem dados de produção, identificadores privados ou
  credenciais.

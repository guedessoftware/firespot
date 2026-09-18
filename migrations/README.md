# Migrações FireSpot

O ponto único de entrada é `app/cli/migrations.php`. Os arquivos
`apply_NNN.php` existentes pertencem ao histórico 001–043 e não devem mais ser
executados diretamente. Migrações novas usam `NNN_nome.sql`, declaração de
idempotência em `app/cli/migration_framework.php` e, quando aplicável,
`smoke_NNN.php`.

Regras:

- execução por HTTP é proibida;
- `--status` e `--verify` usam a conta restrita da aplicação;
- `--adopt-current` e `--apply` exigem `root` e usam o socket administrativo do
  MariaDB, sem armazenar nova senha;
- 001–043 são adotadas somente após fingerprint e smokes; nunca são
  reaplicadas na produção existente;
- alteração do SQL ou do aplicador histórico depois do registro gera erro de
  checksum;
- SQL complexo com `DELIMITER`, trigger, procedure, function ou event é
  recusado pelo runner e exige revisão manual explícita;
- o baseline é apenas de schema: não contém registros, credenciais ou PII.

Primeira adoção controlada deste host:

```bash
sudo bash /opt/firespot-ops/firespot_data_baseline_root.sh
```

Verificação rotineira:

```bash
php app/cli/migrations.php --status
php app/cli/migrations.php --verify
php app/cli/schema_baseline.php --check
```

Antes de uma release, execute `--verify`, aplique pendências em manutenção e
execute `--verify` novamente. O estado da produção nunca deve ser inferido
somente pelo nome dos arquivos.

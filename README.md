# FireSpot

Plataforma PHP de gestão de Hotspot com integração MikroTik/RouterOS e
FreeRADIUS, acesso pago via Pix/Mercado Pago, cortesia, acesso patrocinado e
benefícios para assinantes.

## Estrutura

- `app/`: regras de negócio, integrações e comandos de manutenção.
- `dashboard/`: central administrativa.
- `portal/`, `portal-v2/`, `portal-v3/`: jornadas e versões do portal.
- `conta/`: área do assinante.
- `assets/nas/`: arquivos do portal para RouterOS.
- `migrations/`: migrações e baselines contendo somente estrutura do banco.
- `tests/`: verificações automatizadas.

## Configuração

O projeto requer PHP e MariaDB/MySQL. Os testes atuais utilizam PHP 8.1;
as integrações podem exigir extensões como PDO MySQL, cURL, mbstring, GD,
OpenSSL, Sodium e SSH2, além do FreeRADIUS e suas ferramentas.

Use `.env.example` como modelo, com valores próprios. Em produção, mantenha
o ambiente **fora do DocumentRoot** e informe `FIRESPOT_ENV_PATH`, por exemplo
`/etc/firespot/firespot.env`. O arquivo privado deve ter acesso restrito.
Defina `APP_URL`, banco, chaves e credenciais das integrações necessárias.
O exemplo não contém credenciais prontas para uso.

Leia [o guia de migrações](migrations/README.md) antes de executar qualquer
comando que altere o banco. Os instaladores históricos precisam de revisão
para a infraestrutura atual e não devem ser executados em servidores em uso.

## Distribuição pública

Esta publicação foi preparada a partir de uma cópia do projeto, com histórico
Git novo. Não inclui ambientes privados, backups, registros do banco,
mídias enviadas por clientes, relatórios/auditorias operacionais internos ou
identificadores reais usados em cenários específicos de implantação.

Nomes de NAS, códigos de estabelecimento e dados de dispositivos presentes
nos cenários de implantação e testes foram substituídos por exemplos
fictícios. A migração `053` também usa esses exemplos: **não substitua arquivos
de migração já registrados na produção por esta distribuição**, pois seus
checksums podem diferir. Valide a migração de uma instalação existente em
ambiente separado, preservando seu histórico e sua configuração privada.

Alguns testes de integração dependem de um banco preparado e de automações
operacionais externas. Execute-os apenas em um ambiente de teste isolado.

## Segurança de conexão

Antes de alterar MikroTik, RADIUS ou liberação de acesso, leia
[AGENTS.md](AGENTS.md),
[a política de segurança](docs/MIKROTIK_HOTSPOT_CONNECTION_SAFETY.md) e
[o provisionamento-base de NAS](docs/NAS_MIKROTIK_BASE_PROVISIONING.md).
Preserve o destino de login validado e o gateway da instalação correta.

## Verificação antes de publicar

```bash
python3 scripts/check_publication.py
gitleaks git . --redact=100 --no-banner
```

O GitHub Actions executa essas verificações em pushes e pull requests.
O `.gitignore` impede a inclusão automática dos diretórios privados conhecidos;
revise também os arquivos que já estão versionados antes de enviar alterações.

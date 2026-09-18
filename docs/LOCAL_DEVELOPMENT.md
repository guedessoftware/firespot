# Desenvolvimento local no Linux

Você pode editar o FireSpot na sua máquina e usar o GitHub para compartilhar o código. O servidor de produção fica responsável pela aplicação em uso; o chat e o editor podem trabalhar sobre o clone local.

O ambiente usa Ubuntu 22.04, Apache/PHP 8.1 e MariaDB 10.6 em contêineres. Um proxy Nginx recebe o acesso do navegador. O código fica na pasta do clone e é montado para leitura no Apache; mudanças em PHP, CSS e JavaScript aparecem sem reconstruir a imagem. Banco e uploads ficam em volumes próprios.

## 1. Pré-requisitos

Instale Git, Python 3, Docker Engine e o plugin Docker Compose na sua distribuição Linux, seguindo a [instalação oficial do Docker](https://docs.docker.com/engine/install/). O Docker precisa estar iniciado e seu usuário precisa conseguir executar seus comandos.

```bash
git --version
python3 --version
docker info
docker compose version
```

Use uma versão atual do Compose com suporte a `up --wait`. Se `docker info` mostrar erro de permissão, resolva o acesso ao daemon seguindo a documentação da sua distribuição/Docker antes de continuar.

## 2. Clonar e iniciar

Execute **na sua máquina local**, em uma pasta de desenvolvimento:

```bash
git clone https://github.com/guedessoftware/firespot.git
cd firespot
python3 dev/setup.py --port 8090
bash dev/local.sh up -d --build --wait --wait-timeout 240
bash dev/local.sh exec -T web php dev/bootstrap.php
```

A primeira construção baixa os pacotes e pode demorar alguns minutos. Nas próximas inicializações, a imagem e o banco são reutilizados.

Acesse **http://localhost:8090/dashboard/login.php**.

| Campo | Valor |
| --- | --- |
| Usuário | `admin_local` |
| Senha | Gerada na sua máquina; consulte `cat dev/.local/admin-password` |

Não há senha padrão compartilhada. O bootstrap importa a estrutura, aplica os defaults públicos e cria o administrador local. Não importa clientes, pagamentos, equipamentos ou credenciais do servidor. Cadastre estabelecimentos e dados fictícios pelo painel.

Para verificar o ambiente:

```bash
python3 dev/smoke.py
bash dev/local.sh ps
```

O teste entra com o administrador local, verifica o CSRF e confirma que arquivos internos não estão acessíveis pelo navegador. Ele usa exclusivamente o endereço de loopback e a porta local configurada.

## Porta ocupada

A porta **8080 não é usada** por esta configuração. O padrão é **8090**, publicado apenas em `127.0.0.1`.

Antes de criar o ambiente, o gerador testa a disponibilidade da porta. Se 8090 também estiver ocupada, escolha outra:

```bash
python3 dev/setup.py --port 8091
bash dev/local.sh up -d --build --wait --wait-timeout 240
bash dev/local.sh exec -T web php dev/bootstrap.php
```

Nesse caso, acesse `http://localhost:8091/dashboard/login.php`. A porta fica em `dev/.local/compose.env`, e a URL da aplicação é gerada com o mesmo número. Use `dev/local.sh` para os comandos Compose, pois ele carrega essa configuração.

Executar novamente `python3 dev/setup.py` preserva as chaves, as senhas e a porta existentes, inclusive com os contêineres ligados. Uma configuração parcial é recusada para evitar trocar chaves por acidente.

Se precisar trocar a porta **depois** da criação:

1. Pare o ambiente: `bash dev/local.sh down`.
2. Confirme que a nova porta está livre, por exemplo: `ss -ltn 'sport = :8091'`.
3. Edite apenas `FIRESPOT_LOCAL_PORT` em `dev/.local/compose.env` e `APP_URL` em `dev/.local/firespot.env`, usando a mesma porta nos dois arquivos.
4. Execute novamente `bash dev/local.sh up -d --wait --wait-timeout 240` e `bash dev/local.sh exec -T web php dev/bootstrap.php`. Isso atualiza a URL pública do banco local.

Não apague as chaves para mudar uma porta.

## 3. Trabalhar no clone

Abra a pasta `firespot` no editor e no ambiente do Codex que você usa **na sua máquina**, com o workspace apontando para esse clone. A conversa anterior não faz parte do Git; o código, o README, o `AGENTS.md` e estes guias fornecem o contexto do projeto.

Crie uma branch antes de editar:

```bash
git switch -c feature/minha-alteracao
```

Edite normalmente. PHP não exige build; o OPcache está desligado no ambiente local. Mudanças em `dev/Dockerfile`, nas extensões ou na configuração Apache/PHP exigem reconstrução:

```bash
bash dev/local.sh up -d --build --wait --wait-timeout 240
```

Comandos úteis:

```bash
bash dev/local.sh logs --tail 80 web
bash dev/local.sh exec -T web php -l dashboard/login.php
python3 tests/local_workflow_test.py
python3 dev/smoke.py
bash dev/local.sh down
```

`down` preserva banco e uploads. **`down -v` apaga os volumes locais**, inclusive dados fictícios e arquivos enviados. Se você realmente quiser um banco novo, use essa opção sabendo que será necessário executar o bootstrap novamente. Preserve `dev/.local` para manter as chaves.

Quando uma alteração incluir novas migrações, use o procedimento de migração do [README](../README.md#operação-atualizações-e-backup), validado no banco local. O bootstrap não atualiza silenciosamente um banco existente com ledger diferente. Não altere migrações já publicadas nem seus checksums para fazê-lo passar.

## 4. Segredos e isolamento

As credenciais são geradas em `dev/.local`, com diretório `700` e arquivos `600`. Essa pasta é ignorada pelo Git, recusada pela verificação de publicação e excluída do contexto de construção Docker. A configuração PHP é copiada para `/etc/firespot` dentro do contêiner, fora do diretório web.

Não copie `.env`, `master.key`, dumps, mídias de clientes nem credenciais reais da produção para o clone. Não use `git add -f` para arquivos privados. O administrador local e o banco usam senhas novas, independentes da produção.

Por padrão, PHP e banco usam exclusivamente uma rede interna; somente o proxy tem a rede necessária para publicar a porta do navegador. Essa separação segue o [modelo de redes do Docker](https://docs.docker.com/engine/network/). O banco não publica porta e não são iniciados jobs de infraestrutura, pagamentos ou integração. Chamadas do PHP a APIs externas e equipamentos exigem liberar a rede explicitamente. Navegadores podem acessar recursos externos por conta própria; o isolamento dos contêineres não controla a rede do navegador.

Para desenvolver integrações, use somente contas de teste e equipamentos de laboratório em uma configuração separada, revisada por você. O ambiente local não oferece callbacks públicos para webhooks. Não use tokens reais para tentar reproduzir pagamentos ou operações MikroTik.

## 5. RADIUS e MikroTik no desenvolvimento

O Docker inicia **FreeRADIUS 3 com SQL, PAP, CHAP, contador de tempo e accounting**. O serviço `bootstrap` instala o banco vazio, cria o administrador local e concede ao usuário SQL do RADIUS somente as permissões necessárias. Os segredos são gerados em `dev/.local/`; nenhuma porta UDP é publicada no host.

Após atualizar um ambiente existente, execute novamente `python3 dev/setup.py` para gerar os dois novos segredos sem substituir as chaves anteriores. Valide o serviço com:

```bash
bash dev/local.sh exec -T web php dev/radius/smoke.php
```

O teste troca pacotes UDP reais, valida PAP e CHAP, rejeita senha/MAC incorretos, verifica tempo restante e confirma Start/Interim/Stop no SQL. Não aponte o RADIUS ou o NAS de produção para este banco local.

Para testar o navegador atrás de um MikroTik, use o [laboratório completo com CHR, duas VLANs e clientes Linux com Firefox](HOTSPOT_LAB.md). O Pix do laboratório é simulado e não gera cobranças.

Antes de analisar ou alterar o fluxo de conexão, leia as duas regras permanentes:

- [Segurança da conexão](MIKROTIK_HOTSPOT_CONNECTION_SAFETY.md).
- [Provisionamento da base NAS/MikroTik](NAS_MIKROTIK_BASE_PROVISIONING.md).

Homologue acesso pago, cortesia, patrocinado, reconexão, múltiplas instalações, accounting e CoA no laboratório. Não troque o destino validado do navegador por uma dependência obrigatória de `*.hotspot.internal`.

Os scripts externos em `/opt/firespot-ops` não fazem parte deste repositório. Os fluxos que dependem deles continuam sujeitos às limitações descritas no README; o ambiente local não os implementa nem os ativa automaticamente.

## 6. Enviar alterações e publicar

Revise o que será incluído e faça commit somente do código/documentação necessários:

```bash
git status --short
git diff
git add dashboard/arquivo-alterado.php
python3 scripts/check_publication.py
git diff --cached
git commit -m "Descrever a alteração"
git push -u origin feature/minha-alteracao
```

Autentique o push com a sua conta GitHub na máquina local. Não copie a chave de publicação do servidor. Abra um pull request, espere os checks e integre a branch à `main` depois da revisão.

O GitHub Actions verifica privacidade, testa os casos de falha da publicação, constrói o ambiente local e testa login/CSRF. **Push não faz deploy automático.** Depois da integração, siga o [guia de publicação em produção](DEPLOYMENT.md).

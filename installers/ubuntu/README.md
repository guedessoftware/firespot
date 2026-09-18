# Instalador histórico Ubuntu

`install.sh` foi preservado como referência de uma implantação anterior. **Não
execute esse script para instalar a versão pública atual.**

Ele pressupõe um `schema.sql` que não acompanha esta distribuição, configura
ambiente no diretório web e contém uma configuração antiga de cliente RADIUS
com origem abrangente. Sua configuração Nginx também não substitui as
proteções Apache presentes nos `.htaccess` atuais.

O procedimento atualizado está no [README principal](../../README.md#instalação-completa):

1. pacotes, código e permissões;
2. banco vazio e contas SQL restritas;
3. ambiente/chaves fora do DocumentRoot;
4. `app/cli/install_fresh_database.php` para inicialização;
5. primeiro administrador, Apache e HTTPS;
6. FreeRADIUS SQL, accounting, contador de tempo e CoA;
7. cadastro de NAS, Hotspot e jobs.

Instalações existentes seguem o [guia de migrações](../../migrations/README.md),
com backup, preservação de checksums e homologação em cópia isolada.

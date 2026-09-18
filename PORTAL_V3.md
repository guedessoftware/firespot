# Portal V3 e múltiplas carteiras

> A arquitetura futura consolidada do Portal V3, Minha Conta FIRENETWORK,
> integração HubSoft e benefício por aparelhos está documentada em
> `docs/PORTAL_V3_ACCOUNT_HUBSOFT_ARCHITECTURE.md`. Este arquivo permanece como
> referência operacional da implementação atual de venda anônima.

O Portal V3 oferece venda anônima de acesso Wi-Fi em três etapas: escolha do tempo, pagamento e conexão. Ele é opt-in por estabelecimento; a migração mantém todos os hosts no fluxo existente.

## Ativação segura

1. Confirme `PAYMENT_CREDENTIAL_KEY` no `.env` e proteja o arquivo para leitura exclusiva do administrador e do grupo do PHP (neste servidor: `root:www-data`, permissão `640`).
2. Aplique `php migrations/apply_003.php` e `php migrations/apply_004.php`.
3. Entre em **Dashboard > Recebimentos**.
4. Para recebimento independente, cadastre a carteira Mercado Pago do estabelecimento.
5. Cadastre ao menos uma opção de tempo/preço para o estabelecimento.
6. Marque **Recebimento independente**, vincule a carteira e só então selecione **Portal V3 anônimo**.
7. Reaplique a configuração do host/NAS para incluir os domínios do checkout no walled garden.

Estabelecimentos sem recebimento independente continuam utilizando a carteira e os planos globais.

### Walled garden de pagamento

O padrão do FireSpot usa os hosts exatos validados no NAS de referência, sem
curingas no menu `/ip hotspot walled-garden ip`:

- `sdk.mercadopago.com`
- `api.mercadopago.com`
- `mercadopago.com`
- `www.mercadopago.com`
- `mercadopago.com.br`
- `www.mercadopago.com.br`
- `www.mercadolibre.com`
- `api.mercadolibre.com`
- `http2.mlstatic.com`

A lista central está em `app/hotspot_walled_garden.php` e é compartilhada pela
aplicação automática no NAS e pelo script de instalação exibido no dashboard.

## Privacidade

O FireSpot não cria cadastro de cliente no Portal V3 e não grava nome, CPF, telefone ou e-mail no pedido. Os campos obrigatórios do pagamento são renderizados pelo Payment Brick e enviados ao Mercado Pago apenas para processar a transação.

## Segurança e operação

- Access Tokens adicionais ficam criptografados com Sodium.
- A chave de criptografia não fica no banco.
- O webhook usa uma URL assinada e sempre consulta o pagamento na carteira correta.
- Valor, referência e recebedor são validados antes da liberação RADIUS.
- Acesso RADIUS é idempotente e usa um usuário temporário por compra.
- O crédito é cumulativo: desconexões não consomem o saldo e o mesmo dispositivo pode retomar em visitas futuras.
- A retomada usa cookie seguro do pedido e, no HotSpot, o MAC enviado pelo MikroTik; não usa IP como identidade.
- Uma compra com saldo impede nova cobrança e segue diretamente para a reconexão.
- Agende `app/cli/cleanup_guest_access.php` a cada minuto. Além das credenciais sem saldo, canceladas ou reembolsadas, a rotina fecha qualquer janela temporária de pagamento que não tenha sido encerrada pelo webhook; crédito pago não expira por tempo de calendário.

## Provedores futuros

As tabelas registram o campo `provider` e a camada `app/payment_provider.php` centraliza a integração. Nesta versão, apenas `mercadopago` está habilitado.

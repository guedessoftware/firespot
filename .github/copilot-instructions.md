# FireSpot - Instruções para agentes

Antes de alterar o projeto:

1. Leia `AGENTS.md`.
2. Considere o código atual, migrations e documentação existente como fonte de verdade.
3. Não assuma que `portal/`, `portal-v2/` ou `portal-v3/` é o fluxo ativo sem verificar.
4. Não altere banco, MikroTik, RADIUS, autenticação, pagamentos ou liberação de Internet sem entender o fluxo existente.
5. Para alterações MikroTik/Hotspot, siga obrigatoriamente as documentações indicadas em `AGENTS.md`.
6. Nunca grave credenciais, tokens, senhas ou conteúdo de `.env` no Git.
7. Preserve compatibilidade com PHP 7.4 enquanto o projeto exigir essa versão.
8. Execute os testes relevantes antes de considerar uma alteração concluída.
9. Faça mudanças pequenas e verificáveis; não reestruture módulos fora do escopo solicitado.

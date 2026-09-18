# Liberação por anúncio

Resumo do fluxo:
- A política unificada de cortesia define duração, limites, identificação e se o anúncio é obrigatório.
- `portal/anuncio.php` e `portal-v3/courtesy.php` selecionam somente anúncio próprio da unidade ou campanha global explicitamente autorizada.
- A prova temporizada é vinculada à sessão, `partner_id` e anúncio elegível.
- `portal/api/ad_grant.php` e `portal-v3/api/courtesy_grant.php` liberam acesso somente pelo ledger unificado e rollout RADIUS; não há bypass local.
- Impressões e interações são gravadas em `custom_ads_events` com o contexto do estabelecimento da exibição.

As tabelas não são criadas durante requisições. Aplique `migrations/022_partner_admin_portal.sql` e `migrations/023_runtime_schema_consolidation.sql` pelos respectivos scripts `apply_022.php` e `apply_023.php`.

Variáveis relevantes (fallback):
- `AD_MINUTES_DEFAULT` e `AD_COOLDOWN_DEFAULT` via `.env` (opcional).

Imagens aceitam upload JPG, PNG ou WEBP validado, ou URL HTTP/HTTPS. Links com esquemas executáveis são recusados. Desativar um anúncio preserva métricas; a aplicação não oferece exclusão comum.

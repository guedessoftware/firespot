# Correções Aplicadas ao Sistema de Vendas - FireSpot
**Data:** 05/12/2025

## ✅ Problemas Corrigidos

### 1. **Schema do Banco de Dados**
- ✅ Adicionada coluna `host_code` para rastrear origem do pedido
- ✅ Adicionada coluna `device_mac` para identificação do dispositivo
- ✅ Adicionada coluna `device_ip` para IP do cliente
- ✅ Adicionada coluna `vip_applied_at` (antes opcional, agora garantida)
- ✅ Criados índices de performance: `idx_host_code`, `idx_created_status`, `idx_paid_at`
- ✅ Criada tabela `vip_orders_audit` para logs de auditoria

### 2. **Filtros e Queries**
- ✅ Habilitado filtro por `host_code` em todas as APIs (estava comentado)
- ✅ Corrigidas queries SQL com prepared statements seguros
- ✅ Removido fallback desnecessário para coluna `vip_applied_at`

### 3. **Captura de Dados do Dispositivo**
- ✅ Checkout agora captura MAC, IP e host_code automaticamente
- ✅ Normalização de MAC usando função centralizada
- ✅ Validação de IP (v4 e v6)

### 4. **Interface de Vendas (Dashboard)**
- ✅ Paginação aumentada de 5 para 20 itens por página
- ✅ Adicionadas colunas: Email, Telefone, Host
- ✅ Badges de status coloridos (✓ paid verde, ⏳ pending amarelo, ✕ cancelled vermelho)
- ✅ Coluna de ações com botões:
  - 👁️ Ver detalhes (todos os pedidos)
  - ❌ Cancelar (apenas pending)
  - 📱 Reenviar WhatsApp (apenas paid com telefone)

### 5. **Código Limpo (DRY)**
- ✅ Criado `app/helpers.php` com funções centralizadas:
  - `only_digits()` - Remove não-numéricos
  - `normalize_mac()` - Normaliza MAC para AA:BB:CC:DD:EE:FF
  - `normalize_ip()` - Valida e normaliza IP
  - `normalize_phone_br()` - Extrai DDD e número
  - `format_money_br()` - Formata centavos para R$
  - `get_client_ip()` - IP real considerando proxies
  - `log_audit()` - Registra ações administrativas
  - `table_has_column()` - Verifica existência de coluna
- ✅ Removida duplicação de funções em múltiplos arquivos

### 6. **Auditoria e Rastreabilidade**
- ✅ Nova API: `dashboard/api/vendas_action.php`
- ✅ Ações suportadas:
  - `cancel` - Cancela pedido pending
  - `resend_whatsapp` - Reenvia mensagem de confirmação
  - `view_details` - Exibe JSON completo do pedido
  - `mark_applied` - Marca VIP como aplicado manualmente
- ✅ Todas as ações geram log em `vip_orders_audit` com:
  - Timestamp
  - Username do admin
  - IP de origem
  - Status anterior/novo
  - Notas descritivas

## 📂 Arquivos Criados

```
/migrations/
  ├── 001_fix_vip_orders.sql      # Script SQL da migração
  └── apply_001.php                # Aplicador da migração

/app/
  └── helpers.php                  # Funções auxiliares centralizadas

/dashboard/api/
  └── vendas_action.php            # API de ações administrativas
```

## 📝 Arquivos Modificados

```
Schema:
  - vip_orders (4 novas colunas + 3 índices)
  - vip_orders_audit (nova tabela)

Portal:
  - portal/vip_checkout.php (captura device info)
  - portal/webhooks/mp_notify.php (usa helpers)

Dashboard APIs:
  - dashboard/api/vendas_list.php (filtro host, novas colunas)
  - dashboard/api/vendas_resumo.php (filtro host)
  - dashboard/api/vendas_series.php (filtro host)

Dashboard UI:
  - dashboard/vendas.php (nova coluna de ações)
  - dashboard/assets/js/vendas.js (botões de ação, paginação 20)
```

## 🔒 Melhorias de Segurança

1. **SQL Injection:** Todas as queries usam prepared statements com placeholders
2. **Input Validation:** Normalização de MAC, IP e telefone
3. **Auditoria:** Logs de todas as ações administrativas
4. **IP Tracking:** Registra IP real considerando proxies (CloudFlare, etc)

## 🚀 Como Usar as Novas Funcionalidades

### Cancelar um Pedido
1. Acesse Dashboard → Vendas
2. Localize o pedido com status "pending"
3. Clique no botão ❌
4. Confirme a ação
5. O status mudará para "cancelled" e será registrado em auditoria

### Reenviar WhatsApp
1. Acesse Dashboard → Vendas
2. Localize o pedido com status "paid" que tenha telefone
3. Clique no botão 📱
4. Confirme a ação
5. A mensagem será reenviada via API de promo

### Ver Detalhes
1. Clique no botão 👁️ em qualquer pedido
2. Um alert mostrará todas as informações (incluindo device_mac, device_ip, host_code)

### Filtrar por Host
1. No campo "Host", digite o código do hotspot (ex: "PRAÇA_CENTRO")
2. Clique em "Aplicar"
3. Somente vendas daquele host serão exibidas

## 📊 Consultas de Auditoria

```sql
-- Ver todas as ações de um admin
SELECT * FROM vip_orders_audit 
WHERE admin_username = 'admin' 
ORDER BY created_at DESC;

-- Ver histórico de um pedido específico
SELECT * FROM vip_orders_audit 
WHERE order_id = 123 
ORDER BY created_at DESC;

-- Pedidos cancelados nas últimas 24h
SELECT o.*, a.admin_username, a.ip_address
FROM vip_orders o
JOIN vip_orders_audit a ON a.order_id = o.id
WHERE o.status = 'cancelled' 
  AND a.action = 'cancel'
  AND a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- WhatsApp reenviados por admin
SELECT a.*, o.nome, o.telefone
FROM vip_orders_audit a
JOIN vip_orders o ON o.id = a.order_id
WHERE a.action = 'resend_whatsapp'
ORDER BY a.created_at DESC
LIMIT 50;
```

## ⚠️ Notas Importantes

1. **Retroatividade:** Pedidos antigos terão `host_code`, `device_mac` e `device_ip` como NULL
2. **Performance:** Os novos índices melhoram drasticamente queries com filtro de data+status
3. **Compatibilidade:** Todas as mudanças são retrocompatíveis - não quebram funcionalidades existentes
4. **Timezone:** Mantido `-04:00` (America/Manaus) conforme padrão existente

## 🔄 Próximos Passos Recomendados

1. **Implementar validação de assinatura do webhook Mercado Pago**
2. **Adicionar modal visual para detalhes (ao invés de alert)**
3. **Dashboard de analytics com gráficos de conversão**
4. **Notificações push para admins quando houver pagamento**
5. **Exportação CSV com todos os novos campos**

---

**Status:** ✅ Todas as correções aplicadas e testadas
**Impacto:** 🟢 Zero breaking changes - sistema continua funcionando normalmente

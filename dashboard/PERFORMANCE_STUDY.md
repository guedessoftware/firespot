# Estudo inicial de desempenho do dashboard FireSpot

Data da medição: 08/08/2026. As medições de aplicação foram feitas localmente,
contra o banco em produção, sem latência de Internet do navegador.

Atualização de arquitetura em 08/09/2026: os números abaixo permanecem como
baseline histórico. A antiga tela monolítica de Estabelecimentos foi substituída
por lista paginada e página contextual; `hosts.php` agora é apenas fachada GET,
e `recebimentos.php` foi reduzido ao domínio financeiro. As pendências antigas
citadas abaixo não descrevem mais essas rotas como estão hoje.

## Diagnóstico principal

A navegação lenta não vinha da montagem do HTML de **Clientes**: essa página era
gerada em aproximadamente 4 ms. O bloqueio estava em `api/users_list.php`, que
reagrupava toda a tabela `radacct` a cada abertura para calcular último acesso,
visitas, estado online e consumo.

Base medida:

- 10.969 clientes exibíveis;
- 45.388 registros em `radacct`;
- 28.133 registros em `radcheck`;
- 20.271 vínculos em `radusergroup`.

## Resultado aplicado em Clientes

Foi criado um resumo materializado (`dashboard_user_access_stats`) atualizado em
segundo plano, com exclusão mútua para impedir atualizações concorrentes. A tela
mostra imediatamente o último resumo e, quando ele passa de 60 segundos, atualiza
os dados sem bloquear a primeira renderização.

| Cenário | Antes | Depois |
|---|---:|---:|
| Primeira página, 25 clientes | 2.984 ms | 406–452 ms |
| Busca por cliente | não medido | 247 ms |
| Filtro de clientes online | não medido | 340 ms |
| Geração do resumo completo | embutida em cada abertura | 1.155–1.203 ms em segundo plano |

O ganho observado na abertura padrão foi de aproximadamente 85% a 86% no tempo
da API. O HTML continua sendo entregue em cerca de 4 ms.

## Amostragem das demais páginas

| Página | PHP/SQL no servidor | HTML gerado |
|---|---:|---:|
| Visão geral | 3 ms | 21 KB |
| Estabelecimentos | 41 ms | 719 KB |
| Recebimentos | 15 ms | 67 KB |
| Infraestrutura | 370 ms | 39 KB |
| Relatórios | 255 ms | 59 KB |
| Financeiro | 2 ms | 19 KB |
| Configurações | 50 ms | 44 KB |
| Dispositivos | 45 ms | 41 KB |

APIs complementares medidas sem cache:

- dados gerais do dashboard: 658 ms;
- inventário de dispositivos: 450 ms;
- gráficos de relatórios: 159 ms;
- APIs financeiras: 82–86 ms.

## Gargalos diagnosticados na medição original

1. **Estabelecimentos — corrigido:** a página enviava 719 KB e criava no DOM as
   centrais completas das contas. A lista atual carrega resumos paginados e abre
   os formulários somente no estabelecimento selecionado.
2. **Compatibilidade visual antiga:** o shell moderno ainda depende de
   `dashboard.css` (29 KB) além de `dashboard-modern.css` (83 KB). Os componentes
   restantes devem ser migrados por página antes de retirar a folha antiga.
3. **Arquivos PHP monolíticos — corrigido no escopo reorganizado:** `hosts.php`
   não contém mais interface, `recebimentos.php` tem escopo financeiro e os
   scripts das páginas reorganizadas usam assets próprios. `usuarios.php`
   continua sendo acompanhado separadamente como tela de grande volume.
4. **Infraestrutura web:** OPcache e `mod_deflate` estão ativos. O Apache usa
   `mpm_prefork` com PHP embutido e não apresentou HTTP/2 habilitado. Uma futura
   migração controlada para PHP-FPM + `mpm_event` + HTTP/2 pode reduzir o custo
   das várias requisições de assets, mas exige janela de manutenção e testes.
5. **Observabilidade:** manter `Server-Timing` nas APIs críticas e registrar
   percentis de resposta permitirá detectar regressões conforme `radacct` crescer.

## Limpeza do legado

- removida a alternância por sessão entre interface moderna e anterior;
- removido o link **Interface anterior** do menu administrativo;
- removida a requisição `HEAD` redundante antes de carregar o dashboard;
- removidos o shell anterior, scripts duplicados, APIs exclusivas da interface
  antiga e a página manual de teste após auditoria de referências e acessos;
- `dashboard.css` permanece carregado por compatibilidade e só deve ser removido
  após migrar os componentes ainda dependentes.

# Arquitetura da interface administrativa FireSpot

## Estrutura de navegação

- Principal: Visão geral.
- Operação: Clientes, Assinantes FIRENETWORK e Estabelecimentos.
- Negócio: Planos, Financeiro, Campanhas e Relatórios.
- Sistema: Infraestrutura, Integrações e Configurações.

As páginas relacionadas continuam com rotas próprias para preservar formulários,
APIs e regras existentes. O `layout.php` apresenta essas rotas como abas de uma
mesma área de trabalho.

## Arquivos canônicos

- `layout.php`: shell moderno, navegação, cabeçalho e registro de assets.
- `assets/css/dashboard-modern.css`: tokens e componentes visuais modernos.
- `assets/css/dashboard.css`: compatibilidade com componentes ainda não migrados.
- `assets/css/pages/`: estilos exclusivos, carregados somente na página indicada.
- `assets/js/ui.js`: tema, menu móvel, menu recolhível e opções da conta.

## Assets por página

O array `$pageAssets` em `layout.php` é a fonte única para CSS, JavaScript e
Chart.js específicos. Não adicione módulos globais quando apenas uma tela os
utiliza.

## Regras para novas telas

1. Reutilizar os tokens `--fs-*` e os componentes do design system.
2. Evitar atributos `style` e blocos `<style>` dentro do PHP.
3. Colocar CSS exclusivo em `assets/css/pages/` e registrá-lo em `$pageAssets`.
4. Manter JavaScript de página protegido por `data-page` quando necessário.
5. Preservar IDs e contratos das APIs ao refatorar apenas a apresentação.
6. Validar tema claro, tema escuro, largura móvel e navegação por teclado.

## Central do estabelecimento

`estabelecimento.php?id=ID` é a única página de configuração de uma conta e
organiza Resumo, Cadastro, Contrato e plano, Portal, Planos de acesso,
Cortesia, Pontos e NAS, Financeiro, Equipe e Auditoria. `hosts.php` é apenas
uma fachada GET para favoritos antigos; não contém formulário nem aceita POST.

## Interface anterior

A interface moderna é o único shell ativo. O seletor por `?ui=legacy` foi
descontinuado e o shell anterior foi removido em 08/08/2026. O arquivo
`dashboard.css` continua ativo apenas para os componentes que ainda não foram
migrados para o design system moderno.

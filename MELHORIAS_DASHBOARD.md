# Melhorias Implementadas no Dashboard

## 1. Host de Origem na Lista de Clientes Online

### Modificações realizadas:
- **API `dashboard_data.php`**: Modificada para incluir mapeamento de `nasipaddress` para nomes amigáveis de NAS
- **Interface `index.php`**: Adicionada nova coluna "Host de Origem" na tabela de clientes online
- **JavaScript `table-online.js`**: Atualizado para exibir a nova coluna com informações do host de origem

### Funcionamento:
- A API busca informações dos NAS cadastrados e mapeia o `nasipaddress` do `radacct` para nomes amigáveis
- Prioriza `shortname` sobre `nasname` e adiciona `description` quando disponível
- Exibe o nome do NAS/host de onde o cliente está conectado na tabela de clientes online

## 2. Gráficos de Relatórios por Host e NAS

### Novos arquivos criados:
- **API `api/charts_report.php`**: Nova API para fornecer dados agregados de acessos
- **Melhorias em `relatorios.js`**: Adicionados novos gráficos interativos
- **Estilos em `dashboard.css`**: Novos estilos para controles de gráficos

### Funcionalidades:
- **Gráfico de Acessos por Host**: Gráfico de barras mostrando distribuição de acessos por host/parceiro
- **Gráfico de Acessos por NAS**: Gráfico de rosca mostrando distribuição por servidor NAS
- **Controle de Período**: Seletor para escolher período (7, 15, 30, 60, 90 dias)
- **Dados Detalhados**: Tooltips com informações de usuários únicos e dispositivos únicos

### Dados utilizados:
1. **Para Hosts**: Prioriza dados da tabela `partner_uses` quando disponível, com fallback para `radacct`
2. **Para NAS**: Dados agregados da tabela `radacct` com JOIN para `nas` para nomes amigáveis
3. **Métricas**: Total de acessos, usuários únicos, dispositivos únicos por período

### Interface:
- Gráficos responsivos que se adaptam ao desktop (2 colunas) e mobile (1 coluna)
- Controles intuitivos para seleção de período
- Integração com o design existente do dashboard

## Como usar:

1. **Dashboard Principal**: A coluna "Host de Origem" agora aparece automaticamente na lista de clientes online
2. **Página de Relatórios**: Novos gráficos aparecem na seção inferior da página
3. **Controle de Período**: Use o seletor de período para alterar a janela de dados dos gráficos

## Compatibilidade:

- Funciona com ou sem as tabelas `partners` e `partner_uses`
- Fallback automático para dados do `radacct` quando tabelas específicas não existem
- Responsivo para dispositivos móveis e desktop
- Mantém compatibilidade com o tema claro/escuro existente
<?php

declare(strict_types=1);

/**
 * Registro canônico da arquitetura de informação da Central FireSpot.
 *
 * Páginas podem continuar existindo como fachadas de compatibilidade, mas
 * menus, metadados, seções contextuais e destinos legados partem deste mapa.
 */

/** @return array<string,array{title:string,section:string,description:string}> */
function fs_control_center_page_meta(): array
{
    return [
        'index.php' => ['title'=>'Visão geral','section'=>'Principal','description'=>'Acompanhe operação, acessos e saúde da rede em tempo real.'],
        'usuarios.php' => ['title'=>'Clientes','section'=>'Operação','description'=>'Gerencie cadastros, perfis de acesso e histórico dos clientes.'],
        'dispositivos.php' => ['title'=>'Dispositivos','section'=>'Clientes','description'=>'Consulte aparelhos reconhecidos e recorrência de uso da rede.'],
        'privacidade.php' => ['title'=>'Privacidade dos clientes','section'=>'Clientes','description'=>'Consulte recibos anônimos e a auditoria de exclusões de conta.'],
        'assinantes.php' => ['title'=>'Assinantes FIRENETWORK','section'=>'Operação','description'=>'Acompanhe benefício, contas, aparelhos e atendimento dos assinantes.'],
        'estabelecimentos.php' => ['title'=>'Estabelecimentos','section'=>'Operação','description'=>'Consulte contas, planos, pontos e alertas sem duplicar suas configurações.'],
        'estabelecimento.php' => ['title'=>'Estabelecimento','section'=>'Estabelecimentos','description'=>'Configure a conta selecionada em seu contexto administrativo e operacional.'],
        'hosts.php' => ['title'=>'Estabelecimentos','section'=>'Compatibilidade','description'=>'Rota anterior preservada durante a reorganização da Central.'],
        'hotspots.php' => ['title'=>'Estabelecimentos','section'=>'Compatibilidade','description'=>'Pontos são administrados exclusivamente dentro do estabelecimento.'],
        'host_acessos.php' => ['title'=>'Administradores dos estabelecimentos','section'=>'Estabelecimentos','description'=>'Supervisione vínculos e sessões administrativas.'],
        'planos.php' => ['title'=>'Planos','section'=>'Negócio','description'=>'Administre Planos FireSpot, assinaturas e planos de acesso.'],
        'financeiro.php' => ['title'=>'Financeiro','section'=>'Negócio','description'=>'Acompanhe vendas, carteiras, recebimentos, conciliação e repasses.'],
        'vendas.php' => ['title'=>'Financeiro','section'=>'Negócio','description'=>'Acompanhe pagamentos, receita e desempenho das vendas.'],
        'recebimentos.php' => ['title'=>'Carteiras e recebimentos','section'=>'Financeiro','description'=>'Administre destinos financeiros e recebimentos por estabelecimento.'],
        'auditoria_vendas.php' => ['title'=>'Auditoria financeira','section'=>'Financeiro','description'=>'Investigue eventos de pagamento e confirmações de liberação.'],
        'campanhas.php' => ['title'=>'Campanhas','section'=>'Negócio','description'=>'Administre anúncios, promoções, ofertas e desempenho.'],
        'anuncios.php' => ['title'=>'Campanhas','section'=>'Negócio','description'=>'Crie e organize a comunicação exibida nos portais de acesso.'],
        'promocoes.php' => ['title'=>'Promoções','section'=>'Campanhas','description'=>'Defina benefícios e condições promocionais para os clientes.'],
        'monetizacao.php' => ['title'=>'Monetização e repasses','section'=>'Financeiro','description'=>'Administre Marketplace, ledger, fechamentos e repasses.'],
        'relatorios.php' => ['title'=>'Relatórios','section'=>'Negócio','description'=>'Analise utilização, sessões, tráfego e indicadores históricos.'],
        'infraestrutura.php' => ['title'=>'Infraestrutura','section'=>'Sistema','description'=>'Monitore NAS, RADIUS, filas e comunicação de rede.'],
        'nas.php' => ['title'=>'Infraestrutura','section'=>'Sistema','description'=>'Monitore equipamentos NAS, interfaces e comunicação de rede.'],
        'integracoes.php' => ['title'=>'Integrações','section'=>'Sistema','description'=>'Administre provedores externos e seus estados de funcionamento.'],
        'configuracoes.php' => ['title'=>'Configurações','section'=>'Sistema','description'=>'Administre somente regras gerais, segurança e preferências do FireSpot.'],
    ];
}

/** @return list<array{label:string,items:list<array<string,mixed>>}> */
function fs_control_center_nav_groups(): array
{
    return [
        ['label'=>'Principal','items'=>[
            ['href'=>'index.php','label'=>'Visão geral','icon'=>'overview','pages'=>['index.php']],
        ]],
        ['label'=>'Operação','items'=>[
            ['href'=>'usuarios.php','label'=>'Clientes','icon'=>'users','pages'=>['usuarios.php','dispositivos.php','privacidade.php']],
            ['href'=>'assinantes.php','label'=>'Assinantes FIRENETWORK','icon'=>'subscriber','pages'=>['assinantes.php'],'capability'=>'subscribers.view'],
            ['href'=>'estabelecimentos.php','label'=>'Estabelecimentos','icon'=>'store','pages'=>['estabelecimentos.php','estabelecimento.php','hosts.php','hotspots.php','host_acessos.php']],
        ]],
        ['label'=>'Negócio','items'=>[
            ['href'=>'planos.php','label'=>'Planos','icon'=>'plans','pages'=>['planos.php']],
            ['href'=>'financeiro.php','label'=>'Financeiro','icon'=>'finance','pages'=>['financeiro.php','vendas.php','recebimentos.php','auditoria_vendas.php','monetizacao.php']],
            ['href'=>'campanhas.php','label'=>'Campanhas','icon'=>'campaigns','pages'=>['campanhas.php','anuncios.php','promocoes.php']],
            ['href'=>'relatorios.php','label'=>'Relatórios','icon'=>'reports','pages'=>['relatorios.php']],
        ]],
        ['label'=>'Sistema','items'=>[
            ['href'=>'infraestrutura.php','label'=>'Infraestrutura','icon'=>'network','pages'=>['infraestrutura.php','nas.php']],
            ['href'=>'integracoes.php','label'=>'Integrações','icon'=>'access','pages'=>['integracoes.php']],
            ['href'=>'configuracoes.php','label'=>'Configurações','icon'=>'settings','pages'=>['configuracoes.php']],
        ]],
    ];
}

/** @return array<string,list<array{href:string,label:string,icon:string}>> */
function fs_control_center_tabs(): array
{
    $subscriberTabs = [
        ['href'=>'assinantes.php?section=overview','label'=>'Visão geral','icon'=>'overview'],
        ['href'=>'assinantes.php?section=rollout#subscriber-rollout','label'=>'Ativação','icon'=>'subscriber'],
        ['href'=>'assinantes.php?section=mappings#hubsoft-mappings','label'=>'Planos e perfis','icon'=>'plans'],
        ['href'=>'assinantes.php?section=accounts#subscriber-support','label'=>'Contas e aparelhos','icon'=>'device'],
        ['href'=>'assinantes.php?section=support#subscriber-support','label'=>'Atendimento','icon'=>'users'],
        ['href'=>'assinantes.php?section=observability#subscriber-observability','label'=>'Observabilidade','icon'=>'reports'],
    ];
    $clientTabs = [
        ['href'=>'usuarios.php','label'=>'Cadastros','icon'=>'users'],
        ['href'=>'dispositivos.php','label'=>'Dispositivos','icon'=>'device'],
        ['href'=>'privacidade.php','label'=>'Privacidade','icon'=>'audit'],
    ];
    $financeTabs = [
        ['href'=>'financeiro.php?section=overview','label'=>'Visão geral','icon'=>'overview'],
        ['href'=>'vendas.php','label'=>'Vendas','icon'=>'finance'],
        ['href'=>'recebimentos.php?section=wallets','label'=>'Carteiras','icon'=>'wallet'],
        ['href'=>'recebimentos.php?section=receipts','label'=>'Recebimentos','icon'=>'finance'],
        ['href'=>'financeiro.php?section=delivery','label'=>'Conciliação e entrega','icon'=>'audit'],
        ['href'=>'monetizacao.php?section=overview','label'=>'Repasses','icon'=>'plans'],
        ['href'=>'auditoria_vendas.php','label'=>'Auditoria','icon'=>'audit'],
        ['href'=>'financeiro.php?section=settings','label'=>'Configurações','icon'=>'settings'],
    ];
    $campaignTabs = [
        ['href'=>'campanhas.php?section=overview','label'=>'Visão geral','icon'=>'overview'],
        ['href'=>'anuncios.php','label'=>'Anúncios','icon'=>'campaigns'],
        ['href'=>'promocoes.php','label'=>'Promoções','icon'=>'plans'],
        ['href'=>'campanhas.php?section=commercial','label'=>'Campanhas comerciais','icon'=>'campaigns'],
        ['href'=>'campanhas.php?section=offers','label'=>'Ofertas','icon'=>'access'],
        ['href'=>'campanhas.php?section=performance','label'=>'Desempenho','icon'=>'reports'],
        ['href'=>'campanhas.php?section=settings','label'=>'Configurações','icon'=>'settings'],
    ];
    $infrastructureTabs = [
        ['href'=>'infraestrutura.php?section=overview','label'=>'Visão geral','icon'=>'overview'],
        ['href'=>'nas.php','label'=>'Equipamentos NAS','icon'=>'network'],
        ['href'=>'nas.php?section=radius#servidores-radius','label'=>'Destinos RADIUS','icon'=>'access'],
        ['href'=>'infraestrutura.php?section=freeradius','label'=>'Serviço FreeRADIUS','icon'=>'settings'],
        ['href'=>'infraestrutura.php?section=jobs','label'=>'Jobs e filas','icon'=>'reports'],
        ['href'=>'infraestrutura.php?section=logs','label'=>'Logs sanitizados','icon'=>'audit'],
    ];
    $planTabs = [
        ['href'=>'planos.php?section=firespot','label'=>'Planos FireSpot','icon'=>'plans'],
        ['href'=>'planos.php?section=subscriptions','label'=>'Assinaturas e cotas','icon'=>'store'],
        ['href'=>'planos.php?section=access','label'=>'Planos de acesso globais','icon'=>'access'],
        ['href'=>'planos.php?section=partner-access','label'=>'Planos por estabelecimento','icon'=>'store'],
    ];
    $configurationTabs = [
        ['href'=>'configuracoes.php?section=overview','label'=>'Visão geral','icon'=>'overview'],
        ['href'=>'configuracoes.php?section=general','label'=>'Plataforma','icon'=>'settings'],
        ['href'=>'configuracoes.php?section=identity','label'=>'Identidade','icon'=>'store'],
        ['href'=>'configuracoes.php?section=governance','label'=>'Governança','icon'=>'audit'],
        ['href'=>'configuracoes.php?section=security','label'=>'Segurança','icon'=>'access'],
    ];

    return [
        'assinantes.php'=>$subscriberTabs,
        'usuarios.php'=>$clientTabs,
        'dispositivos.php'=>$clientTabs,
        'privacidade.php'=>$clientTabs,
        'financeiro.php'=>$financeTabs,
        'vendas.php'=>$financeTabs,
        'recebimentos.php'=>$financeTabs,
        'auditoria_vendas.php'=>$financeTabs,
        'monetizacao.php'=>$financeTabs,
        'planos.php'=>$planTabs,
        'campanhas.php'=>$campaignTabs,
        'anuncios.php'=>$campaignTabs,
        'promocoes.php'=>$campaignTabs,
        'infraestrutura.php'=>$infrastructureTabs,
        'nas.php'=>$infrastructureTabs,
        'configuracoes.php'=>$configurationTabs,
    ];
}

/** @return array<string,array{label:string,group:string,capability:string}> */
function fs_control_center_partner_sections(): array
{
    return [
        'summary'=>['label'=>'Resumo','group'=>'Geral','capability'=>'partners.view'],
        'registration'=>['label'=>'Cadastro','group'=>'Geral','capability'=>'partners.view'],
        'contract'=>['label'=>'Contrato e plano','group'=>'Geral','capability'=>'partners.view'],
        'portal'=>['label'=>'Portal e aparência','group'=>'Operação','capability'=>'partners.view'],
        'access-plans'=>['label'=>'Planos de acesso','group'=>'Operação','capability'=>'partners.view'],
        'courtesy'=>['label'=>'Cortesia','group'=>'Operação','capability'=>'partners.view'],
        'points'=>['label'=>'Pontos e NAS','group'=>'Operação','capability'=>'partners.view'],
        'finance'=>['label'=>'Financeiro','group'=>'Negócio e acesso','capability'=>'partners.view'],
        'team'=>['label'=>'Equipe','group'=>'Negócio e acesso','capability'=>'partner.administrators.view'],
        'audit'=>['label'=>'Auditoria','group'=>'Negócio e acesso','capability'=>'partners.view'],
    ];
}

function fs_control_center_partner_section(string $section, bool $canViewAdministrators): string
{
    $sections = fs_control_center_partner_sections();
    if (!isset($sections[$section])) return 'summary';
    if ($section === 'team' && !$canViewAdministrators) return 'summary';
    return $section;
}

function fs_control_center_partner_url(int $partnerId, string $section = 'summary', array $extra = []): string
{
    if ($partnerId <= 0) return 'estabelecimentos.php';
    $query = ['id'=>$partnerId,'section'=>$section] + $extra;
    return 'estabelecimento.php?' . http_build_query($query);
}

function fs_control_center_legacy_partner_section(string $legacyTab): string
{
    return [
        'summary'=>'summary',
        'portal'=>'portal',
        'plans'=>'access-plans',
        'billing'=>'finance',
        'network'=>'points',
        'access'=>'courtesy',
        'administrators'=>'team',
    ][$legacyTab] ?? 'summary';
}

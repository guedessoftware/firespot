<?php declare(strict_types=1);if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}?>
<section class="stack">
  <article class="card"><h1>Auditoria do estabelecimento</h1><p class="subtle">Últimas 100 ações deste estabelecimento. Senhas, tokens, segredos e identificadores pessoais não são armazenados nos metadados exibidos.</p></article>
  <article class="card"><div class="table-wrap"><table><thead><tr><th>Data</th><th>Ator</th><th>Ação</th><th>Recurso</th><th>Contexto seguro</th></tr></thead><tbody>
  <?php foreach($partnerAuditRows as $audit):$metadata=json_decode((string)($audit['metadata']??''),true);if(!is_array($metadata))$metadata=[];?>
    <tr><td><?=host_h($audit['created_at'])?></td><td><?=host_h($audit['actor_name']?:($audit['actor_type']==='firespot'?'Equipe FireSpot':($audit['actor_type']==='system'?'Automação':'Administrador do estabelecimento')))?></td><td><code><?=host_h($audit['action'])?></code></td><td><?=host_h($audit['target_type'])?><?=($audit['target_id']??'')!==''?' #'.host_h($audit['target_id']):''?></td><td><?=host_h($metadata?json_encode(partner_admin_clean_metadata($metadata),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'—')?></td></tr>
  <?php endforeach;?><?php if(!$partnerAuditRows):?><tr><td colspan="5">Nenhum evento disponível.</td></tr><?php endif;?></tbody></table></div></article>
</section>

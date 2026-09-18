<?php
declare(strict_types=1);
if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}
$canManageCourtesy=partner_admin_role_has($role,'courtesy.manage')&&fs_partner_has_entitlement($pdo,$partnerId,'courtesy.manage',true);
$editing=$courtesyDraft?:$courtesyEffective;
$modeLabel=static fn(string $mode):string=>$mode==='online'?'Somente tempo conectado':'Tempo corrido desde a liberação';
?>
<section class="stack host-courtesy">
  <article class="card host-courtesy__hero">
    <div>
      <h1>Padrão de cortesia</h1>
      <p class="subtle">Este padrão abastece pontos sem regra própria. Ativação, duração e limites de cada instalação ficam em Pontos Hotspot.</p>
    </div>
    <a class="btn primary" href="?page=hotspots">Configurar por ponto</a>
  </article>

  <article class="card host-courtesy__settings">
    <h2>Padrão do estabelecimento</h2>
    <div class="host-courtesy__summary" aria-label="Resumo da política publicada">
      <div class="host-courtesy__metric"><span>Duração</span><strong><?=(int)$courtesyEffective['grant_minutes']?> min</strong></div>
      <div class="host-courtesy__metric"><span>Intervalo após terminar</span><strong><?=(int)$courtesyEffective['cooldown_after_end_minutes']?> min</strong></div>
      <div class="host-courtesy__metric"><span>Por dispositivo</span><strong><?=$courtesyEffective['device_max_grants']===null?'Livre':(int)$courtesyEffective['device_max_grants']?></strong><small><?=host_h($courtesyEffective['device_period_minutes']===null?'Sem janela':'A cada '.(int)$courtesyEffective['device_period_minutes'].' min')?></small></div>
      <div class="host-courtesy__metric"><span>Consumo</span><strong class="host-courtesy__metric-text"><?=host_h($modeLabel((string)$courtesyEffective['consumption_mode']))?></strong></div>
    </div>

    <?php if($canManageCourtesy):?>
      <section class="host-courtesy__block" aria-labelledby="courtesy-presets-title">
        <h3 id="courtesy-presets-title">Modelos rápidos</h3>
        <div class="host-courtesy__presets">
          <?php foreach(fs_partner_courtesy_presets() as $presetCode=>$preset):?>
            <form method="post" class="host-courtesy__preset">
              <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>">
              <input type="hidden" name="action" value="courtesy_preset">
              <input type="hidden" name="return_page" value="courtesy">
              <input type="hidden" name="preset_code" value="<?=host_h($presetCode)?>">
              <strong><?=host_h($preset['label'])?></strong>
              <p class="subtle"><?=host_h($preset['description'])?></p>
              <button class="btn" type="submit">Usar como rascunho</button>
            </form>
          <?php endforeach;?>
        </div>
      </section>

      <form method="post" id="host-courtesy-draft" class="host-courtesy__editor">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>">
        <input type="hidden" name="action" value="courtesy_draft_save">
        <input type="hidden" name="return_page" value="courtesy">
        <fieldset class="host-courtesy__fieldset">
          <legend>Disponibilidade e tempo</legend>
          <div class="host-courtesy__fields">
            <label class="host-courtesy__switch-field"><span>Situação</span><span class="host-courtesy__switch-control"><input type="checkbox" name="enabled" value="1" <?=(int)$editing['enabled']===1?'checked':''?>><span>Oferecer cortesia</span></span></label>
            <label>Duração da cortesia (min)<input type="number" name="grant_minutes" min="1" max="1440" value="<?=(int)$editing['grant_minutes']?>" required></label>
            <label>Validade do crédito (min)<input type="number" name="credit_validity_minutes" min="1" max="525600" value="<?=(int)$editing['credit_validity_minutes']?>" required></label>
            <label>Como o tempo é consumido<select name="consumption_mode"><option value="online" <?=$editing['consumption_mode']==='online'?'selected':''?>>Somente conectado</option><option value="elapsed" <?=$editing['consumption_mode']==='elapsed'?'selected':''?>>Tempo corrido</option></select></label>
          </div>
        </fieldset>
        <fieldset class="host-courtesy__fieldset">
          <legend>Limites de uso</legend>
          <div class="host-courtesy__fields host-courtesy__fields--limits">
            <label>Identificação<select name="auth_mode"><option value="anonymous" <?=$editing['auth_mode']==='anonymous'?'selected':''?>>Dispositivo</option><option value="account" <?=$editing['auth_mode']==='account'?'selected':''?>>Conta</option><option value="account_device" <?=$editing['auth_mode']==='account_device'?'selected':''?>>Conta + dispositivo</option></select></label>
            <label>Usos por dispositivo<input type="number" name="device_max_grants" min="1" max="1000000" value="<?=host_h($editing['device_max_grants']??'')?>" placeholder="sem limite"></label>
            <label>Janela por dispositivo (min)<input type="number" name="device_period_minutes" min="1" max="525600" value="<?=host_h($editing['device_period_minutes']??'')?>" placeholder="sem janela"></label>
            <label>Usos por conta<input type="number" name="account_max_grants" min="1" max="1000000" value="<?=host_h($editing['account_max_grants']??'')?>" placeholder="sem limite"></label>
            <label>Janela por conta (min)<input type="number" name="account_period_minutes" min="1" max="525600" value="<?=host_h($editing['account_period_minutes']??'')?>" placeholder="sem janela"></label>
            <label>Intervalo após terminar (min)<input type="number" name="cooldown_after_end_minutes" min="0" max="525600" value="<?=(int)$editing['cooldown_after_end_minutes']?>" required></label>
          </div>
        </fieldset>
      </form>

      <div class="host-courtesy__action-bar">
        <div><strong><?=$courtesyDraft?'Rascunho v'.(int)$courtesyDraft['revision']:'Política publicada ativa'?></strong><small><?=$courtesyDraft?'Revise duração, limites e intervalo antes de publicar.':'As mudanças só entram em produção após a publicação.'?></small></div>
        <div class="host-actions">
          <button class="btn" type="submit" form="host-courtesy-draft">Salvar rascunho</button>
          <?php if($courtesyDraft):?><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="courtesy_publish"><input type="hidden" name="return_page" value="courtesy"><button class="btn primary" type="submit">Publicar política</button></form><?php endif;?>
        </div>
      </div>
    <?php endif;?>
  </article>

  <article class="card host-courtesy__technical">
    <h2>Controles técnicos protegidos</h2>
    <p class="subtle">Esses parâmetros permanecem administrados pela FireSpot e não são alterados pelos formulários comerciais.</p>
    <div class="host-courtesy__technical-grid">
      <div><strong>Execução</strong><span><?=host_h(strtoupper((string)$courtesyEffective['enforcement_method']))?></span></div>
      <div><strong>Bloqueio durante acesso ativo</strong><span><?=$courtesyEffective['block_while_active']?'Ativo':'Inativo'?></span></div>
      <div><strong>Excluir acesso pago ativo</strong><span><?=$courtesyEffective['exclude_active_paid']?'Sim':'Não'?></span></div>
      <div><strong>Reserva</strong><span><?=(int)$courtesyEffective['reservation_ttl_seconds']?> s</span></div>
      <div><strong>Publicidade obrigatória</strong><span><?=$courtesyEffective['requires_ad']?'Conforme portal patrocinado':'Não'?></span></div>
    </div>
  </article>

  <article class="card host-courtesy__history">
    <h2>Histórico e rollback</h2>
    <p class="subtle">Restaurar apenas copia uma revisão anterior para o rascunho; a política publicada continua ativa até uma nova confirmação.</p>
    <div class="table-wrap"><table><thead><tr><th>Revisão</th><th>Estado</th><th>Duração</th><th>Intervalo final</th><th>Publicação</th><th>Ação</th></tr></thead><tbody><?php foreach($courtesyHistory as $revision):?><tr><td>v<?=(int)$revision['revision']?></td><td><?=host_h($revision['state'])?></td><td><?=(int)$revision['grant_minutes']?> min</td><td><?=(int)$revision['cooldown_after_end_minutes']?> min</td><td><?=host_h(host_datetime($revision['published_at']))?></td><td><?php if($canManageCourtesy):?><form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="courtesy_restore"><input type="hidden" name="return_page" value="courtesy"><input type="hidden" name="revision_id" value="<?=(int)$revision['id']?>"><button class="btn" type="submit">Copiar para rascunho</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$courtesyHistory):?><tr><td colspan="6">Nenhuma revisão publicada no histórico.</td></tr><?php endif;?></tbody></table></div>
  </article>
</section>

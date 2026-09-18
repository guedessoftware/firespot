<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/../app/partner_ads.php';
$csrf = csrf_token();

$titulo = 'Anúncios';
$pageId = 'anuncios';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

$msg = $_SESSION['flash_msg'] ?? '';
$err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

// Handle actions
$action = $_POST['action'] ?? '';
function sanitize_url(?string $url): ?string {
  return partner_ads_http_url($url,false);
}
function sanitize_ad_media(string $url): string {
  $url=trim($url);if($url==='')return '';
  if(preg_match('~^/assets/ads/[A-Za-z0-9_.-]+$~',$url))return $url;
  return (string)partner_ads_http_url($url,true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check($_POST['csrf'] ?? '')) {
  $_SESSION['flash_err'] = 'Sessão expirada ou token de segurança inválido. Recarregue a página.';
  header('Location: anuncios.php', true, 303);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !admin_has_capability('ads.global.manage')) {
  $_SESSION['flash_err'] = 'Seu papel administrativo não permite alterar campanhas globais.';
  header('Location: anuncios.php', true, 303);
  exit;
}
if ($action === 'add' && $_SERVER['REQUEST_METHOD']==='POST') {
  $uploaded = null;
  try {
    $title = trim($_POST['title'] ?? '');
    $mediaType = partner_ads_media_type((string)($_POST['media_type'] ?? 'image'));
    $fitMode = partner_ads_fit_mode((string)($_POST['fit_mode'] ?? 'contain'));
    $mediaUrl = sanitize_ad_media((string)($_POST['media_url'] ?? ''));
    $posterUrl = sanitize_ad_media((string)($_POST['poster_url'] ?? '')) ?: null;
    $uploaded = partner_ads_store_upload(0,$_FILES['media_file'] ?? []);
    if ($uploaded) { $mediaUrl = (string)$uploaded['url']; $mediaType = (string)$uploaded['type']; }
    $link_url = sanitize_url($_POST['link_url'] ?? '');
    $interestText=partner_ads_button_text($_POST['interest_button_text']??null,'Tenho interesse');$skipText=partner_ads_button_text($_POST['skip_button_text']??null,'Pular e conectar');$leadCapture=!empty($_POST['lead_capture_enabled'])?1:0;$offerMessage=substr(trim((string)($_POST['offer_message']??'')),0,500)?:null;
    $duration = max(5,min(180,(int)($_POST['duration_sec'] ?? 15)));
    $weight = max(1, min(100, (int)($_POST['weight'] ?? 1)));
    $active = isset($_POST['active']) ? 1 : 0;
    if ($title!=='' && $mediaUrl!=='') {
      if ($mediaType==='video' && !preg_match('~\.mp4(?:$|[?#])~i',$mediaUrl)) throw new InvalidArgumentException('Para vídeo remoto, informe uma URL de arquivo MP4.');
      $compatImage=$posterUrl ?: $mediaUrl;
      $st = $pdo->prepare("INSERT INTO custom_ads (title,media_type,media_url,poster_url,fit_mode,image_url,link_url,interest_button_text,skip_button_text,lead_capture_enabled,offer_message,duration_sec,weight,active,ad_kind) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'institutional')");
      $st->execute([$title,$mediaType,$mediaUrl,$posterUrl,$fitMode,$compatImage,$link_url,$interestText,$skipText,$leadCapture,$offerMessage,$duration,$weight,$active]);
      $_SESSION['flash_msg'] = 'Anúncio criado.';
    } else {
      throw new InvalidArgumentException('Informe o título e envie uma imagem/vídeo ou sua URL.');
    }
  } catch (Throwable $e) {
    if ($uploaded) partner_ads_delete_upload_if_unreferenced($pdo,(string)$uploaded['url']);
    $_SESSION['flash_err'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Não foi possível salvar o anúncio.';
  }
  header('Location: anuncios.php');
  exit;
}
if ($action === 'del' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['id'])) {
  try {
    $id = (int)$_POST['id'];
    $pdo->prepare('UPDATE custom_ads SET active=0,updated_at=NOW() WHERE id=? AND partner_id IS NULL')->execute([$id]);
    $_SESSION['flash_msg'] = 'Anúncio desativado; eventos e métricas foram preservados.';
  } catch (Throwable $e) {
    $_SESSION['flash_err'] = 'Não foi possível desativar o anúncio.';
  }
  header('Location: anuncios.php');
  exit;
}
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['id'])) {
  try {
    $id = (int)$_POST['id'];
    $pdo->prepare('UPDATE custom_ads SET active = 1 - active WHERE id=? AND partner_id IS NULL')->execute([$id]);
    $_SESSION['flash_msg'] = 'Status alterado.';
  } catch (Throwable $e) {
    $_SESSION['flash_err'] = 'Não foi possível alterar o status.';
  }
  header('Location: anuncios.php');
  exit;
}
if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['id'])) {
  $uploaded = null;
  try {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title'] ?? '');
    $currentSt=$pdo->prepare('SELECT * FROM custom_ads WHERE id=? AND partner_id IS NULL LIMIT 1');$currentSt->execute([$id]);$current=$currentSt->fetch();
    if(!$current)throw new RuntimeException('Anúncio não encontrado.');
    $mediaType=partner_ads_media_type((string)($_POST['media_type']??$current['media_type']??'image'));
    $fitMode=partner_ads_fit_mode((string)($_POST['fit_mode']??$current['fit_mode']??'contain'));
    $mediaUrl=sanitize_ad_media((string)($_POST['media_url']??'')) ?: partner_ads_media_url($current);
    $posterUrl=sanitize_ad_media((string)($_POST['poster_url']??'')) ?: null;
    $uploaded=partner_ads_store_upload(0,$_FILES['media_file']??[]);
    if($uploaded){$mediaUrl=(string)$uploaded['url'];$mediaType=(string)$uploaded['type'];}
    $link_url = sanitize_url($_POST['link_url'] ?? '');
    $interestText=partner_ads_button_text($_POST['interest_button_text']??($current['interest_button_text']??null),'Tenho interesse');$skipText=partner_ads_button_text($_POST['skip_button_text']??($current['skip_button_text']??null),'Pular e conectar');$leadCapture=!empty($_POST['lead_capture_enabled'])?1:0;$offerMessage=substr(trim((string)($_POST['offer_message']??'')),0,500)?:null;
    $duration = max(5,min(180,(int)($_POST['duration_sec'] ?? 15)));
    $weight = max(1, min(100, (int)($_POST['weight'] ?? 1)));
    $active = isset($_POST['active']) ? 1 : 0;
    if($title===''||$mediaUrl==='')throw new InvalidArgumentException('Informe o título e a mídia.');
    if($mediaType==='video'&&!preg_match('~\.mp4(?:$|[?#])~i',$mediaUrl))throw new InvalidArgumentException('Para vídeo remoto, informe uma URL de arquivo MP4.');
    $compatImage=$posterUrl?:$mediaUrl;
    $st = $pdo->prepare('UPDATE custom_ads SET title=?,media_type=?,media_url=?,poster_url=?,fit_mode=?,image_url=?,link_url=?,interest_button_text=?,skip_button_text=?,lead_capture_enabled=?,offer_message=?,duration_sec=?,weight=?,active=? WHERE id=? AND partner_id IS NULL');
    $st->execute([$title,$mediaType,$mediaUrl,$posterUrl,$fitMode,$compatImage,$link_url,$interestText,$skipText,$leadCapture,$offerMessage,$duration,$weight,$active,$id]);
    $oldMedia=partner_ads_media_url($current);if($oldMedia!==$mediaUrl)partner_ads_delete_upload_if_unreferenced($pdo,$oldMedia);
    $_SESSION['flash_msg'] = 'Anúncio atualizado.';
  } catch (Throwable $e) {
    if ($uploaded) partner_ads_delete_upload_if_unreferenced($pdo,(string)$uploaded['url']);
    $_SESSION['flash_err'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Não foi possível atualizar o anúncio.';
  }
  header('Location: anuncios.php');
  exit;
}

// Fetch all ads
$ads = $pdo->query('SELECT * FROM custom_ads WHERE partner_id IS NULL ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

ob_start();
?>
<?php $activeAds = count(array_filter($ads, static function ($ad) { return (int)$ad['active'] === 1; })); ?>
<section class="fs-workspace-overview" aria-label="Resumo das campanhas visuais">
  <div class="fs-workspace-overview__copy">
    <span>Campanhas visuais</span>
    <strong>Conteúdo exibido durante a jornada de conexão</strong>
    <small>Cadastre peças visuais aqui; mensagens segmentadas para clientes ficam na área de Promoções.</small>
  </div>
  <div class="fs-workspace-stats">
    <div><strong><?= count($ads) ?></strong><span>Total</span></div>
    <div><strong><?= $activeAds ?></strong><span>Ativos</span></div>
    <div><strong><?= count($ads) - $activeAds ?></strong><span>Inativos</span></div>
  </div>
  <nav class="fs-workspace-links" aria-label="Atalhos de campanhas">
    <a href="#novo-anuncio">Novo anúncio</a>
    <a href="#anuncios-cadastrados">Peças cadastradas</a>
    <a href="promocoes.php">Mensagens promocionais</a>
    <a href="campanhas.php?section=settings">Regras de exibição</a>
  </nav>
</section>
<div class="card" id="novo-anuncio">
  <?php if (!empty($msg)): ?>
    <div class="notice ad-notice ad-notice--success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($err)): ?>
    <div class="notice ad-notice ad-notice--error"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <h2>Novo anúncio</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-row"><label for="ad-add-title">Título</label><input id="ad-add-title" type="text" name="title" required></div>
    <div class="form-row"><label for="ad-add-media-type">Tipo de mídia</label><select id="ad-add-media-type" name="media_type"><option value="image">Imagem</option><option value="video">Vídeo MP4</option></select></div>
    <div class="form-row"><label for="ad-add-media-url">URL da mídia</label><input id="ad-add-media-url" type="text" name="media_url" placeholder="https://.../campanha.mp4 ou /assets/ads/..."></div>
    <div class="form-row"><label for="ad-add-media-file">Ou enviar mídia</label><input id="ad-add-media-file" type="file" name="media_file" accept="image/jpeg,image/png,image/webp,video/mp4"><small class="muted">Imagem até 5 MB ou vídeo MP4 até 30 MB.</small></div>
    <div class="form-row"><label for="ad-add-poster-url">URL da capa do vídeo (opcional)</label><input id="ad-add-poster-url" type="text" name="poster_url" placeholder="https://.../capa.jpg"></div>
    <div class="form-row"><label for="ad-add-fit-mode">Enquadramento</label><select id="ad-add-fit-mode" name="fit_mode"><option value="contain">Mostrar a mídia inteira</option><option value="cover">Preencher e recortar</option></select></div>
    <div class="form-row"><label for="ad-add-link-url">URL da oferta (opcional)</label><input id="ad-add-link-url" type="url" name="link_url" placeholder="https://minha-landing.com"><small class="muted">Só será aberta após a conexão, se o visitante demonstrar interesse.</small></div>
    <div class="form-row"><label for="ad-add-interest-button">Texto do botão de interesse</label><input id="ad-add-interest-button" name="interest_button_text" maxlength="60" value="Tenho interesse"></div>
    <div class="form-row"><label for="ad-add-skip-button">Texto do botão para seguir</label><input id="ad-add-skip-button" name="skip_button_text" maxlength="60" value="Pular e conectar"></div>
    <div class="form-row"><label><input type="checkbox" name="lead_capture_enabled" value="1"> Pedir nome e celular para enviar a oferta</label></div>
    <div class="form-row"><label for="ad-add-offer-message">Mensagem da oferta</label><textarea id="ad-add-offer-message" name="offer_message" maxlength="500" rows="3"></textarea></div>
    <div class="form-row"><label for="ad-add-duration">Tempo obrigatório (segundos)</label><input id="ad-add-duration" type="number" name="duration_sec" min="5" max="180" value="15"></div>
    <div class="form-row"><label for="ad-add-weight">Peso (sorteio)</label><input id="ad-add-weight" type="number" name="weight" min="1" max="100" value="1"></div>
    <div class="form-row"><label><input type="checkbox" name="active" value="1" checked> Ativo</label></div>
    <div class="form-row"><button class="btn primary" type="submit">Adicionar</button></div>
  </form>
  <?php $adsDir = __DIR__ . '/../assets/ads'; if (!is_dir($adsDir) || !is_writable($adsDir)): ?>
    <div class="notice ad-notice ad-notice--warning">
      Atenção: a pasta de uploads (<code><?= htmlspecialchars($adsDir) ?></code>) não tem permissão de escrita. Ajuste as permissões para permitir envio de mídia.
    </div>
  <?php endif; ?>
</div>

<div class="card" id="anuncios-cadastrados">
  <h2>Anúncios cadastrados</h2>
  <?php if (!$ads): ?>
    <p class="muted">Nenhum anúncio cadastrado.</p>
  <?php else: ?>
    <div class="ad-table-wrap">
      <table class="table">
        <thead><tr><th>ID</th><th>Título</th><th>Mídia</th><th>Duração</th><th>Peso</th><th>Ativo</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($ads as $ad): $adControlPrefix = 'ad-' . (int)$ad['id']; ?>
          <tr>
            <td><?= (int)$ad['id'] ?></td>
            <td><?= htmlspecialchars($ad['title']) ?></td>
            <td><?php if (partner_ads_media_type((string)($ad['media_type']??'image'))==='video'): ?><video class="ad-thumbnail" src="<?=htmlspecialchars(partner_ads_media_url($ad))?>" muted preload="metadata"></video><?php else: ?><img class="ad-thumbnail" src="<?=htmlspecialchars(partner_ads_media_url($ad))?>" alt=""><?php endif; ?></td>
            <td><?= (int)$ad['duration_sec'] ?>s</td>
            <td><?= (int)$ad['weight'] ?></td>
            <td><?= ((int)$ad['active']===1?'Sim':'Não') ?></td>
            <td>
              <details>
                <summary>Editar</summary>
                <form method="post" class="ad-edit-form" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id" value="<?= (int)$ad['id'] ?>">
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-title">Título</label><input id="<?= $adControlPrefix ?>-title" type="text" name="title" value="<?= htmlspecialchars($ad['title']) ?>" required></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-media-type">Tipo de mídia</label><select id="<?= $adControlPrefix ?>-media-type" name="media_type"><option value="image" <?=($ad['media_type']??'image')==='image'?'selected':''?>>Imagem</option><option value="video" <?=($ad['media_type']??'image')==='video'?'selected':''?>>Vídeo MP4</option></select></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-media-url">URL da mídia</label><input id="<?= $adControlPrefix ?>-media-url" type="text" name="media_url" value="<?=htmlspecialchars(partner_ads_media_url($ad))?>" required></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-media-file">Ou enviar nova mídia</label><input id="<?= $adControlPrefix ?>-media-file" type="file" name="media_file" accept="image/jpeg,image/png,image/webp,video/mp4"></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-poster-url">URL da capa</label><input id="<?= $adControlPrefix ?>-poster-url" type="text" name="poster_url" value="<?=htmlspecialchars((string)($ad['poster_url']??''))?>"></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-fit-mode">Enquadramento</label><select id="<?= $adControlPrefix ?>-fit-mode" name="fit_mode"><option value="contain" <?=($ad['fit_mode']??'contain')==='contain'?'selected':''?>>Mostrar inteira</option><option value="cover" <?=($ad['fit_mode']??'contain')==='cover'?'selected':''?>>Preencher e recortar</option></select></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-link-url">URL da oferta</label><input id="<?= $adControlPrefix ?>-link-url" type="url" name="link_url" value="<?= htmlspecialchars($ad['link_url'] ?? '') ?>"></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-interest-button">Texto do botão de interesse</label><input id="<?= $adControlPrefix ?>-interest-button" name="interest_button_text" maxlength="60" value="<?=htmlspecialchars($ad['interest_button_text']??'Tenho interesse')?>"></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-skip-button">Texto do botão para seguir</label><input id="<?= $adControlPrefix ?>-skip-button" name="skip_button_text" maxlength="60" value="<?=htmlspecialchars($ad['skip_button_text']??'Pular e conectar')?>"></div>
                  <div class="form-row"><label><input type="checkbox" name="lead_capture_enabled" value="1" <?=!empty($ad['lead_capture_enabled'])?'checked':''?>> Pedir nome e celular</label></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-offer-message">Mensagem da oferta</label><textarea id="<?= $adControlPrefix ?>-offer-message" name="offer_message" maxlength="500" rows="3"><?=htmlspecialchars($ad['offer_message']??'')?></textarea></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-duration">Tempo obrigatório (seg)</label><input id="<?= $adControlPrefix ?>-duration" type="number" name="duration_sec" min="5" max="180" value="<?= (int)$ad['duration_sec'] ?>"></div>
                  <div class="form-row"><label for="<?= $adControlPrefix ?>-weight">Peso</label><input id="<?= $adControlPrefix ?>-weight" type="number" name="weight" min="1" max="100" value="<?= (int)$ad['weight'] ?>"></div>
                  <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= ((int)$ad['active']===1?'checked':'') ?>> Ativo</label></div>
                  <div class="form-row"><button class="btn" type="submit">Salvar</button></div>
                </form>
              </details>
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$ad['id'] ?>">
                <button class="btn" type="submit">Ativar/Desativar</button>
              </form>
              <form method="post" class="inline-form" data-confirm="Remover anúncio?">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="del">
                <input type="hidden" name="id" value="<?= (int)$ad['id'] ?>">
                <button class="btn danger" type="submit">Remover</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
require __DIR__ . '/layout.php';

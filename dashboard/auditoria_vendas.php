<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';

$titulo = 'Auditoria de Vendas';
$pageId = 'aud_vendas';
ob_start();
?>
<div class="card">
  <h3>Auditoria — Entregas VIP</h3>
  <form id="audit-sales-filters" class="filters audit-sales-filters">
    <label>Idade mínima
      <input type="number" id="age" value="10" min="1" max="1440"> min
    </label>
    <label><input type="checkbox" id="verify"> Verificar status no Mercado Pago (máx 20)</label>
    <button class="theme-btn" id="btn-run" type="button">Executar</button>
  </form>

  <div id="out" class="table-responsive audit-sales-output">
    <table class="tabela">
      <thead><tr><th colspan="7">Pagas sem VIP aplicado</th></tr></thead>
      <tbody id="tb-paid"><tr><td>—</td></tr></tbody>
    </table>
    <br>
    <table class="tabela">
      <thead><tr><th colspan="8">Pendentes com payment_id (possível trava)</th></tr></thead>
      <tbody id="tb-pending"><tr><td>—</td></tr></tbody>
    </table>
  </div>
</div>

<?php
$conteudo = ob_get_clean();
include 'layout.php';

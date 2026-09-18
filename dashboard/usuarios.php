<?php
// ============================================================================
// /hotspot/dashboard/usuarios.php  —  Gestão de Clientes (CRUD) + VIP restante
// - Lista SEM duplicatas (sua API já filtra pela interseção radcheck ∩ clientes_info)
// - Filtros com busca instantânea (debounce), ordenação, paginação
// - Ações: Adicionar, Editar, Excluir (usa as APIs já criadas)
// - Mostra “VIP restante” (com barra de progresso), visitas e último visto
// - Mobile-first, consistente com dashboard.css e layout.php
// ============================================================================

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

// use o gerador oficial do seu projeto
$csrf = csrf_token();   // <-- em vez de random_bytes

$titulo = 'Clientes';
$pageId = 'usuarios';

ob_start();
?>

<!-- ======= Toolbar / Filtros ======= -->
<div class="card users-toolbar">
  <div class="card-header users-toolbar__header">
    <div class="users-toolbar__title">
      <h3>Gerenciar clientes</h3>
      <small class="muted" id="stats-inline" aria-live="polite"></small>
    </div>
    <div class="users-toolbar__actions">
      <button id="btn-add" class="theme-btn" type="button">Novo cliente</button>
      <button id="btn-reload" class="theme-btn" type="button" title="Recarregar">⟳</button>
      <button id="btn-export" class="theme-btn btn-export" type="button" title="Exportar CSV">⬇️ Exportar</button>
    </div>
  </div>

  <form id="filters" class="content-grid users-filters">
    <div>
      <label class="muted" for="f-q">Busca</label>
      <input id="f-q" type="text" placeholder="CPF/usuário, nome, telefone" />
    </div>
    <div>
      <label class="muted" for="f-group">Grupo</label>
      <input id="f-group" list="dl-groups" placeholder="Ex.: VIP_24H" />
      <datalist id="dl-groups"></datalist>
    </div>
    <div>
      <label class="muted" for="f-status">Status</label>
      <select id="f-status">
        <option value="">Todos</option>
        <option value="online">Online</option>
        <option value="offline">Offline</option>
      </select>
    </div>
    <div>
      <label class="muted" for="f-order">Ordenar</label>
      <select id="f-order">
        <option value="last_seen">Último visto</option>
        <option value="username">Usuário</option>
        <option value="nome">Nome</option>
        <option value="visits30">Visitas (30d)</option>
      </select>
    </div>
    <div>
      <label class="muted" for="f-dir">Direção</label>
      <select id="f-dir">
        <option value="desc">Desc</option>
        <option value="asc">Asc</option>
      </select>
    </div>
    <div>
      <label class="muted" for="f-per">Por página</label>
      <select id="f-per">
        <option>25</option>
        <option>50</option>
        <option>75</option>
        <option>100</option>
      </select>
    </div>
  </form>
</div>

<!-- ======= Tabela ======= -->
<div class="card">
  <div class="table-responsive">
    <table class="tabela" id="grid">
      <thead>
        <tr>
          <th class="users-status-column">Status</th>
          <th>Usuário (CPF)</th>
          <th>Nome</th>
          <th>Grupo(s)</th>
          <th class="users-vip-column">VIP restante</th>
          <th>Visitas (30d)</th>
          <th>Último visto</th>
          <th class="users-actions-column">Ações</th>
        </tr>
      </thead>
      <tbody id="grid-body">
        <tr>
          <td colspan="8" class="muted">Carregando…</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="users-pagination">
    <small class="muted" id="pg-summary">—</small>
    <div class="users-pagination__controls">
      <button type="button" id="pg-prev" class="theme-btn">◀</button>
      <span id="pg-info" class="muted" data-page="1" data-pages="1">Página 1</span>
      <button type="button" id="pg-next" class="theme-btn">▶</button>
    </div>
  </div>
</div>

<!-- ======= Modal: Criar/Editar ======= -->
<div id="modal-user" class="modal-backdrop" aria-hidden="true">
  <div class="card users-modal-card">
    <div class="card-header users-modal-header">
      <h3 id="mu-title">Novo cliente</h3>
      <button id="mu-close" class="theme-btn" type="button" title="Fechar">✕</button>
    </div>

    <form id="mu-form" class="users-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" id="mu-mode" value="create"> <!-- create|update -->

      <div class="content-grid users-grid-name">
        <div>
          <label class="muted" for="mu-username">CPF (username RADIUS)</label>
          <input id="mu-username" placeholder="Somente números" maxlength="11" autocomplete="username" />
        </div>
        <div>
          <label class="muted" for="mu-nome">Nome</label>
          <input id="mu-nome" placeholder="Nome completo" autocomplete="name" />
        </div>
      </div>

      <div class="content-grid users-grid-three">
        <div>
          <label class="muted" for="mu-telefone">Telefone</label>
          <input id="mu-telefone" placeholder="DDD+Número" autocomplete="tel" />
        </div>
        <div>
          <label class="muted" for="mu-email">E-mail</label>
          <input id="mu-email" placeholder="cliente@exemplo.com" autocomplete="email" />
        </div>
        <div>
          <label class="muted" for="mu-nascimento">Nascimento</label>
          <input id="mu-nascimento" type="date" />
        </div>
      </div>

      <div class="content-grid users-grid-three">
        <div>
          <label class="muted" for="mu-sexo">Sexo</label>
          <select id="mu-sexo">
            <option value="">—</option>
            <option value="M">Masculino</option>
            <option value="F">Feminino</option>
            <option value="O">Outros</option>
          </select>
        </div>
        <div>
          <label class="muted" for="mu-senha">Senha (RADIUS)</label>
          <input id="mu-senha" type="password" placeholder="mín. 6 caracteres" autocomplete="new-password" />
          <small class="muted">Deixe vazio para manter a atual (ao editar)</small>
        </div>
        <div>
          <label class="muted" for="mu-group">Plano/Grupo (radusergroup)</label>
          <input list="dl-groups" id="mu-group" placeholder="Ex.: VIP_24H" />
          <small class="muted">Opcional — se vazio, mantém grupos atuais</small>
        </div>
      </div>

      <div class="content-grid users-grid-two">
        <div>
          <label class="muted" for="mu-maxmin">Tempo total (Max-All-Session, em minutos)</label>
          <input id="mu-maxmin" type="number" min="0" step="1" placeholder="ex.: 1440 para 24h" />
          <small class="muted">Se vazio, não altera</small>
        </div>
        <div class="users-check-cell">
          <label class="users-check-label">
            <input id="mu-exclusive" type="checkbox"> Exclusivo (limpar outros grupos)
          </label>
        </div>
      </div>

      <div class="users-form-actions">
        <button type="button" id="mu-cancel" class="theme-btn">Cancelar</button>
        <button type="submit" id="mu-apply" class="theme-btn">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- ======= Modal: Histórico ======= -->
<div id="modal-history" class="modal-backdrop" aria-hidden="true">
  <div class="card users-modal-card users-modal-card--history">
    <div class="card-header users-modal-header">
      <h3 id="mh-title">Histórico do cliente</h3>
      <button id="mh-close" class="theme-btn" type="button" title="Fechar">✕</button>
    </div>
    <div class="history-content" id="mh-body">
      <div id="mh-summary" class="history-summary muted">Selecione um usuário para carregar os dados.</div>
      <section class="history-section">
        <h4>Compras de crédito</h4>
        <div id="mh-orders" class="history-table muted">—</div>
      </section>
      <section class="history-section">
        <h4>Acessos (RADIUS)</h4>
        <div id="mh-sessions" class="history-table muted">—</div>
      </section>
    </div>
  </div>
</div>

<?php
$conteudo = ob_get_clean();
include 'layout.php';

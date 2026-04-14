/**
 * assets/js/app.js
 * Frontend controller — handles ticket list, detail, comments, admin UI.
 * Requires: APP_URL, ROLE, PAGE, (TICKET_ID on detail page) — injected by PHP.
 */

// ── Centralised fetch (cache: no-store) ───────────────────────────────────────
async function api(action, { method = 'GET', body = null, formData = null } = {}) {
  const url = `${APP_URL}/inc/api.php?action=${action}`;
  const opts = {
    method,
    cache: 'no-store',
    credentials: 'same-origin',
  };
  if (formData) {
    opts.body = formData;
  } else if (body) {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  if (res.status === 401) { window.location.href = `${APP_URL}/index.php`; return null; }
  return res.json();
}

async function apiGet(action, params = {}) {
  const qs = new URLSearchParams(params).toString();
  const url = `${APP_URL}/inc/api.php?action=${action}${qs ? '&' + qs : ''}`;
  const res = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
  if (res.status === 401) { window.location.href = `${APP_URL}/index.php`; return null; }
  return res.json();
}

// ── Utilities ─────────────────────────────────────────────────────────────────
const statusLabels = {
  nuovo: 'Nuovo', in_lavorazione: 'In lavorazione',
  risolto: 'Risolto', chiuso: 'Chiuso',
};
const priorityLabels = { bassa: 'Bassa', media: 'Media', alta: 'Alta', urgente: 'Urgente' };

function statusBadge(s) {
  return `<span class="badge badge-status-${s}">${statusLabels[s] || s}</span>`;
}
function priorityBadge(p) {
  const cls = { bassa:'success', media:'warning', alta:'warning text-dark', urgente:'danger' };
  return `<span class="badge bg-${cls[p] || 'secondary'}">${priorityLabels[p] || p}</span>`;
}
function fmtDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('it-IT', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}
function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toast(msg, type = 'success') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'position-fixed bottom-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
  }
  const id = 'toast-' + Date.now();
  container.insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-bg-${type} border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">${esc(msg)}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`);
  setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (!LOGGED_IN) return; // login page — nothing to do

  if (PAGE === 'index') {
    initIndex();
  } else if (PAGE === 'detail') {
    initDetail(TICKET_ID);
  }
});

// ── INDEX PAGE ────────────────────────────────────────────────────────────────

let currentFilters = {};
let currentPage    = 1;

function initIndex() {
  renderTicketTab();
  if (ROLE === 'admin') {
    setupAdminTabs();
    loadNotifications();
  } else if (ROLE === 'operator') {
    loadNotifications();
  }

  // Poll notifications every 60s
  if (ROLE === 'operator' || ROLE === 'admin') {
    setInterval(loadNotifications, 60000);
  }
}

function setupAdminTabs() {
  document.querySelectorAll('[data-tab]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      document.querySelectorAll('[data-tab]').forEach(x => x.classList.remove('active'));
      a.classList.add('active');
      ['tickets','users','report','logs'].forEach(t => {
        const el = document.getElementById('tab-' + t);
        if (el) el.classList.toggle('d-none', t !== a.dataset.tab);
      });
      if (a.dataset.tab === 'users')  loadUsers();
      if (a.dataset.tab === 'report') loadReport();
      if (a.dataset.tab === 'logs')   loadLogs();
    });
  });
}

// ── TICKET LIST ───────────────────────────────────────────────────────────────

function renderTicketTab() {
  const container = document.getElementById('tab-tickets');
  if (!container) return;

  const isStaff = (ROLE === 'operator' || ROLE === 'admin');

  container.innerHTML = `
    ${isStaff ? `
    <div class="filters-bar">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><input id="f-search"   class="form-control form-control-sm" placeholder="🔍 Cerca…"></div>
        <div class="col-md-2">
          <select id="f-status" class="form-select form-select-sm">
            <option value="">Tutti gli stati</option>
            <option value="nuovo">Nuovo</option>
            <option value="in_lavorazione">In lavorazione</option>
            <option value="risolto">Risolto</option>
            <option value="chiuso">Chiuso</option>
          </select>
        </div>
        <div class="col-md-2">
          <select id="f-priority" class="form-select form-select-sm">
            <option value="">Tutte le priorità</option>
            <option value="bassa">Bassa</option>
            <option value="media">Media</option>
            <option value="alta">Alta</option>
            <option value="urgente">Urgente</option>
          </select>
        </div>
        <div class="col-md-2">
          <input id="f-category" class="form-control form-control-sm" placeholder="Categoria">
        </div>
        <div class="col-md-1">
          <button class="btn btn-sm btn-primary w-100" id="btn-filter">Filtra</button>
        </div>
        <div class="col-md-1">
          <button class="btn btn-sm btn-outline-secondary w-100" id="btn-reset">Reset</button>
        </div>
      </div>
    </div>` : ''}

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">${ROLE === 'user' ? 'I miei ticket' : 'Tutti i ticket'}</h5>
      <button class="btn btn-success btn-sm" id="btn-new-ticket">+ Nuovo Ticket</button>
    </div>

    <div id="ticket-list-wrap"></div>
    <div id="pagination-wrap" class="mt-3"></div>

    <!-- New Ticket Modal -->
    <div class="modal fade" id="newTicketModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Nuovo Ticket</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <form id="form-new-ticket">
              <div class="mb-3">
                <label class="form-label">Titolo *</label>
                <input type="text" name="title" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Descrizione *</label>
                <textarea name="description" class="form-control" rows="4" required></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Categoria *</label>
                <select name="category" class="form-select" required>
                  <option value="">-- seleziona --</option>
                  <option>Accesso</option><option>Bug</option>
                  <option>Richiesta</option><option>Hardware</option>
                  <option>Software</option><option>Altro</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Priorità *</label>
                <select name="priority" class="form-select" required>
                  <option value="">-- seleziona --</option>
                  <option value="bassa">Bassa</option>
                  <option value="media">Media</option>
                  <option value="alta">Alta</option>
                  <option value="urgente">Urgente</option>
                </select>
              </div>
              <div id="new-ticket-error" class="alert alert-danger d-none"></div>
              <button type="submit" class="btn btn-primary w-100">Invia Ticket</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  `;

  // Bind events
  document.getElementById('btn-new-ticket').addEventListener('click', () => {
    new bootstrap.Modal(document.getElementById('newTicketModal')).show();
  });
  document.getElementById('form-new-ticket').addEventListener('submit', submitNewTicket);

  if (isStaff) {
    document.getElementById('btn-filter').addEventListener('click', applyFilters);
    document.getElementById('btn-reset').addEventListener('click', () => {
      currentFilters = {}; currentPage = 1;
      ['f-search','f-status','f-priority','f-category'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
      });
      loadTicketList();
    });
    ['f-search'].forEach(id => {
      document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') applyFilters(); });
    });
  }

  loadTicketList();
}

function applyFilters() {
  currentFilters = {
    search:   document.getElementById('f-search')?.value   || '',
    status:   document.getElementById('f-status')?.value   || '',
    priority: document.getElementById('f-priority')?.value || '',
    category: document.getElementById('f-category')?.value || '',
  };
  currentPage = 1;
  loadTicketList();
}

async function loadTicketList() {
  const wrap = document.getElementById('ticket-list-wrap');
  if (!wrap) return;
  wrap.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

  const params = { page: currentPage, per_page: 15, ...currentFilters };
  const data = await apiGet('tickets', params);
  if (!data) return;

  if (!data.tickets || data.tickets.length === 0) {
    wrap.innerHTML = `<div class="empty-state"><div class="fs-1">📭</div><p>Nessun ticket trovato.</p></div>`;
    document.getElementById('pagination-wrap').innerHTML = '';
    return;
  }

  wrap.innerHTML = data.tickets.map(t => `
    <div class="card ticket-card mb-2 priority-${t.priority}"
         onclick="goToTicket('${esc(t.id)}')">
      <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="fw-semibold">${esc(t.title)}</span>
            <span class="text-muted small ms-2">#${esc(t.id)}</span>
          </div>
          <div class="d-flex gap-1 flex-shrink-0 ms-2">
            ${statusBadge(t.status)} ${priorityBadge(t.priority)}
          </div>
        </div>
        <div class="text-muted small mt-1">
          ${ROLE !== 'user' ? `<span>👤 ${esc(t.created_by_name)}</span> · ` : ''}
          📂 ${esc(t.category)} ·
          🕐 ${fmtDate(t.created_at)}
          ${t.assigned_to ? ` · 🔧 ${esc(t.assigned_to_name)}` : ''}
        </div>
      </div>
    </div>`).join('');

  // Pagination
  const pWrap = document.getElementById('pagination-wrap');
  if (data.total_pages > 1) {
    const pages = [];
    for (let i = 1; i <= data.total_pages; i++) {
      pages.push(`<li class="page-item ${i === data.page ? 'active' : ''}">
        <button class="page-link" onclick="gotoPage(${i})">${i}</button></li>`);
    }
    pWrap.innerHTML = `<nav><ul class="pagination pagination-sm justify-content-center">${pages.join('')}</ul></nav>
      <p class="text-center text-muted small">${data.total} ticket totali</p>`;
  } else {
    pWrap.innerHTML = `<p class="text-center text-muted small">${data.total} ticket totali</p>`;
  }
}

function gotoPage(p) { currentPage = p; loadTicketList(); }
function goToTicket(id) { window.location.href = `${APP_URL}/pages/ticket_details.php?id=${id}`; }

async function submitNewTicket(e) {
  e.preventDefault();
  const form = e.target;
  const errEl = document.getElementById('new-ticket-error');
  errEl.classList.add('d-none');

  const payload = {
    title:       form.title.value,
    description: form.description.value,
    category:    form.category.value,
    priority:    form.priority.value,
  };

  const data = await api('create_ticket', { method: 'POST', body: payload });
  if (!data) return;
  if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }

  bootstrap.Modal.getInstance(document.getElementById('newTicketModal')).hide();
  form.reset();
  toast('Ticket creato con successo!');
  loadTicketList();
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────

async function loadNotifications() {
  const data = await apiGet('notifications');
  if (!data) return;
  const n = data.notifications?.length || 0;
  const badge = document.getElementById('notif-badge');
  if (badge) {
    badge.textContent = `${n} aggiornamenti`;
    badge.classList.toggle('d-none', n === 0);
  }
  const list = document.getElementById('notif-list');
  if (list) {
    if (n === 0) { list.innerHTML = '<p class="text-muted">Nessuna notifica recente.</p>'; return; }
    list.innerHTML = data.notifications.map(nt => `
      <div class="border-bottom pb-2 mb-2">
        <a href="${APP_URL}/pages/ticket_details.php?id=${esc(nt.ticket_id)}" class="text-decoration-none">
          <strong>${esc(nt.message)}</strong>
        </a>
        <br><small class="text-muted">${fmtDate(nt.at)}</small>
      </div>`).join('');
  }
}

// ── TICKET DETAIL PAGE ────────────────────────────────────────────────────────

async function initDetail(ticketId) {
  const container = document.getElementById('ticket-container');
  if (!container) return;

  const [tData, cData, opsData] = await Promise.all([
    apiGet('ticket',     { id: ticketId }),
    apiGet('comments',   { ticket_id: ticketId }),
    (ROLE === 'operator' || ROLE === 'admin') ? apiGet('operators') : Promise.resolve(null),
  ]);

  if (!tData || tData.error) {
    container.innerHTML = `<div class="alert alert-danger">${esc(tData?.error || 'Errore caricamento ticket')}</div>`;
    return;
  }

  renderDetail(container, tData.ticket, cData?.comments || [], opsData?.operators || []);
}

function renderDetail(container, ticket, comments, operators) {
  const canEdit   = (ROLE === 'operator' || ROLE === 'admin');
  const isClosed  = ticket.status === 'chiuso';
  const canComment = !isClosed || canEdit;

  container.innerHTML = `
    <div class="mb-3">
      <a href="${APP_URL}/index.php" class="btn btn-sm btn-outline-secondary">← Torna alla lista</a>
    </div>

    <!-- Header -->
    <div class="detail-header mb-4">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <h4 class="mb-1">${esc(ticket.title)}</h4>
          <span class="text-muted small">#${esc(ticket.id)} · Creato da <strong>${esc(ticket.created_by_name)}</strong> il ${fmtDate(ticket.created_at)}</span>
        </div>
        <div class="d-flex gap-1">${statusBadge(ticket.status)} ${priorityBadge(ticket.priority)}</div>
      </div>

      <hr>
      <p class="mb-2 white-space-pre-wrap">${esc(ticket.description)}</p>
      <div class="text-muted small">
        📂 ${esc(ticket.category)}
        ${ticket.assigned_to ? ` · 🔧 Assegnato a: <strong>${esc(ticket.assigned_to_name)}</strong>` : ' · 🔧 Non assegnato'}
        · ✏️ Aggiornato: ${fmtDate(ticket.updated_at)}
      </div>

      ${ticket.uploads?.length ? `
        <div class="mt-2">
          <small class="text-muted">📎 Allegati ticket:</small>
          <div class="upload-preview-wrap mt-1">
            ${ticket.uploads.map(u => `<img src="${APP_URL}/uploads/${esc(u)}" class="comment-thumb" onclick="window.open(this.src)">`).join('')}
          </div>
        </div>` : ''}
    </div>

    <!-- Operator actions -->
    ${canEdit ? `
    <div class="card mb-4">
      <div class="card-header fw-semibold">⚙️ Azioni Operatore</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label small">Stato</label>
            <select id="upd-status" class="form-select form-select-sm">
              ${Object.entries(statusLabels).map(([v,l]) =>
                `<option value="${v}" ${ticket.status===v?'selected':''}>${l}</option>`).join('')}
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Priorità</label>
            <select id="upd-priority" class="form-select form-select-sm">
              ${Object.entries(priorityLabels).map(([v,l]) =>
                `<option value="${v}" ${ticket.priority===v?'selected':''}>${l}</option>`).join('')}
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small">Assegna a</label>
            <select id="upd-assigned" class="form-select form-select-sm">
              <option value="">— Nessuno —</option>
              ${operators.map(o => `<option value="${esc(o.id)}" ${ticket.assigned_to===o.id?'selected':''}>${esc(o.name)}</option>`).join('')}
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary btn-sm w-100" id="btn-update-ticket">Aggiorna</button>
          </div>
        </div>
      </div>
    </div>` : ''}

    <!-- History -->
    <div class="card mb-4">
      <div class="card-header fw-semibold">📋 Storico</div>
      <div class="card-body" id="history-wrap">
        ${(ticket.history || []).map(h => `
          <div class="history-item">
            <strong>${esc(h.by_name || h.by)}</strong>: ${esc(h.note)}
            <span class="text-muted ms-2 small">${fmtDate(h.at)}</span>
          </div>`).join('')}
      </div>
    </div>

    <!-- Comments -->
    <div class="card mb-4">
      <div class="card-header fw-semibold">💬 Commenti</div>
      <div class="card-body" id="comments-wrap">
        ${renderCommentsList(comments)}
      </div>
    </div>

    <!-- Add comment -->
    ${canComment ? `
    <div class="card mb-4">
      <div class="card-header fw-semibold">✏️ Aggiungi commento</div>
      <div class="card-body">
        <form id="form-comment" enctype="multipart/form-data">
          <input type="hidden" name="ticket_id" value="${esc(ticket.id)}">
          <div class="mb-3">
            <textarea name="text" class="form-control" rows="3" placeholder="Scrivi un commento…" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label small">📎 Allega immagini (jpg, png, gif, webp — max 5 MB cad.)</label>
            <input type="file" name="uploads[]" class="form-control form-control-sm" multiple accept="image/*" id="file-input">
            <div class="upload-preview-wrap" id="file-preview"></div>
          </div>
          <div id="comment-error" class="alert alert-danger d-none"></div>
          <button type="submit" class="btn btn-primary btn-sm">Invia commento</button>
        </form>
      </div>
    </div>` : `
    <div class="alert alert-secondary">🔒 Ticket chiuso — non è possibile aggiungere nuovi commenti.</div>`}
  `;

  // Bind operator update
  if (canEdit) {
    document.getElementById('btn-update-ticket').addEventListener('click', () => updateTicket(ticket.id));
  }

  // Bind comment form
  const commentForm = document.getElementById('form-comment');
  if (commentForm) {
    commentForm.addEventListener('submit', submitComment);
    document.getElementById('file-input')?.addEventListener('change', previewFiles);
  }
}

function renderCommentsList(comments) {
  if (!comments.length) return '<p class="text-muted small">Nessun commento ancora.</p>';
  return comments.map(c => `
    <div class="comment-bubble ${c.user_role === 'user' ? 'from-user' : 'from-op'}">
      <div class="d-flex justify-content-between mb-1">
        <span class="fw-semibold small">${esc(c.user_name)}</span>
        <span class="text-muted small">${fmtDate(c.created_at)}</span>
      </div>
      <p class="mb-1">${esc(c.text)}</p>
      ${c.uploads?.length ? `<div class="upload-preview-wrap">${c.uploads.map(u =>
        `<img src="${APP_URL}/uploads/${esc(u)}" class="comment-thumb" onclick="window.open(this.src)">`
      ).join('')}</div>` : ''}
    </div>`).join('');
}

async function updateTicket(ticketId) {
  const status   = document.getElementById('upd-status')?.value;
  const priority = document.getElementById('upd-priority')?.value;
  const assigned = document.getElementById('upd-assigned')?.value;

  const data = await api('update_ticket', {
    method: 'POST',
    body: { id: ticketId, status, priority, assigned_to: assigned || null },
  });
  if (!data) return;
  if (data.error) { toast(data.error, 'danger'); return; }
  toast('Ticket aggiornato!');
  setTimeout(() => location.reload(), 800);
}

async function submitComment(e) {
  e.preventDefault();
  const form   = e.target;
  const errEl  = document.getElementById('comment-error');
  errEl.classList.add('d-none');

  const fd = new FormData(form);
  const data = await api('add_comment', { method: 'POST', formData: fd });
  if (!data) return;
  if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }

  toast('Commento aggiunto!');
  form.reset();
  document.getElementById('file-preview').innerHTML = '';

  // Append new comment without full reload
  const wrap = document.getElementById('comments-wrap');
  const c    = data.comment;
  if (wrap) {
    const placeholder = wrap.querySelector('p.text-muted');
    if (placeholder) placeholder.remove();
    wrap.insertAdjacentHTML('beforeend', `
      <div class="comment-bubble ${c.user_role === 'user' ? 'from-user' : 'from-op'}">
        <div class="d-flex justify-content-between mb-1">
          <span class="fw-semibold small">${esc(c.user_name)}</span>
          <span class="text-muted small">${fmtDate(c.created_at)}</span>
        </div>
        <p class="mb-1">${esc(c.text)}</p>
        ${c.uploads?.length ? `<div class="upload-preview-wrap">${c.uploads.map(u =>
          `<img src="${APP_URL}/uploads/${esc(u)}" class="comment-thumb" onclick="window.open(this.src)">`
        ).join('')}</div>` : ''}
      </div>`);
    wrap.scrollTop = wrap.scrollHeight;
  }
}

function previewFiles(e) {
  const preview = document.getElementById('file-preview');
  preview.innerHTML = '';
  [...e.target.files].forEach(f => {
    if (!f.type.startsWith('image/')) return;
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    img.onload = () => URL.revokeObjectURL(img.src);
    preview.appendChild(img);
  });
}

// ── ADMIN: USERS ──────────────────────────────────────────────────────────────

async function loadUsers() {
  const wrap = document.getElementById('tab-users');
  if (!wrap) return;
  wrap.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

  const data = await apiGet('users');
  if (!data) return;

  wrap.innerHTML = `
    <div id="users-table-wrap">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Gestione Utenti</h5>
        <button class="btn btn-success btn-sm" id="btn-add-user">+ Nuovo Utente</button>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr><th>Nome</th><th>Username</th><th>Email</th><th>Ruolo</th><th>Attivo</th><th>Creato</th><th></th></tr>
          </thead>
          <tbody id="users-tbody">
            ${data.users.map(u => `
              <tr data-uid="${esc(u.id)}">
                <td>${esc(u.name)}</td>
                <td><code>${esc(u.username)}</code></td>
                <td>${esc(u.email)}</td>
                <td><span class="badge bg-secondary">${esc(u.role)}</span></td>
                <td>${u.active ? '✅' : '❌'}</td>
                <td class="small text-muted">${fmtDate(u.created_at)}</td>
                <td>
                  <button class="btn btn-xs btn-outline-primary btn-sm btn-edit-user" data-user='${JSON.stringify(u)}'>✏️</button>
                  <button class="btn btn-xs btn-outline-danger  btn-sm btn-del-user"  data-uid="${esc(u.id)}" data-name="${esc(u.name)}">🗑️</button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="userModalTitle">Nuovo Utente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="form-user">
              <input type="hidden" id="user-id">
              <div class="mb-3"><label class="form-label">Nome *</label><input type="text" id="u-name" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">Username *</label><input type="text" id="u-username" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">Email *</label><input type="email" id="u-email" class="form-control" required></div>
              <div class="mb-3">
                <label class="form-label">Ruolo *</label>
                <select id="u-role" class="form-select">
                  <option value="user">Utente</option>
                  <option value="operator">Operatore</option>
                  <option value="admin">Amministratore</option>
                </select>
              </div>
              <div class="mb-3"><label class="form-label">Password <span id="pwd-hint">(obbligatoria)</span></label>
                <input type="text" id="u-password" class="form-control">
              </div>
              <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="u-active" checked>
                <label class="form-check-label" for="u-active">Attivo</label>
              </div>
              <div id="user-error" class="alert alert-danger d-none"></div>
              <button type="submit" class="btn btn-primary w-100">Salva</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  `;

  document.getElementById('btn-add-user').addEventListener('click', () => openUserModal(null));
  document.querySelectorAll('.btn-edit-user').forEach(btn => {
    btn.addEventListener('click', () => openUserModal(JSON.parse(btn.dataset.user)));
  });
  document.querySelectorAll('.btn-del-user').forEach(btn => {
    btn.addEventListener('click', () => deleteUser(btn.dataset.uid, btn.dataset.name));
  });
  document.getElementById('form-user').addEventListener('submit', saveUser);
}

function openUserModal(user) {
  const modal = new bootstrap.Modal(document.getElementById('userModal'));
  document.getElementById('userModalTitle').textContent = user ? 'Modifica Utente' : 'Nuovo Utente';
  document.getElementById('user-id').value    = user?.id       || '';
  document.getElementById('u-name').value     = user?.name     || '';
  document.getElementById('u-username').value = user?.username || '';
  document.getElementById('u-email').value    = user?.email    || '';
  document.getElementById('u-role').value     = user?.role     || 'user';
  document.getElementById('u-password').value = '';
  document.getElementById('u-active').checked = user ? (user.active !== false) : true;
  document.getElementById('pwd-hint').textContent = user ? '(lascia vuoto per non cambiare)' : '(obbligatoria)';
  document.getElementById('user-error').classList.add('d-none');
  document.getElementById('u-username').disabled = !!user;
  modal.show();
}

async function saveUser(e) {
  e.preventDefault();
  const errEl = document.getElementById('user-error');
  errEl.classList.add('d-none');
  const id = document.getElementById('user-id').value;

  const payload = {
    id:       id || undefined,
    name:     document.getElementById('u-name').value,
    username: document.getElementById('u-username').value,
    email:    document.getElementById('u-email').value,
    role:     document.getElementById('u-role').value,
    password: document.getElementById('u-password').value,
    active:   document.getElementById('u-active').checked,
  };

  const action = id ? 'update_user' : 'create_user';
  const data   = await api(action, { method: 'POST', body: payload });
  if (!data) return;
  if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }

  bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
  toast(id ? 'Utente aggiornato!' : 'Utente creato!');
  loadUsers();
}

async function deleteUser(id, name) {
  if (!confirm(`Eliminare l'utente "${name}"?`)) return;
  const data = await api('delete_user', { method: 'POST', body: { id } });
  if (!data) return;
  if (data.error) { toast(data.error, 'danger'); return; }
  toast('Utente eliminato.');
  loadUsers();
}

// ── ADMIN: REPORT ─────────────────────────────────────────────────────────────

async function loadReport() {
  const wrap = document.getElementById('tab-report');
  if (!wrap) return;
  wrap.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

  const data = await apiGet('report');
  if (!data) return;

  const byStatus   = Object.entries(data.by_status   || {});
  const byPriority = Object.entries(data.by_priority  || {});
  const byCategory = Object.entries(data.by_category  || {});

  wrap.innerHTML = `
    <h5 class="mb-3">📊 Report</h5>
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3"><div class="report-card"><div class="display-5 text-primary">${data.total_tickets}</div><div class="text-muted small">Ticket totali</div></div></div>
      <div class="col-6 col-md-3"><div class="report-card"><div class="display-5 text-success">${data.total_users}</div><div class="text-muted small">Utenti</div></div></div>
      <div class="col-6 col-md-3"><div class="report-card"><div class="display-5 text-info">${data.total_comments}</div><div class="text-muted small">Commenti</div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="card"><div class="card-header">Per stato</div><div class="card-body">
          ${byStatus.map(([k,v]) => `<div class="d-flex justify-content-between">${statusBadge(k)}<strong>${v}</strong></div>`).join('')}
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card"><div class="card-header">Per priorità</div><div class="card-body">
          ${byPriority.map(([k,v]) => `<div class="d-flex justify-content-between">${priorityBadge(k)}<strong>${v}</strong></div>`).join('')}
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card"><div class="card-header">Per categoria</div><div class="card-body">
          ${byCategory.map(([k,v]) => `<div class="d-flex justify-content-between"><span>${esc(k)}</span><strong>${v}</strong></div>`).join('')}
        </div></div>
      </div>
    </div>`;
}

// ── ADMIN: LOGS ───────────────────────────────────────────────────────────────

let logsPage = 1;

async function loadLogs(page = 1) {
  logsPage = page;
  const wrap = document.getElementById('tab-logs');
  if (!wrap) return;
  wrap.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

  const data = await apiGet('logs', { page });
  if (!data) return;

  wrap.innerHTML = `
    <h5 class="mb-3">📋 Log Attività</h5>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead class="table-light">
          <tr><th>Azione</th><th>Utente</th><th>Nota</th><th>Data/Ora</th></tr>
        </thead>
        <tbody>
          ${(data.logs || []).map(l => `
            <tr class="log-row">
              <td><code>${esc(l.action)}</code></td>
              <td>${esc(l.user_name)}</td>
              <td>${esc(l.note)}</td>
              <td class="text-muted">${fmtDate(l.at)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <p class="text-muted small text-center">${data.total} voci totali · pagina ${data.page}</p>
    ${data.total > 50 ? `<div class="text-center">
      <button class="btn btn-sm btn-outline-secondary me-1" ${page <= 1 ? 'disabled' : ''} onclick="loadLogs(${page - 1})">← Prec</button>
      <button class="btn btn-sm btn-outline-secondary"      ${data.logs?.length < 50 ? 'disabled' : ''} onclick="loadLogs(${page + 1})">Succ →</button>
    </div>` : ''}
  `;
}

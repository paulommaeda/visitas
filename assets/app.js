/* Visitas — local assets, no build step or external scripts. */
(() => {
  'use strict';
  const V = window.V = { actions: {}, data: null, board: null, cards: [], screen: 'board', filters: { q: '', assigned: '', due: '', archived: false }, modalDirty: false, pendingMoves: new Map(), dragId: 0 };
  const config = window.VISITAS_CONFIG;
  V.$ = (s, root = document) => root?.querySelector(s) || null;
  V.$$ = (s, root = document) => root ? [...root.querySelectorAll(s)] : [];
  V.esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  V.e = V.esc;
  V.uid = () => window.crypto?.randomUUID?.() || ('v' + Date.now().toString(36) + Math.random().toString(36).slice(2));
  V.cap = cap => !!V.data?.capabilities[cap];
  V.staff = id => V.data?.staff.find(u => +u.id === +id);
  V.deadline = c => c.step_due_at && (!c.due_at || c.step_due_at < c.due_at) ? c.step_due_at : c.due_at;
  V.initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(n => n[0]).join('').toUpperCase();
  V.avatar = (name, small = false) => `<span class="v-avatar ${small ? 'v-avatar-sm' : ''}" title="${V.e(name)}">${V.e(V.initials(name))}</span>`;
  V.icon = name => {
    const paths = {
      grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
      people: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
      plus: '<path d="M12 5v14M5 12h14"/>', search: '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
      bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
      settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m9 3 6 0 1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1Z"/>',
      clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', check: '<path d="m5 12 4 4L19 6"/>',
      checklist: '<path d="m3 6 1 1 2-2M9 6h12M3 12h3m3 0h12M3 18h3m3 0h12"/>',
      mail: '<rect x="2" y="4" width="20" height="16" rx="3"/><path d="m2 6 10 7L22 6"/>',
      phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.4 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/>',
      more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
      menu: '<path d="M4 6h16M4 12h16M4 18h16"/>', close: '<path d="m6 6 12 12M6 18 18 6"/>',
      arrow: '<path d="M5 12h14m-5-5 5 5-5 5"/>', lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4"/>',
      heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
      exit: '<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4m7-16 5 5-5 5m5-5H9"/>',
      archive: '<rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v14h14V7M9 12h6"/>',
      edit: '<path d="m16 3 5 5-12 12-6 1 1-6Zm-2 2 5 5"/>',
      chart: '<path d="M3 3v18h18M7 16v-4m5 4V7m5 9v-7"/>',
    };
    return `<svg class="v-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.grid}</svg>`;
  };
  V.button = (action, label, icon = '', cls = '', extra = '') => `<button type="button" class="v-btn ${cls}" data-action="${action}" ${extra}>${icon ? V.icon(icon) : ''}${label}</button>`;
  V.date = (value, time = false) => !value ? '—' : new Date(value.length === 10 ? value + 'T12:00:00' : value).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: time ? 'numeric' : undefined, ...(time ? { hour: '2-digit', minute: '2-digit' } : {}) });
  V.localDateTime = iso => {
    if (!iso) return '';
    const d = new Date(iso); const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
  };
  V.toast = (message, error = false) => {
    const t = V.$('#visitas-toast'); t.textContent = message; t.className = 'v-toast ' + (error ? 'v-toast-error' : '');
    clearTimeout(V.toastTimer); V.toastTimer = setTimeout(() => t.className = '', 5000);
  };
  V.apiURL = path => {
    const url = new URL(config.api, location.href), index = path.indexOf('?');
    const route = index < 0 ? path : path.slice(0, index), query = index < 0 ? '' : path.slice(index + 1);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + route);
    else url.pathname = url.pathname.replace(/\/?$/, '/') + route;
    new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
    return url.href;
  };
  V.api = async (path, method = 'GET', body) => {
    const r = await fetch(V.apiURL(path), { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    let data; try { data = await r.json(); } catch { throw new Error('O servidor retornou uma resposta inesperada. Recarregue a página.'); }
    if (!r.ok) { const error = new Error(data.code === 'rest_cookie_invalid_nonce' ? 'Sua sessão expirou. Recarregue a página para entrar novamente.' : data.message || 'Não foi possível concluir.'); error.status = r.status; throw error; }
    return data;
  };
  V.form = (id, handler) => {
    const f = document.getElementById(id); if (!f) return;
    f.addEventListener('invalid', event => {
      const pane = event.target.closest('[data-pane],[data-settings-pane]');
      if (!pane?.hidden) return;
      const action = pane.hasAttribute('data-pane') ? 'card-tab' : 'settings-tab';
      const tab = pane.dataset.pane || pane.dataset.settingsPane;
      V.$(`[data-action="${action}"][data-tab="${tab}"]`, f)?.click();
    }, true);
    f.addEventListener('submit', async e => {
      e.preventDefault(); if (!f.reportValidity()) return;
      const submit = e.submitter || f.querySelector('[type="submit"]'); if (submit?.disabled) return;
      const err = f.querySelector('.v-form-error'); if (err) { err.textContent = ''; err.hidden = true; }
      if (submit) { submit.disabled = true; submit.classList.add('v-working'); }
      try { await handler(f, new FormData(f), e); }
      catch (error) { if (err) { err.textContent = error.message; err.hidden = false; err.scrollIntoView({ block: 'nearest' }); } else V.toast(error.message, true); }
      finally { if (submit) { submit.disabled = false; submit.classList.remove('v-working'); } }
    });
  };
  V.formError = '<div class="v-alert v-alert-error v-form-error" role="alert" hidden></div>';
  let restoreFocus;
  V.modal = (title, content, wide = false) => {
    restoreFocus = document.activeElement; V.modalDirty = false;
    V.$('#visitas-modal').innerHTML = `<div class="v-overlay"><section class="v-modal ${wide ? 'v-modal-wide' : ''}" role="dialog" aria-modal="true" aria-labelledby="v-modal-title"><header class="v-modal-header"><h2 id="v-modal-title">${V.e(title)}</h2>${V.button('close', '<span class="v-sr">Fechar</span>', 'close', 'v-icon-btn')}</header>${content}</section></div>`;
    document.body.classList.add('v-modal-open');
    V.$('#visitas-modal').addEventListener('input', event => { if (!event.target.closest('[data-no-dirty]')) V.modalDirty = true; }, { once: true });
    const dialog = V.$('.v-modal');
    requestAnimationFrame(() => { if (dialog?.isConnected) V.$('input:not([type="hidden"]),select,textarea,button', dialog)?.focus(); });
  };
  V.close = (force = false) => {
    if (!force && V.modalDirty && !confirm('Há alterações não salvas. Deseja sair sem salvar?')) return false;
    V.$('#visitas-modal').innerHTML = ''; document.body.classList.remove('v-modal-open'); V.modalDirty = false; restoreFocus?.focus(); return true;
  };
  V.actions.close = () => V.close();
  document.addEventListener('keydown', e => {
    const modal = V.$('.v-modal'); if (!modal) return;
    if (e.key === 'Escape') { e.preventDefault(); V.close(); }
    if (e.key === 'Tab') {
      const fs = V.$$('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),a[href]', modal).filter(x => x.getClientRects().length);
      if (!fs.length) return; const first = fs[0], last = fs[fs.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
  document.addEventListener('click', async e => {
    const t = e.target.closest('[data-action]'); if (!t || t.disabled) return;
    if (t.dataset.action === 'card' && (V.pendingMoves.has(+t.dataset.id) || Date.now() < (V.dragClickUntil || 0))) return;
    const fn = V.actions[t.dataset.action]; if (!fn) return;
    e.preventDefault(); if (t.tagName === 'BUTTON') t.disabled = true;
    try { await fn(t, e); } catch (error) { V.toast(error.message, true); }
    finally { if (t.isConnected && t.tagName === 'BUTTON') t.disabled = false; }
  });

  V.shell = () => {
    const d = V.data, b = d.brand;
    V.$('#visitas-app').innerHTML = `<div class="v-layout"><button class="v-sidebar-shade" data-action="menu" aria-label="Fechar menu"></button><aside class="v-sidebar"><a class="v-brand" href="${V.e(config.portal)}">${b.logo_url ? `<img src="${V.e(b.logo_url)}" alt="${V.e(b.brand_name)}">` : `<span class="v-brand-symbol">${V.icon('heart')}</span>`}<span><strong>${V.e(b.app_name)}</strong><small>${V.e(b.brand_name)}</small></span></a><div class="v-workspace"><span class="v-workspace-dot"></span> Espaço da equipe <span class="v-live">ATIVO</span></div><div class="v-sidebar-section"><p>SEUS QUADROS</p>${V.cap('create_boards') ? V.button('new-board', '<span class="v-sr">Novo quadro</span>', 'plus', 'v-icon-btn') : ''}</div><nav id="v-board-nav" aria-label="Quadros">${d.boards.map(x => `<button class="v-nav-item ${V.board && +V.board.id === x.id && V.screen === 'board' ? 'active' : ''}" data-action="board" data-id="${x.id}"><span class="v-nav-dot" style="background:${V.e(x.color)}"></span><span>${V.e(x.name)}</span>${x.visibility === 'private' ? V.icon('lock') : ''}</button>`).join('') || '<p class="v-sidebar-empty">Nenhum quadro disponível.</p>'}</nav><div class="v-sidebar-bottom"><p>GERENCIAMENTO</p>${V.cap('view_dashboard') ? V.button('dashboard', 'Dashboard', 'chart', 'v-nav-item' + (V.screen === 'dashboard' ? ' active' : '')) : ''}${V.cap('manage_users') ? V.button('users', 'Equipe', 'people', 'v-nav-item') : ''}${V.cap('view_logs') ? V.button('logs', 'Comunicações', 'mail', 'v-nav-item') : ''}${d.is_admin ? V.button('settings', 'Personalização e ajustes', 'settings', 'v-nav-item') : ''}<div class="v-sidebar-message">${V.icon('heart')}<p>${V.e(b.tagline)}</p></div><button class="v-user" data-action="profile">${V.avatar(d.user.name)}<span><strong>${V.e(d.user.name)}</strong><small>Meu perfil e acesso</small></span>${V.icon('more')}</button></div></aside><div class="v-main"><header class="v-topbar"><div>${V.button('menu', '<span class="v-sr">Abrir menu</span>', 'menu', 'v-icon-btn v-menu-toggle')}<span class="v-breadcrumb">${V.icon('grid')} <span>Meu espaço</span><i>/</i><strong id="v-crumb">Quadros</strong></span></div><div class="v-top-actions"><span class="v-today">${new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })}</span>${V.button('notifications', '<span class="v-sr">Notificações</span><span class="v-notice-dot" id="v-unread" hidden></span>', 'bell', 'v-icon-btn')}${V.button('logout', '<span class="v-sr">Sair</span>', 'exit', 'v-icon-btn')}</div></header><main id="v-content"></main></div></div>`;
  };
  V.refresh = async () => { V.data = await V.api('bootstrap'); };
  V.openBoard = async id => {
    V.screen = 'board'; document.body.classList.remove('v-menu-open');
    V.board = await V.api('boards/' + id); V.cards = [];
    let page = 1, more = true;
    while (more) { const r = await V.api(`boards/${id}/cards?page=${page}&archived=${V.filters.archived ? '1' : '0'}`); V.cards.push(...r.cards); more = r.has_more; page++; }
    V.shell(); V.renderBoard();
    const url = new URL(location); url.searchParams.set('board', id); ['card','view','month','dashboard_board','include_archived'].forEach(key => url.searchParams.delete(key)); history.replaceState(null, '', url);
  };
  V.reloadBoard = async () => { await V.refresh(); if (V.screen === 'dashboard') await V.openDashboard(V.dashboardFilters); else if (V.board) await V.openBoard(V.board.id); else V.shell(); };
  V.filtered = () => V.cards.filter(c => {
    const f = V.filters; const q = f.q.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const hay = [c.title, c.email, c.phone, c.source, ...c.labels].join(' ').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    if (q && !hay.includes(q)) return false;
    if (f.assigned === 'none' && +c.assigned_to) return false;
    if (f.assigned && f.assigned !== 'none' && +c.assigned_to !== +f.assigned) return false;
    const due = V.deadline(c) ? new Date(V.deadline(c)).getTime() : 0;
    if (f.due === 'overdue' && (!due || due >= Date.now() || +c.completed)) return false;
    if (f.due === 'soon' && (!due || due < Date.now() || due > Date.now() + 7 * 86400000 || +c.completed)) return false;
    if (f.due === 'completed' && !+c.completed) return false;
    return true;
  });
  V.cardHTML = c => {
    const u = V.staff(c.assigned_to), done = c.checklists.flatMap(x => x.items).filter(i => i.done).length, total = c.checklists.flatMap(x => x.items).length;
    const overdue = V.deadline(c) && new Date(V.deadline(c)) < new Date() && !+c.completed;
    const pending = V.pendingMoves.has(+c.id), edit = V.cap('edit_cards') && !V.board.read_only;
    return `<article class="v-card ${+c.completed ? 'v-card-completed' : ''} ${pending ? 'v-card-saving' : ''}" data-card="${c.id}" draggable="${edit && !pending}" aria-busy="${pending}"><button class="v-card-open" data-action="card" data-id="${c.id}" ${pending ? 'disabled' : ''}>${c.labels.length ? `<div class="v-card-labels">${c.labels.map((l, i) => `<span class="v-label v-label-${i % 4}">${V.e(l)}</span>`).join('')}</div>` : ''}<div class="v-card-title">${V.e(c.title)}</div>${c.source ? `<p class="v-card-source">${V.e(c.source)}</p>` : ''}${c.description ? `<p class="v-card-description">${V.e(c.description.slice(0, 100))}</p>` : ''}<div class="v-card-meta">${V.deadline(c) ? `<span class="v-badge ${+c.completed ? 'v-badge-done' : overdue ? 'v-badge-overdue' : ''}">${V.icon(+c.completed ? 'check' : 'clock')}${V.date(V.deadline(c))}</span>` : (+c.completed ? '<span class="v-badge v-badge-done">Concluído</span>' : '')}${total ? `<span class="v-check-progress ${done === total ? 'v-progress-done' : ''}">${V.icon('checklist')}${done}/${total}</span>` : ''}${u ? V.avatar(u.name, true) : '<span class="v-no-assignee" title="Sem responsável">—</span>'}</div>${pending ? '<span class="v-move-status" role="status">Salvando movimentação…</span>' : ''}</button></article>`;
  };

  V.placeCard = (id, listId, beforeId = 0, afterId = 0) => {
    const index = V.cards.findIndex(c => +c.id === +id); if (index < 0) return;
    const [card] = V.cards.splice(index, 1); card.list_id = +listId;
    let to = beforeId ? V.cards.findIndex(c => +c.id === +beforeId && +c.list_id === +listId) : -1;
    if (to < 0 && afterId) { const after = V.cards.findIndex(c => +c.id === +afterId && +c.list_id === +listId); if (after >= 0) to = after + 1; }
    if (to < 0) { for (let i = V.cards.length - 1; i >= 0; i--) if (+V.cards[i].list_id === +listId) { to = i + 1; break; } }
    V.cards.splice(to < 0 ? V.cards.length : to, 0, card);
  };
  V.syncCardElement = id => {
    const kanban = V.$('#v-kanban'); if (!kanban) return;
    const visible = V.filtered(), card = visible.find(c => +c.id === +id);
    const old = V.$(`[data-card="${id}"]`, kanban);
    if (!card) old?.remove();
    else {
      const holder = document.createElement('div'); holder.innerHTML = V.cardHTML(card); const node = holder.firstElementChild;
      if (old) old.replaceWith(node);
      const column = V.$(`[data-drop="${card.list_id}"]`, kanban);
      if (column) {
        const listCards = visible.filter(c => +c.list_id === +card.list_id), index = listCards.findIndex(c => +c.id === +id);
        const next = listCards[index + 1], anchor = next ? V.$(`[data-card="${next.id}"]`, column) : null;
        column.insertBefore(node, anchor);
      }
    }
    for (const list of V.board.lists) {
      const section = V.$(`[data-list="${list.id}"]`, kanban), column = V.$('[data-drop]', section); if (!column) continue;
      const count = visible.filter(c => +c.list_id === +list.id).length;
      V.$('.v-list-count', section).textContent = count;
      const empty = V.$('.v-empty-list', column);
      if (count) empty?.remove();
      else if (!empty) column.insertAdjacentHTML('beforeend', `<div class="v-empty-list"><span>${V.icon(+V.board.initial_list_id === +list.id ? 'people' : 'arrow')}</span><p>${+V.board.initial_list_id === +list.id ? 'Cada novo encontro começa aqui.' : 'Espaço para o próximo passo.'}</p><small>${V.filters.q || V.filters.assigned || V.filters.due ? 'Nenhum visitante neste filtro.' : 'Os cartões desta etapa aparecerão aqui.'}</small></div>`);
    }
    V.$('#v-results').textContent = `${visible.length} de ${V.cards.length} visitantes`;
  };
  V.moveCard = async (id, listId, beforeId = 0) => {
    id = +id; listId = +listId; beforeId = +beforeId;
    const card = V.cards.find(c => +c.id === id);
    if (!card || !V.cap('edit_cards') || V.board.read_only || V.pendingMoves.has(id) || beforeId === id || !V.board.lists.some(l => +l.id === listId)) return;
    const siblings = V.cards.filter(c => +c.list_id === +card.list_id), index = siblings.findIndex(c => +c.id === id);
    if (+card.list_id === listId && (beforeId === +(siblings[index + 1]?.id || 0))) return;
    const move = { boardId: +V.board.id, old: { ...card }, before: +(siblings[index + 1]?.id || 0), after: +(siblings[index - 1]?.id || 0) };
    V.pendingMoves.set(id, move);
    V.placeCard(id, listId, beforeId); V.syncCardElement(id);
    try {
      const saved = await V.api('cards/' + id, 'PATCH', { version: +move.old.version, list_id: listId, before_id: beforeId });
      V.pendingMoves.delete(id);
      if (+V.board?.id === move.boardId) { const at = V.cards.findIndex(c => +c.id === id); if (at >= 0) V.cards[at] = saved; V.syncCardElement(id); }
      V.toast('Cartão movido.');
    } catch (error) {
      V.pendingMoves.delete(id);
      if (+V.board?.id === move.boardId) {
        const at = V.cards.findIndex(c => +c.id === id); if (at >= 0) V.cards[at] = move.old;
        V.placeCard(id, +move.old.list_id, move.before, move.after); V.syncCardElement(id);
        if (error.status === 409) {
          try { const current = await V.api('cards/' + id); if (+V.board?.id === move.boardId) { const at = V.cards.findIndex(c => +c.id === id); if (at >= 0) V.cards[at] = current; V.placeCard(id, +current.list_id); V.syncCardElement(id); } }
          catch { /* The restored card remains available for an explicit refresh. */ }
        }
      }
      V.toast('A movimentação não foi salva. ' + error.message, true);
    }
  };
  V.clearDrag = () => {
    if (V.dragId) V.dragClickUntil = Date.now() + 250;
    V.dragId = 0; V.dragPoint = null; cancelAnimationFrame(V.dragFrame);
    V.$$('.v-dragging,.v-drop-target').forEach(el => el.classList.remove('v-dragging', 'v-drop-target'));
    V.$('.v-drop-marker')?.remove();
  };
  V.beforeAt = (column, y) => {
    const cards = V.$$('[data-card]', column).filter(el => +el.dataset.card !== V.dragId);
    let lo = 0, hi = cards.length;
    while (lo < hi) { const mid = (lo + hi) >> 1, rect = cards[mid].getBoundingClientRect(); if (y < rect.top + rect.height / 2) hi = mid; else lo = mid + 1; }
    return +(cards[lo]?.dataset.card || 0);
  };
  V.dragTick = () => {
    if (!V.dragId) return;
    const p = V.dragPoint, wrap = V.$('.v-kanban-wrap');
    if (p && wrap) {
      const rect = wrap.getBoundingClientRect(), edge = 44;
      if (p.x < rect.left + edge) wrap.scrollLeft -= Math.min(18, (rect.left + edge - p.x) / 3);
      else if (p.x > rect.right - edge) wrap.scrollLeft += Math.min(18, (p.x - rect.right + edge) / 3);
      const under = document.elementFromPoint(p.x, p.y)?.closest('.v-list'), column = V.$('[data-drop]', under);
      if (column) {
        const cr = column.getBoundingClientRect();
        if (p.y < cr.top + 32) column.scrollTop -= Math.min(14, (cr.top + 32 - p.y) / 3);
        else if (p.y > cr.bottom - 32) column.scrollTop += Math.min(14, (p.y - cr.bottom + 32) / 3);
        V.$$('.v-drop-target').forEach(el => { if (el !== column) el.classList.remove('v-drop-target'); }); column.classList.add('v-drop-target');
        let marker = V.$('.v-drop-marker');
        if (!marker) { marker = document.createElement('div'); marker.className = 'v-drop-marker'; marker.setAttribute('aria-hidden', 'true'); }
        const before = V.beforeAt(column, p.y), anchor = before ? V.$(`[data-card="${before}"]`, column) : null;
        if (marker.parentElement !== column || marker.nextElementSibling !== anchor) column.insertBefore(marker, anchor);
      }
    }
    V.dragFrame = requestAnimationFrame(V.dragTick);
  };
  V.bindDragDrop = () => {
    const kanban = V.$('#v-kanban'); if (!kanban || kanban.dataset.dragBound) return; kanban.dataset.dragBound = '1';
    kanban.addEventListener('dragstart', event => {
      const el = event.target.closest('[data-card]');
      if (!el || el.getAttribute('draggable') !== 'true' || V.pendingMoves.has(+el.dataset.card)) { event.preventDefault(); return; }
      V.dragId = +el.dataset.card; event.dataTransfer.setData('text/plain', el.dataset.card); event.dataTransfer.effectAllowed = 'move';
      requestAnimationFrame(() => { if (V.dragId === +el.dataset.card) el.classList.add('v-dragging'); }); V.dragFrame = requestAnimationFrame(V.dragTick);
    });
    kanban.addEventListener('dragover', event => { if (!V.dragId || !event.target.closest('.v-list')) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; V.dragPoint = { x: event.clientX, y: event.clientY }; });
    kanban.addEventListener('dragleave', event => { if (!kanban.contains(event.relatedTarget)) { V.dragPoint = null; V.$('.v-drop-marker')?.remove(); V.$$('.v-drop-target').forEach(el => el.classList.remove('v-drop-target')); } });
    kanban.addEventListener('drop', event => {
      const column = V.$('[data-drop]', event.target.closest('.v-list'));
      if (!column || !V.dragId) return; event.preventDefault();
      const id = V.dragId, listId = +column.dataset.drop, before = V.beforeAt(column, event.clientY);
      V.clearDrag(); void V.moveCard(id, listId, before);
    });
    kanban.addEventListener('dragend', V.clearDrag);
  };
  V.renderBoard = () => {
    const b = V.board, cards = V.cards;
    V.$('#v-crumb').textContent = b.name;
    const overdue = cards.filter(c => V.deadline(c) && new Date(V.deadline(c)) < new Date() && !+c.completed).length;
    const done = cards.filter(c => +c.completed).length;
    V.$('#v-content').innerHTML = `<section class="v-board-header"><div><p class="v-eyebrow v-primary-text">ACOLHIMENTO & RELACIONAMENTO</p><h1>${V.e(b.name)}</h1><p class="v-muted">${V.e(b.description || 'Um espaço para acompanhar cada próximo passo.')}</p></div><div class="v-board-actions">${b.can_manage ? V.button('board-settings', '<span class="v-sr">Configurar quadro</span>', 'settings', 'v-icon-btn') : ''}${V.cap('create_cards') && !b.read_only ? V.button('new-card', 'Novo visitante', 'plus', 'v-primary') : ''}</div></section><section class="v-stats" aria-label="Resumo do quadro"><div><span class="v-stat-icon">${V.icon('people')}</span><span><strong>${cards.length}</strong><small>${V.filters.archived ? 'Arquivados' : 'Visitantes no quadro'}</small></span></div><div><span class="v-stat-icon v-stat-amber">${V.icon('clock')}</span><span><strong>${overdue}</strong><small>Precisam de atenção</small></span></div><div><span class="v-stat-icon v-stat-green">${V.icon('check')}</span><span><strong>${done}</strong><small>Acompanhamentos concluídos</small></span></div><div class="v-board-privacy">${V.icon(b.visibility === 'private' ? 'lock' : 'people')}<span>${b.visibility === 'private' ? 'Quadro privado' : 'Compartilhado com a equipe'}<small>${b.read_only ? 'Seu acesso é de leitura' : 'Cada próximo passo faz diferença'}</small></span></div></section><div class="v-board-toolbar"><label class="v-search">${V.icon('search')}<input type="search" id="v-filter-search" placeholder="Buscar visitante, telefone ou etiqueta…" value="${V.e(V.filters.q)}" aria-label="Buscar visitante"></label><div class="v-filters"><select id="v-filter-assigned" aria-label="Filtrar por responsável"><option value="">Todos os responsáveis</option><option value="none" ${V.filters.assigned === 'none' ? 'selected' : ''}>Sem responsável</option>${V.data.staff.map(u => `<option value="${u.id}" ${+V.filters.assigned === +u.id ? 'selected' : ''}>${V.e(u.name)}</option>`).join('')}</select><select id="v-filter-due" aria-label="Filtrar por prazo"><option value="">Todos os prazos</option>${[['overdue', 'Em atraso'], ['soon', 'Próximos 7 dias'], ['completed', 'Concluídos']].map(([v, l]) => `<option value="${v}" ${v === V.filters.due ? 'selected' : ''}>${l}</option>`).join('')}</select>${V.button('archived', '<span class="v-sr">Alternar arquivados</span>', 'archive', `v-icon-btn ${V.filters.archived ? 'active' : ''}`)}${V.button('reload', '<span class="v-sr">Atualizar quadro</span>', 'grid', 'v-icon-btn')}</div></div><div class="v-kanban-wrap"><div id="v-kanban" class="v-kanban"></div></div><footer class="v-board-footer"><span id="v-results"></span><span>${V.icon('heart')} Cuidar é caminhar junto.</span></footer>`;
    V.drawCards();
    V.$('#v-filter-search').addEventListener('input', e => { V.filters.q = e.target.value; V.drawCards(); });
    V.$('#v-filter-assigned').addEventListener('change', e => { V.filters.assigned = e.target.value; V.drawCards(); });
    V.$('#v-filter-due').addEventListener('change', e => { V.filters.due = e.target.value; V.drawCards(); });
  };
  V.drawCards = () => {
    const b = V.board, cards = V.filtered();
    V.$('#v-results').textContent = `${cards.length} de ${V.cards.length} visitantes`;
    V.$('#v-kanban').innerHTML = b.lists.map(l => {
      const lc = cards.filter(c => +c.list_id === +l.id);
      return `<section class="v-list" data-list="${l.id}"><header class="v-list-header"><span class="v-list-dot" style="background:${V.e(l.color)}"></span><h2>${V.e(l.name)}</h2>${+b.initial_list_id === +l.id ? '<span class="v-initial-tag" title="Novos visitantes entram nesta lista">INÍCIO</span>' : ''}<span class="v-list-count">${lc.length}</span>${b.can_manage ? V.button('list-menu', '<span class="v-sr">Editar lista</span>', 'more', 'v-icon-btn', `data-id="${l.id}"`) : ''}</header><div class="v-list-cards" data-drop="${l.id}">${lc.map(V.cardHTML).join('') || `<div class="v-empty-list"><span>${V.icon(+b.initial_list_id === +l.id ? 'people' : 'arrow')}</span><p>${+b.initial_list_id === +l.id ? 'Cada novo encontro começa aqui.' : 'Espaço para o próximo passo.'}</p><small>${V.filters.q || V.filters.assigned || V.filters.due ? 'Nenhum visitante neste filtro.' : 'Os cartões desta etapa aparecerão aqui.'}</small></div>`}</div></section>`;
    }).join('') + (b.can_manage ? `<div class="v-add-list">${V.button('new-list', 'Adicionar lista', 'plus')}</div>` : '');
    V.bindDragDrop();
  };
  V.actions.board = t => V.openBoard(t.dataset.id);
  V.actions.reload = () => V.reloadBoard();
  V.actions.menu = () => document.body.classList.toggle('v-menu-open');
  V.actions.archived = async () => { V.filters.archived = !V.filters.archived; await V.openBoard(V.board.id); };
  V.actions.logout = () => { if (V.close()) V.$('#visitas-logout').submit(); };
  V.notifications = async (logs = false, page = 1) => {
    const r = await V.api(`notifications?logs=${logs ? 1 : 0}&page=${page}`);
    const state = { queued: 'Na fila', processing: 'Processando', available: 'Disponível', accepted: 'Aceito para envio', sent: 'Enviado', delivered: 'Entregue', failed: 'Falhou', cancelled: 'Cancelado', test_ready: 'Preparando teste', testing: 'Enviando teste', unknown: 'Sem confirmação' };
    V.modal(logs ? 'Comunicações da equipe' : 'Suas notificações', `<div class="v-modal-body"><p class="v-muted">${logs ? 'E-mails e SMS: acompanhe a fila e a última confirmação disponível.' : 'Novas atribuições e lembretes dos seus acompanhamentos.'}</p>${logs ? V.button('sync', 'Consultar status dos SMS', 'mail') : ''}<div class="v-notifications">${r.items.map(n => `<article><div class="v-notification-icon">${V.icon(n.channel === 'inapp' ? 'bell' : n.channel === 'sms' ? 'phone' : 'mail')}</div><div><strong>${V.e(n.subject)}</strong><p>${V.e(n.body)}</p>${logs ? `<small>${V.e(n.recipient)} · <span class="v-state v-state-${n.state}">${V.e(state[n.state] || n.state)}</span> · ${+n.attempts} tentativa(s)</small><small>${V.e(n.detail)}</small>` : ''}<small>${V.date(n.created_at.replace(' ', 'T') + 'Z', true)}</small>${n.card_id ? V.button('notification-card', 'Abrir cartão', 'arrow', 'v-link-btn', `data-id="${n.card_id}"`) : ''}</div></article>`).join('') || '<div class="v-empty-page">Tudo em dia por aqui.</div>'}</div><div class="v-pagination">${page > 1 ? V.button(logs ? 'logs-page' : 'notifications-page', 'Anterior', '', '', `data-page="${page - 1}"`) : ''}${r.has_more ? V.button(logs ? 'logs-page' : 'notifications-page', 'Próxima', '', '', `data-page="${page + 1}"`) : ''}</div></div>`, true);
    if (!logs) { await V.api('notifications/read', 'POST', {}); V.$('#v-unread').hidden = true; }
  };
  V.actions.notifications = () => V.notifications(); V.actions.logs = () => V.notifications(true);
  V.actions['notifications-page'] = t => V.notifications(false, +t.dataset.page); V.actions['logs-page'] = t => V.notifications(true, +t.dataset.page);
  V.actions.sync = async () => { await V.api('notifications/sync', 'POST', {}); await V.notifications(true); V.toast('Status consultados.'); };
  V.actions['notification-card'] = async t => { const c = await V.api('cards/' + t.dataset.id); V.close(true); if (!V.board || +V.board.id !== +c.board_id) await V.openBoard(c.board_id); await V.editCard(c); };
  V.init = async () => {
    try {
      await V.refresh(); const params = new URLSearchParams(location.search); const selected = V.data.boards.find(b => b.id === +params.get('board')) || V.data.boards[0];
      if (params.get('view') === 'dashboard' && V.cap('view_dashboard')) await V.openDashboard({ month: params.get('month') || V.data.month, board_id: params.get('dashboard_board') || '0', include_archived: params.get('include_archived') === '1' });
      else if (selected) await V.openBoard(selected.id);
      else { V.shell(); V.$('#v-content').innerHTML = `<div class="v-empty-page">${V.icon('grid')}<h1>Um novo espaço para acolher</h1><p>Crie um quadro ou peça acesso à coordenação.</p>${V.cap('create_boards') ? V.button('new-board', 'Criar primeiro quadro', 'plus', 'v-primary') : ''}</div>`; }
      if (params.get('card')) await V.actions.card({ dataset: { id: params.get('card') } });
      const poll = async () => { if (document.hidden) return; try { const n = await V.api('notifications'); const dot = V.$('#v-unread'); if (dot) dot.hidden = !n.unread; } catch { /* Session errors are shown on the next requested action. */ } };
      poll(); setInterval(poll, 60000);
    } catch (error) { V.$('#visitas-app').innerHTML = `<main class="v-boot"><h1>Não foi possível abrir o portal.</h1><p>${V.e(error.message)}</p><a class="v-btn v-primary" href="${V.e(config.portal)}">Recarregar</a></main>`; }
  };
  document.addEventListener('DOMContentLoaded', V.init);
})();

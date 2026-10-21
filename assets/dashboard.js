(() => {
  'use strict';
  const V = window.V, e = V.e;
  const monthLabel = month => month === 'all' ? 'Todo o período' : new Date(month + '-01T12:00:00').toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
  const day = value => !value ? '—' : new Date(value.length === 10 ? value + 'T12:00:00' : value).toLocaleDateString('pt-BR');
  const params = (filters = V.dashboardFilters) => new URLSearchParams({ month: filters.month, board_id: filters.board_id, include_archived: filters.include_archived ? '1' : '0' });
  const personButton = (id, label, cls = '') => V.button('dashboard-visitor', label, '', cls, `data-id="${+id}"`);
  const groupTitles = { visitors: 'Visitantes do período', accompanied: 'Pessoas acompanhadas no período', visited: 'Pessoas com visitas registradas', overdue: 'Visitantes com prazo em atraso', completed: 'Acompanhamentos concluídos' };

  V.openDashboard = async (filters = V.dashboardFilters) => {
    if (!V.cap('view_dashboard')) throw new Error('Seu perfil não tem acesso ao dashboard.');
    V.screen = 'dashboard';
    V.dashboardFilters = { month: filters?.month || V.data.month, board_id: filters?.board_id || 0, include_archived: !!filters?.include_archived };
    document.body.classList.remove('v-menu-open');
    V.shell(); V.$('#v-crumb').textContent = 'Dashboard de acompanhamento';
    V.$('#v-content').innerHTML = '<section class="v-dashboard"><div class="v-boot"><span class="v-spinner"></span><p>Preparando os indicadores…</p></div></section>';
    const url = new URL(location);
    url.searchParams.set('view', 'dashboard'); url.searchParams.set('month', V.dashboardFilters.month);
    url.searchParams.set('dashboard_board', V.dashboardFilters.board_id);
    url.searchParams.set('include_archived', V.dashboardFilters.include_archived ? '1' : '0');
    url.searchParams.delete('card'); history.replaceState(null, '', url);
    try { await V.refreshDashboard(); }
    catch (error) {
      if (V.screen !== 'dashboard') return;
      V.$('#v-content').innerHTML = `<section class="v-dashboard"><div class="v-empty-page"><h1>Dashboard de acompanhamento</h1><p>${e(error.message)}</p>${V.button('dashboard-refresh', 'Tentar novamente', 'arrow')}</div></section>`;
    }
  };
  V.refreshDashboard = async () => {
    const request = V.dashboardRequest = (V.dashboardRequest || 0) + 1;
    const data = await V.api('dashboard?' + params());
    if (request !== V.dashboardRequest || V.screen !== 'dashboard') return;
    V.dashboardData = data; V.renderDashboard();
  };
  V.renderDashboard = () => {
    const d = V.dashboardData, f = V.dashboardFilters, t = d.totals;
    const period = monthLabel(f.month);
    const metrics = [
      ['visitors', 'Visitantes no período', t.visitors, 'Pela primeira visita ou data do cadastro.', 'people'],
      ['accompanied', 'Pessoas acompanhadas', t.accompanied, 'Com visita ou interação no período.', 'heart'],
      ['visited', 'Visitas registradas', t.visits, `${t.visited_people} pessoa(s) visitada(s) no período.`, 'checklist'],
      ['overdue', 'Prazos em atraso', t.overdue, 'Dos visitantes do período, na data de hoje.', 'clock'],
    ];
    const maxTrend = Math.max(1, ...d.trend.flatMap(p => [p.visitors, p.accompanied]));
    V.$('#v-content').innerHTML = `<section class="v-dashboard"><header class="v-dashboard-heading"><div><p class="v-eyebrow">GESTÃO DO ACOLHIMENTO</p><h1>Dashboard de acompanhamento</h1><p class="v-muted">Cada pessoa, um próximo passo. Acompanhe o cuidado da equipe.</p></div>${V.button('dashboard-people', 'Ver visitantes', 'people', 'v-primary', 'data-group="visitors"')}</header>
      <form id="v-dashboard-filters" class="v-dashboard-filters"><label>Mês de referência<input id="v-dashboard-month" name="month" type="month" value="${f.month === 'all' ? '' : e(f.month)}" ${f.month === 'all' ? 'disabled' : 'required'}></label><label>Quadro<select name="board_id" id="v-dashboard-board"><option value="0">Todos os quadros permitidos</option>${V.data.boards.map(b => `<option value="${b.id}" ${+f.board_id === +b.id ? 'selected' : ''}>${e(b.name)}</option>`).join('')}</select></label><label class="v-check"><input id="v-dashboard-all" type="checkbox" name="all" ${f.month === 'all' ? 'checked' : ''}>Todos os meses</label><label class="v-check"><input type="checkbox" name="include_archived" ${f.include_archived ? 'checked' : ''}>Incluir arquivados</label><button class="v-btn v-primary" type="submit">Aplicar filtros</button></form>
      <div class="v-dashboard-period"><span>${e(period)} · ${f.board_id ? e(V.data.boards.find(b => +b.id === +f.board_id)?.name || 'Quadro selecionado') : 'Todos os quadros permitidos'}</span><small>Atualizado às ${new Date(d.generated_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</small></div>
      <div class="v-dashboard-metrics">${metrics.map(([group, title, value, hint, icon]) => `<button class="v-dashboard-metric v-dashboard-metric-${group}" data-action="dashboard-people" data-group="${group}"><span class="v-metric-heading"><span>${e(title)}</span><i>${V.icon(icon)}</i></span><strong data-metric="${group}">${value.toLocaleString('pt-BR')}</strong><small>${e(hint)}</small><span class="v-metric-link">Ver pessoas ${V.icon('arrow')}</span></button>`).join('')}</div>
      <div class="v-dashboard-charts"><section class="v-dashboard-panel"><header><div><h2>Pessoas por etapa</h2><p>Posição atual dos visitantes do período selecionado.</p></div><span class="v-dashboard-total">${t.visitors} pessoas</span></header><div class="v-stage-chart">${d.stages.map(s => `<button class="v-stage-row" data-action="dashboard-people" data-group="visitors" data-list="${s.id}"><span class="v-stage-label"><span><b>${e(s.name)}</b>${!+f.board_id ? `<small>${e(s.board_name)}</small>` : ''}</span><strong>${s.total}</strong></span><span class="v-stage-track"><span style="width:${t.visitors ? Math.round(s.total / t.visitors * 100) : 0}%;background:${e(s.color)}"></span></span></button>`).join('') || '<p class="v-muted">Nenhuma etapa disponível nos quadros permitidos.</p>'}</div><footer>${V.button('dashboard-people', `${t.completed} acompanhamento(s) concluído(s)`, 'check', 'v-link-btn', 'data-group="completed"')}<small>Uma pessoa por cartão. Clique em uma etapa para ver os nomes.</small></footer></section>
      <section class="v-dashboard-panel"><header><div><h2>Acolhimento mês a mês</h2><p>Os seis meses até o período selecionado.</p></div>${V.icon('chart')}</header><div class="v-trend-legend"><span><i></i>Visitantes</span><span><i></i>Pessoas acompanhadas</span></div><div class="v-trend-chart" role="group" aria-label="Visitantes e pessoas acompanhadas nos últimos seis meses">${d.trend.map(p => `<button class="v-trend-month ${p.month === f.month ? 'active' : ''}" data-action="dashboard-month" data-month="${p.month}" aria-label="${e(monthLabel(p.month))}: ${p.visitors} visitantes, ${p.accompanied} pessoas acompanhadas"><span class="v-trend-bars"><span style="height:${p.visitors / maxTrend * 100}%"><b>${p.visitors}</b></span><span style="height:${p.accompanied / maxTrend * 100}%"><b>${p.accompanied}</b></span></span><small>${e(new Date(p.month + '-01T12:00:00').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', ''))}</small></button>`).join('')}</div><p class="v-dashboard-note">Acompanhadas inclui quem chegou em meses anteriores e recebeu uma visita ou interação no mês.</p></section></div>
      <section class="v-dashboard-panel v-dashboard-recent"><header><div><h2>Últimos registros de acompanhamento</h2><p>Visitas e interações registradas pela equipe no período.</p></div>${V.button('dashboard-people', 'Ver pessoas acompanhadas', 'arrow', 'v-link-btn', 'data-group="accompanied"')}</header><div>${d.recent.map(a => `<button class="v-dashboard-record" data-action="dashboard-visitor" data-id="${a.card_id}">${V.avatar(a.title, true)}<span class="v-record-person"><strong>${e(a.title)}</strong><small>${e(a.kind === 'visit' ? 'Visita realizada' : a.kind === 'checklist_note' ? 'Observação de checklist' : 'Interação')} · ${e(day(a.occurred_on || a.created_at.replace(' ', 'T') + 'Z'))} · ${e(a.author || 'Usuário removido')}</small><span>${e(a.content.slice(0, 180))}</span></span><span class="v-record-stage">${e(a.list_name)}</span>${V.icon('arrow')}</button>`).join('') || '<div class="v-dashboard-empty">Ainda não há visitas ou interações neste período. Abra um visitante para registrar o acompanhamento.</div>'}</div></section>
      <p class="v-dashboard-note">${f.include_archived ? 'Inclui cartões arquivados.' : 'Exibe cartões ativos.'} O mês dos visitantes considera a data da primeira visita; sem essa data, considera o cadastro. As etapas mostram a situação atual.</p></section>`;
    V.$('#v-dashboard-all').addEventListener('change', event => { const input = V.$('#v-dashboard-month'); input.disabled = event.target.checked; input.required = !event.target.checked; if (!input.value) input.value = V.data.month; });
    V.form('v-dashboard-filters', async (form, fd) => V.openDashboard({ month: fd.has('all') ? 'all' : fd.get('month'), board_id: fd.get('board_id'), include_archived: fd.has('include_archived') }));
  };
  V.actions.dashboard = () => V.openDashboard();
  V.actions['dashboard-refresh'] = () => V.openDashboard(V.dashboardFilters);
  V.actions['dashboard-month'] = t => V.openDashboard({ ...V.dashboardFilters, month: t.dataset.month });

  V.openDashboardPeople = async (state = {}) => {
    if (V.$('.v-modal') && !V.close()) return;
    V.dashboardPeople = { group: 'visitors', list_id: 0, page: 1, q: '', ...state };
    const p = V.dashboardPeople, stage = V.dashboardData.stages.find(s => s.id === +p.list_id);
    V.modal(stage ? `${stage.name} · ${stage.board_name}` : groupTitles[p.group], `<div class="v-modal-body"><p class="v-muted v-small">${e(monthLabel(V.dashboardFilters.month))} · ${p.group === 'visitors' || p.group === 'overdue' || p.group === 'completed' ? 'Pela data da primeira visita ou cadastro.' : 'Pela data das visitas e interações, mesmo quando o visitante chegou antes.'}</p><label class="v-search v-dashboard-search">${V.icon('search')}<input id="v-dashboard-search" data-no-dirty value="${e(p.q)}" maxlength="190" placeholder="Buscar nome, e-mail ou telefone" aria-label="Buscar visitantes"></label><div id="v-dashboard-people-results" aria-live="polite"></div></div>`, true);
    V.$('#v-dashboard-search').addEventListener('input', event => {
      p.q = event.target.value; p.page = 1; clearTimeout(V.dashboardSearchTimer);
      V.dashboardSearchTimer = setTimeout(() => { if (V.dashboardPeople === p) V.loadDashboardPeople().catch(error => V.toast(error.message, true)); }, 250);
    });
    await V.loadDashboardPeople();
  };
  V.loadDashboardPeople = async () => {
    const state = V.dashboardPeople, query = params(), request = V.peopleRequest = (V.peopleRequest || 0) + 1;
    Object.entries(state).forEach(([key, value]) => query.set(key, value));
    const box = V.$('#v-dashboard-people-results'); if (!box) return;
    box.setAttribute('aria-busy', 'true');
    try {
      const result = await V.api('dashboard/visitors?' + query);
      if (!box.isConnected || request !== V.peopleRequest || state !== V.dashboardPeople) return;
      box.innerHTML = `<p class="v-dashboard-result-count">${result.total} pessoa(s) encontrada(s)</p><div class="v-dashboard-people">${result.items.map(c => `<button type="button" class="v-dashboard-person" data-action="dashboard-visitor" data-id="${c.id}" data-from-list="1">${V.avatar(c.title, true)}<span><strong>${e(c.title)}</strong><small>${e(c.phone || c.email || 'Contato não informado')}</small></span><span><b>${e(c.list_name)}</b><small>${e(c.board_name)}</small></span><span><b>${e(c.assignee || 'Sem responsável')}</b><small>Primeira visita: ${e(day(c.visit_date || c.created_at.replace(' ', 'T') + 'Z'))}</small></span>${V.icon('arrow')}</button>`).join('') || '<div class="v-dashboard-empty">Nenhum visitante encontrado para estes filtros.</div>'}</div><div class="v-pagination"><span>Página ${result.page}</span><div>${result.page > 1 ? V.button('dashboard-people-page', 'Anterior', '', '', `data-page="${result.page-1}"`) : ''}${result.has_more ? V.button('dashboard-people-page', 'Próxima', '', '', `data-page="${result.page+1}"`) : ''}</div></div>`;
    } catch (error) { if (box.isConnected) box.innerHTML = `<div class="v-alert v-alert-error">${e(error.message)}</div>${V.button('dashboard-people-page', 'Tentar novamente', '', '', `data-page="${state.page}"`)}`; }
    finally { box.removeAttribute('aria-busy'); }
  };
  V.actions['dashboard-people'] = t => V.openDashboardPeople({ group: t.dataset.group || 'visitors', list_id: +t.dataset.list || 0 });
  V.actions['dashboard-people-page'] = async t => { V.dashboardPeople.page = +t.dataset.page; await V.loadDashboardPeople(); };
  V.actions['dashboard-back'] = () => V.openDashboardPeople(V.dashboardPeople);

  V.followupRecordsHTML = items => items.map(a => `<article class="v-followup-record"><header><span class="v-followup-kind">${e(a.kind === 'visit' ? 'Visita realizada' : a.kind === 'checklist_note' ? 'Observação de checklist' : 'Interação')}</span><time>${e(day(a.occurred_on || a.created_at.replace(' ', 'T') + 'Z'))}</time></header><p>${e(a.content)}</p><small>${e(a.author || (a.user_id ? 'Usuário removido' : 'Sistema'))} · Registrado em ${V.date(a.created_at.replace(' ', 'T') + 'Z', true)}</small></article>`).join('');
  V.openDashboardVisitor = async (id, fromList = false) => {
    if (V.$('.v-modal') && !V.close()) return;
    const [c, records] = await Promise.all([V.api('cards/' + id), V.api('cards/' + id + '/followups')]);
    const b = await V.api('boards/' + c.board_id);
    V.dashboardVisitor = { card: c, board: b, page: 1 };
    V.modal(c.title, `<div class="v-modal-body v-visitor-overview"><div class="v-visitor-heading">${V.avatar(c.title)}<div><p class="v-eyebrow">ACOMPANHAMENTO DO VISITANTE</p><strong>${e(b.name)}</strong><small>${e(b.lists.find(l => +l.id === +c.list_id)?.name || 'Etapa não informada')}${+c.completed ? ' · Concluído' : ''}${+c.archived ? ' · Arquivado' : ''}</small></div></div><dl class="v-visitor-facts"><div><dt>Telefone</dt><dd>${c.phone ? `<a href="tel:${e(c.phone)}">${e(c.phone)}</a>` : 'Não informado'}</dd></div><div><dt>E-mail</dt><dd>${c.email ? `<a href="mailto:${e(c.email)}">${e(c.email)}</a>` : 'Não informado'}</dd></div><div><dt>Primeira visita</dt><dd>${e(day(c.visit_date))}</dd></div><div><dt>Pessoa responsável</dt><dd>${e(V.staff(c.assigned_to)?.name || 'Não atribuída')}</dd></div><div><dt>Como chegou</dt><dd>${e(c.source || 'Não informado')}</dd></div><div><dt>Prazo do acompanhamento</dt><dd>${c.due_at ? V.date(c.due_at, true) : 'Não definido'}</dd></div></dl>${c.address ? `<div class="v-visitor-note"><h3>Endereço / bairro</h3><p>${e(c.address)}</p></div>` : ''}<div class="v-visitor-note"><h3>Observações do cadastro</h3><p>${e(c.description || 'Nenhuma observação no cadastro.')}</p></div><section class="v-visitor-followups"><h3>Visitas e observações de acompanhamento</h3><p class="v-muted v-small">Todo o histórico deste visitante, independentemente do mês do dashboard.</p>${records.can_comment ? `<form id="v-dashboard-visit-form" class="v-visit-form">${V.formError}<label>Data da visita realizada<input name="date" type="date" value="${e(V.data.today)}" max="${e(V.data.today)}" required></label><label>Observações da visita<textarea name="content" rows="3" maxlength="8000" required placeholder="Como foi a visita? Registre o que foi conversado e os próximos passos."></textarea></label><button type="submit" class="v-btn v-primary">${V.icon('plus')}Registrar visita</button></form>` : '<p class="v-muted v-small">Seu acesso permite consultar os registros deste visitante.</p>'}<div id="v-dashboard-followups">${V.followupRecordsHTML(records.items) || '<p class="v-dashboard-empty">Nenhuma visita ou interação registrada.</p>'}</div>${records.has_more ? V.button('dashboard-more-followups', 'Carregar registros anteriores', '', '', `data-id="${c.id}"`) : ''}</section></div><footer class="v-modal-footer"><div>${fromList ? V.button('dashboard-back', 'Voltar à lista') : V.button('close', 'Fechar')}</div><div>${V.button('dashboard-full-card', 'Abrir cartão completo', 'arrow', '', `data-id="${c.id}"`)}</div></footer>`, true);
    V.form('v-dashboard-visit-form', async (form, fd) => {
      await V.api('cards/' + c.id + '/visits', 'POST', { date: fd.get('date'), content: fd.get('content') });
      V.modalDirty = false; form.elements.content.value = '';
      const refreshed = await Promise.allSettled([V.api('cards/' + c.id + '/followups'), V.refreshDashboard()]);
      if (refreshed[0].status === 'fulfilled') {
        const data = refreshed[0].value; V.dashboardVisitor.page = 1;
        V.$('#v-dashboard-followups').innerHTML = V.followupRecordsHTML(data.items);
        V.$('[data-action="dashboard-more-followups"]')?.remove();
        if (data.has_more) V.$('#v-dashboard-followups').insertAdjacentHTML('afterend', V.button('dashboard-more-followups', 'Carregar registros anteriores', '', '', `data-id="${c.id}"`));
      }
      V.toast(refreshed.some(r => r.status === 'rejected') ? 'Visita salva. Atualize a tela para consultar os dados mais recentes.' : 'Visita e observações registradas.');
    });
  };
  V.actions['dashboard-visitor'] = t => V.openDashboardVisitor(+t.dataset.id, t.dataset.fromList === '1');
  V.actions['dashboard-more-followups'] = async t => {
    const next = V.dashboardVisitor.page + 1, result = await V.api('cards/' + t.dataset.id + '/followups?page=' + next);
    V.dashboardVisitor.page = next; V.$('#v-dashboard-followups').insertAdjacentHTML('beforeend', V.followupRecordsHTML(result.items));
    if (!result.has_more) t.remove();
  };
  V.actions['dashboard-full-card'] = async t => {
    if (!V.close()) return;
    V.board = V.dashboardVisitor.board;
    await V.editCard(await V.api('cards/' + t.dataset.id));
  };
})();

(() => {
  'use strict';
  const V = window.V, e = V.e;
  const currentFlow = () => V.editingCard?.id ? V.editingCard.workflow || {} : V.board?.workflow || {};
  const rule = item => ({ amount: 0, unit: 'business_days', target_list_id: 0, require_note: false, ...item.rule });
  const units = { hours: 'Horas corridas', days: 'Dias corridos', business_days: 'Dias úteis' };
  const timeLabel = r => `${r.amount} ${r.amount === 1 ? ({hours:'hora corrida',days:'dia corrido',business_days:'dia útil'})[r.unit] : units[r.unit].toLowerCase()}`;
  const noteLabel = () => currentFlow().notes_label || V.board?.workflow?.notes_label || 'Atividades e observações';
  const control = (key, label, value, type = 'text', attrs = '') => `<label>${e(label)}<input data-${key} type="${type}" value="${e(value)}" ${attrs}></label>`;
  const rulesHTML = item => {
    const r = rule(item), target = V.board.lists.find(l => +l.id === +r.target_list_id);
    return `<details class="v-item-rules"><summary>Prazo e ação <small>${r.amount ? e(timeLabel(r)) : 'Sem prazo'}${target ? ' · → ' + e(target.name) : ''}</small></summary><div class="v-rule-grid">${control('rule-amount', 'Tempo de execução (0 = sem prazo)', r.amount, 'number', `min="0" max="${r.unit === 'hours' ? 8760 : 365}" step="${r.unit === 'hours' ? '.01' : '1'}"`)}<label>Unidade<select data-rule-unit>${Object.entries(units).map(([value,label]) => `<option value="${value}" ${r.unit === value ? 'selected' : ''}>${e(label)}</option>`).join('')}</select></label><label class="v-span-2">Ao concluir, mover o cartão para<select data-rule-target><option value="0">Manter na lista atual</option>${V.board.lists.map(l => `<option value="${l.id}" ${+r.target_list_id === +l.id ? 'selected' : ''}>${e(l.name)}</option>`).join('')}</select></label></div><label class="v-check"><input data-rule-note type="checkbox" ${r.require_note ? 'checked' : ''}>Exigir observação ao concluir</label><label>Atividade / orientação<textarea data-item-instructions rows="2" maxlength="4000" placeholder="O que deve ser realizado neste passo?">${e(item.instructions || '')}</textarea></label></details>`;
  };
  const readRule = root => ({ amount: +V.$('[data-rule-amount]', root).value, unit: V.$('[data-rule-unit]', root).value,
    target_list_id: +V.$('[data-rule-target]', root).value, require_note: V.$('[data-rule-note]', root).checked });
  const assigneeHTML = (item, template = false) => {
    const assigned = +item.assigned_to || 0, canDelegate = template || V.cap('view_all_cards') || V.cap('manage_boards') || V.data.is_admin;
    const choices = (V.board.assignees || []).filter(user => canDelegate || +user.id === +V.data.user.id);
    const unavailable = assigned && !choices.some(user => +user.id === assigned);
    return `<label class="v-task-assignee">Responsável pela tarefa<select data-item-assigned aria-label="Responsável pela tarefa"><option value="0">Sem responsável</option>${choices.map(user => `<option value="${user.id}" ${+user.id === assigned ? 'selected' : ''}>${e(user.name)}</option>`).join('')}${unavailable ? `<option value="${assigned}" selected>${e(V.staff(assigned)?.name || 'Usuário indisponível')} · atribuição atual</option>` : ''}</select></label>`;
  };
  const itemDates = item => `<div class="v-item-dates">${item.planned_due_at ? `<span>Previsto: <strong>${V.date(item.planned_due_at, true)}</strong></span>` : ''}${!item.done && item.execution_due_at ? `<span>Execução até: <strong>${V.date(item.execution_due_at, true)}</strong></span>` : ''}${item.done ? `<span class="v-item-completed">${item.completed_at ? `Concluído em ${V.date(item.completed_at, true)}${V.staff(item.completed_by) ? ' · ' + e(V.staff(item.completed_by).name) : ''}` : 'Concluído · data anterior não registrada'}</span>` : ''}</div>`;
  const orderButtons = (action, kind) => `<span class="v-item-order">${V.button(action, '<span class="v-sr">Mover acima</span>↑', '', 'v-icon-btn', `data-direction="up" data-kind="${kind}"`)}${V.button(action, '<span class="v-sr">Mover abaixo</span>↓', '', 'v-icon-btn', `data-direction="down" data-kind="${kind}"`)}</span>`;
  V.checklistItemHTML = item => `<div class="v-workflow-item" data-item="${e(item.id)}"><div class="v-checklist-item"><input type="checkbox" data-item-done ${item.done ? 'checked' : ''} aria-label="Concluir item"><input data-item-text value="${e(item.text)}" placeholder="Descreva este passo" aria-label="Descrição do item" required maxlength="190">${orderButtons('workflow-order','item')}${V.button('remove-checkitem', '<span class="v-sr">Excluir item</span>', 'close', 'v-icon-btn')}</div>${assigneeHTML(item)}${itemDates(item)}${rulesHTML(item)}${currentFlow().notes_enabled !== false || rule(item).require_note ? `<label class="v-item-notes">${e(noteLabel())}${rule(item).require_note ? ' *' : ''}<textarea data-item-notes rows="2" maxlength="8000" placeholder="Registre a execução e os próximos passos.">${e(item.notes || '')}</textarea></label>` : `<textarea data-item-notes hidden>${e(item.notes || '')}</textarea>`}</div>`;
  V.checklistHTML = list => `<section class="v-checklist" data-checklist="${e(list.id)}"><header>${V.icon('checklist')}<input data-checklist-title value="${e(list.title)}" aria-label="Nome do checklist" required maxlength="190">${orderButtons('workflow-order','checklist')}${V.button('remove-checklist', '<span class="v-sr">Excluir checklist</span>', 'close', 'v-icon-btn')}</header><details class="v-checklist-notes"><summary>Atividades e observações do checklist</summary><label>Atividades previstas<textarea data-checklist-description rows="2" maxlength="4000">${e(list.description || '')}</textarea></label>${currentFlow().notes_enabled !== false ? `<label>${e(noteLabel())}<textarea data-checklist-notes rows="3" maxlength="8000">${e(list.notes || '')}</textarea></label>` : `<textarea data-checklist-notes hidden>${e(list.notes || '')}</textarea>`}</details><div class="v-checklist-items">${list.items.map(V.checklistItemHTML).join('')}</div>${V.button('add-checkitem','Adicionar item','plus','v-link-btn')}</section>`;
  V.collectChecklists = () => V.$$('[data-checklist]', V.$('#v-card-form')).map(root => ({ id: root.dataset.checklist,
    title: V.$('[data-checklist-title]', root).value, description: V.$('[data-checklist-description]', root).value, notes: V.$('[data-checklist-notes]', root).value,
    items: V.$$('[data-item]', root).map(item => ({ id: item.dataset.item, text: V.$('[data-item-text]', item).value, done: V.$('[data-item-done]', item).checked, assigned_to: +V.$('[data-item-assigned]', item).value,
      rule: readRule(item), instructions: V.$('[data-item-instructions]', item).value, notes: V.$('[data-item-notes]', item).value })) }));

  V.templateItemHTML = item => `<section class="v-template-item" data-template-item="${e(item.id)}"><div class="v-template-item-heading"><input data-template-text value="${e(item.text)}" placeholder="Nome do passo, ex.: Cadastro" required maxlength="190" aria-label="Nome do passo">${orderButtons('workflow-order','template-item')}${V.button('workflow-remove-template-item','<span class="v-sr">Excluir passo</span>','close','v-icon-btn')}</div>${assigneeHTML(item, true)}${rulesHTML(item)}</section>`;
  V.templateHTML = template => `<div class="v-template" data-template="${e(template.id)}"><div class="v-inline-actions"><input data-template-title value="${e(template.title)}" placeholder="Nome do checklist" aria-label="Nome do modelo" required>${orderButtons('workflow-order','template')}${V.button('remove-template','<span class="v-sr">Excluir modelo</span>','close','v-icon-btn')}</div><label>Atividades previstas deste checklist<textarea data-template-description rows="2" maxlength="4000">${e(template.description || '')}</textarea></label><div class="v-template-items">${template.items.map(V.templateItemHTML).join('')}</div>${V.button('workflow-add-template-item','Adicionar passo','plus','v-link-btn')}<p class="v-muted v-small">A ordem dos passos define a soma dos prazos. Os cartões existentes mantêm seu progresso e sua cópia das regras.</p></div>`;
  V.collectTemplates = () => V.$$('[data-template]', V.$('#v-board-settings')).map(root => ({ id: root.dataset.template,
    title: V.$('[data-template-title]', root).value, description: V.$('[data-template-description]', root).value,
    items: V.$$('[data-template-item]', root).map(item => ({ id: item.dataset.templateItem, text: V.$('[data-template-text]', item).value,
      assigned_to: +V.$('[data-item-assigned]', item).value, instructions: V.$('[data-item-instructions]', item).value, rule: readRule(item) })) }));
  V.workflowSettingsHTML = (settings = {}) => `<section class="v-form-section v-workflow-settings"><h3>Planejamento e automações dos checklists</h3><p class="v-muted v-small">Configure os novos cartões. A previsão final soma os prazos de todos os passos, na ordem dos modelos, a partir do cadastro.</p><label class="v-check"><input name="workflow_enabled" type="checkbox" ${settings.enabled ? 'checked' : ''}>Ativar prazos e ações automáticas nos novos cartões</label><div class="v-form-grid"><label>Prazo de execução de cada etapa<select name="workflow_deadline_mode"><option value="activation" ${settings.deadline_mode !== 'registration' ? 'selected' : ''}>A partir do início da etapa</option><option value="registration" ${settings.deadline_mode === 'registration' ? 'selected' : ''}>Seguir o cronograma desde o cadastro</option></select></label><label>Nome do campo de observações<input name="workflow_notes_label" maxlength="100" value="${e(settings.notes_label || 'Atividades e observações')}"></label></div><label class="v-check"><input name="workflow_sync_due" type="checkbox" ${settings.sync_due !== false ? 'checked' : ''}>Usar a soma dos checklists como entrega final do cartão</label><label class="v-check"><input name="workflow_require_order" type="checkbox" ${settings.require_order !== false ? 'checked' : ''}>Concluir os passos na ordem</label><label class="v-check"><input name="workflow_complete_card" type="checkbox" ${settings.complete_card ? 'checked' : ''}>Concluir o cartão quando todos os passos estiverem concluídos</label><label class="v-check"><input name="workflow_notes_enabled" type="checkbox" ${settings.notes_enabled !== false ? 'checked' : ''}>Mostrar atividades e observações em cada checklist e passo</label><details class="v-workflow-calendar"><summary>Calendário de dias úteis</summary><p class="v-muted v-small">O dia do cadastro não é contado como primeiro dia do prazo. Horas são corridas; dias úteis respeitam este calendário.</p><div class="v-weekday-options">${['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'].map((name,i) => `<label class="v-check"><input name="workflow_weekdays" type="checkbox" value="${i+1}" ${(settings.weekdays || [1,2,3,4,5]).includes(i+1) ? 'checked' : ''}>${name}</label>`).join('')}</div><label>Feriados e dias sem expediente<textarea name="workflow_holidays" rows="3" placeholder="Uma data por linha, no formato DD/MM/AAAA">${e((settings.holidays || []).map(date => date.split('-').reverse().join('/')).join('\n'))}</textarea></label><p class="v-muted v-small">Cadastre aqui os feriados que devem ser descontados. O fuso horário segue a configuração do WordPress ao aplicar o planejamento.</p></details></section>`;
  V.collectWorkflowSettings = form => {
    const fd = new FormData(form), holidays = String(fd.get('workflow_holidays') || '').split(/\n|,/).map(s => s.trim()).filter(Boolean).map(s => /^\d{2}\/\d{2}\/\d{4}$/.test(s) ? s.split('/').reverse().join('-') : s);
    return { enabled: fd.has('workflow_enabled'), sync_due: fd.has('workflow_sync_due'), require_order: fd.has('workflow_require_order'), complete_card: fd.has('workflow_complete_card'),
      notes_enabled: fd.has('workflow_notes_enabled'), notes_label: fd.get('workflow_notes_label'), deadline_mode: fd.get('workflow_deadline_mode'), weekdays: fd.getAll('workflow_weekdays').map(Number), holidays };
  };
  V.actions['workflow-add-template-item'] = target => { target.closest('[data-template]').querySelector('.v-template-items').insertAdjacentHTML('beforeend', V.templateItemHTML({ id: V.uid(), text: '' })); V.modalDirty = true; };
  V.actions['workflow-remove-template-item'] = target => { target.closest('[data-template-item]').remove(); V.modalDirty = true; };
  V.actions['workflow-order'] = target => {
    const attr = { item: 'data-item', checklist: 'data-checklist', 'template-item': 'data-template-item', template: 'data-template' }[target.dataset.kind];
    const node = target.closest('[' + attr + ']'), sibling = target.dataset.direction === 'up' ? node.previousElementSibling : node.nextElementSibling;
    if (!sibling?.hasAttribute(attr)) return;
    if (target.dataset.direction === 'up') node.parentNode.insertBefore(node, sibling); else node.parentNode.insertBefore(sibling, node);
    V.modalDirty = true;
  };
  document.addEventListener('change', event => {
    if (!event.target.matches('[data-rule-unit]')) return;
    const amount = V.$('[data-rule-amount]', event.target.closest('.v-item-rules'));
    amount.max = event.target.value === 'hours' ? '8760' : '365'; amount.step = event.target.value === 'hours' ? '.01' : '1';
  });
  document.addEventListener('invalid', event => { const details = event.target.closest('details'); if (details) details.open = true; }, true);

  const readState = () => {
    const form = V.$('#v-card-form'); if (!form) return null;
    return { fields: Object.fromEntries(new FormData(form)), checklists: JSON.stringify(V.collectChecklists()),
      attributes: V.$$('[data-attr]', form).map(input => [input.dataset.attr, input.type === 'checkbox' ? input.checked : input.value]),
      drafts: ['v-comment-text','v-message-body','v-message-subject','v-message-channel','v-interaction-kind','v-interaction-date'].map(id => V.$('#'+id)?.value ?? '') };
  };
  const stateKey = state => JSON.stringify([Object.entries(state.fields).sort(([a],[b]) => a.localeCompare(b)), state.checklists, state.attributes, state.drafts]);
  const dirty = () => V.cardBaseline && stateKey(readState()) !== stateKey(V.cardBaseline);
  const close = V.close;
  V.close = force => {
    if (V.$('#v-card-form') && V.cardBaseline) {
      if (V.checklistSaving && !force) { V.toast('Salvando o checklist. Aguarde um instante.'); return false; }
      V.modalDirty = !!dirty();
    }
    const result = close(force); if (result) V.cardBaseline = null; return result;
  };
  const summaryHTML = flow => `<div class="v-workflow-summary"><header><strong>${flow.enabled ? 'Planejamento dos checklists' : 'Planejamento automático desativado'}</strong>${flow.enabled ? '<span class="v-badge">ATIVO</span>' : ''}</header>${flow.enabled ? `<div class="v-workflow-dates"><span>Entrega final prevista<strong>${flow.planned_due_at ? V.date(flow.planned_due_at, true) : 'Calculada ao cadastrar e salvar os prazos'}</strong><small>Soma das etapas a partir do cadastro do cartão.</small></span><span>Etapa atual<strong>${e(flow.active?.text || 'Todos os passos concluídos ou ainda não iniciados')}</strong><small>${flow.active?.due_at ? 'Executar até ' + V.date(flow.active.due_at, true) : 'Sem prazo de execução definido'}</small></span></div><p class="v-muted v-small">Marcar um item salva os checklists e executa sua ação. Os demais dados do cartão continuam no botão Salvar alterações.</p>` : '<p class="v-muted v-small">Os checks e observações são salvos com o cartão. Ative o planejamento no quadro para calcular datas e executar ações ao marcar.</p>'}${V.cap('edit_cards') && !V.board.read_only && V.editingCard.id ? `<div class="v-inline-actions">${V.board.workflow?.enabled ? V.button('workflow-use-board','Aplicar planejamento atual do quadro','clock') : ''}${flow.enabled ? V.button('workflow-manual','Usar prazos manuais neste cartão','','v-link-btn') : ''}</div>` : ''}</div>`;
  V.renderWorkflowCard = () => {
    const root = V.$('#v-checklists'); if (!root) return;
    let summary = V.$('#v-workflow-card');
    if (!summary) { summary = document.createElement('div'); summary.id = 'v-workflow-card'; root.before(summary); }
    const flow = currentFlow(); summary.innerHTML = summaryHTML(flow);
    const due = V.$('#v-card-form [name="due_at"]');
    if (due) { due.readOnly = !!(flow.enabled && flow.sync_due && (flow.planned_due_at || V.collectChecklists().some(l => l.items.some(i => i.rule.amount > 0)))); due.title = due.readOnly ? 'Calculado pela soma dos prazos dos checklists.' : ''; }
    const completed = V.$('#v-card-form [name="completed"]');
    if (completed) { completed.disabled = !!(flow.enabled && flow.complete_card); completed.closest('label').title = completed.disabled ? 'Controlado automaticamente pela conclusão dos checklists.' : ''; }
  };
  V.saveChecklist = async (extra = {}) => {
    if (V.checklistSaving) return;
    const form = V.$('#v-card-form'), card = V.editingCard;
    if (!form || !card?.id) return;
    for (const input of V.$$('input,select,textarea', V.$('#v-checklists'))) {
      if (!input.checkValidity()) { input.closest('details')?.setAttribute('open',''); input.reportValidity(); throw new Error('Confira os campos dos checklists antes de concluir.'); }
    }
    V.checklistSaving = true;
    const disabled = [...V.$$('input,select,textarea,button', V.$('#v-checklists')), ...V.$$('[type="submit"], [data-action="workflow-use-board"], [data-action="workflow-manual"]', form)].map(input => [input, input.disabled]);
    disabled.forEach(([input]) => input.disabled = true);
    try {
      const saved = await V.api('cards/' + card.id, 'PATCH', { version: +card.version, checklists: V.collectChecklists(), ...extra });
      Object.assign(card, saved);
      if (V.screen === 'board' && +V.board.id === +card.board_id) {
        const index = V.cards.findIndex(c => +c.id === +card.id); if (index >= 0) { V.cards[index] = saved; V.placeCard(card.id, saved.list_id); V.syncCardElement(card.id); }
        const stats = V.$$('.v-stats strong');
        if (stats.length >= 3) { stats[1].textContent = V.cards.filter(c => V.deadline(c) && new Date(V.deadline(c)) < new Date() && !+c.completed).length; stats[2].textContent = V.cards.filter(c => +c.completed).length; }
      } else if (V.screen === 'dashboard') { V.refreshDashboard().catch(() => {}); }
      if (!form.isConnected) return;
      if (saved.activity) {
        V.$('#v-activity').innerHTML = V.activityHTML(saved.activity); V.activityPage = 1;
        V.$('[data-action="more-activity"]', form)?.remove();
        if (saved.activity_more) V.$('#v-activity').insertAdjacentHTML('afterend', V.button('more-activity','Carregar histórico anterior','','',`data-id="${card.id}"`));
      }
      const body = V.$('.v-modal-body'), scroll = body.scrollTop;
      const focused = document.activeElement?.closest('[data-item]'), focusedList = focused?.closest('[data-checklist]')?.dataset.checklist;
      const open = V.$$('[data-checklist]').flatMap(list => V.$$('[data-item] details[open]', list).map(detail => list.dataset.checklist + '/' + detail.closest('[data-item]').dataset.item));
      const openLists = V.$$('[data-checklist]').filter(list => V.$('.v-checklist-notes', list).open).map(list => list.dataset.checklist);
      V.$('#v-checklists').innerHTML = saved.checklists.map(V.checklistHTML).join('');
      for (const list of V.$$('[data-checklist]')) for (const item of V.$$('[data-item]', list)) if (open.includes(list.dataset.checklist+'/'+item.dataset.item)) V.$('details', item).open = true;
      for (const list of V.$$('[data-checklist]')) if (openLists.includes(list.dataset.checklist)) V.$('.v-checklist-notes', list).open = true;
      const fields = { due_at: V.localDateTime(saved.due_at), list_id: String(saved.list_id) };
      for (const [name,value] of Object.entries(fields)) { form.elements[name].value = value; V.cardBaseline.fields[name] = value; }
      form.elements.completed.checked = !!+saved.completed;
      V.renderWorkflowCard();
      if (+saved.completed && !form.elements.completed.disabled) V.cardBaseline.fields.completed = 'on'; else delete V.cardBaseline.fields.completed;
      V.cardBaseline.checklists = JSON.stringify(V.collectChecklists()); V.modalDirty = !!dirty(); body.scrollTop = scroll;
      if (focused && focusedList) V.$(`[data-checklist="${CSS.escape(focusedList)}"] [data-item="${CSS.escape(focused.dataset.item)}"] [data-item-done]`)?.focus({ preventScroll: true });
      V.toast('Checklist salvo. Prazos e ações atualizados.');
    } finally { V.checklistSaving = false; disabled.forEach(([input,state]) => { if (input.isConnected) input.disabled = state; }); }
  };
  V.actions['workflow-use-board'] = () => V.saveChecklist({ use_board_workflow: true });
  V.actions['workflow-manual'] = () => V.saveChecklist({ disable_workflow: true });
  const editCard = V.editCard;
  V.editCard = async card => {
    await editCard(card); V.renderWorkflowCard(); V.cardBaseline = readState();
    V.$('#v-checklists').addEventListener('change', async event => {
      if (!event.target.matches('[data-item-done]') || !V.editingCard.id || !currentFlow().enabled) return;
      const input = event.target, previous = !input.checked;
      try { await V.saveChecklist(); } catch (error) { if (input.isConnected) input.checked = previous; V.modalDirty = !!dirty(); V.toast(error.message, true); }
    });
    V.$('#v-card-form').addEventListener('submit', event => { if (V.checklistSaving) { event.preventDefault(); event.stopImmediatePropagation(); V.toast('Aguarde o checklist terminar de salvar.'); } }, true);
  };
})();

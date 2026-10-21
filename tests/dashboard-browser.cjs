/* Real dashboard interactions against disposable WordPress; external services are intercepted. */
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
if (process.env.VISITAS_TESTING !== '1' || !process.env.VISITAS_WP_ROOT) throw new Error('Set VISITAS_TESTING=1 and use a disposable WordPress.');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8100';
const output = process.env.VISITAS_SCREENSHOTS || '/tmp/visitas-dashboard-screenshots';
fs.mkdirSync(output, { recursive: true });
let server, browser, activePage, fixture;
(async () => {
  const args = process.env.VISITAS_PHP_INI ? ['-c', process.env.VISITAS_PHP_INI] : [];
  args.push('-d', 'auto_prepend_file=' + path.join(__dirname, 'mock-services.php'), '-S', new URL(base).host, '-t', process.env.VISITAS_WP_ROOT, path.join(__dirname, 'router.php'));
  server = spawn(process.env.VISITAS_PHP || 'php', args, { stdio: ['ignore', 'ignore', 'pipe'], env: process.env });
  server.stderr.on('data', d => { if (/Fatal error|Parse error/.test(String(d))) console.error(String(d)); });
  for (let i = 0; i < 40; i++) { try { await fetch(base + '/?visitas_app=1'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  const page = activePage = await context.newPage(), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  await page.goto(base + '/?visitas_app=1');
  await page.locator('input[name="login"]').fill(process.env.TEST_ADMIN_USER || 'admin');
  await page.locator('input[name="password"]').fill(process.env.TEST_ADMIN_PASSWORD || 'QA-Test-Password-2026');
  await page.locator('button[type="submit"]').click(); await page.locator('#v-kanban').waitFor();
  fixture = await page.evaluate(async () => {
    const V = window.V, suffix = Date.now().toString(), people = [], byMonth = [];
    const user = await V.api('users', 'POST', { name: 'Marina QA', login: 'qa_dashboard_' + suffix, email: 'marina-' + suffix + '@example.test', password: 'QA-Dashboard-Password-2026', roles: ['visitas_coordenacao'] });
    const board = await V.api('boards', 'POST', { name: 'Acolhimento de visitantes · QA', description: 'Um cuidado de cada vez, do primeiro encontro aos próximos passos.', seed: true });
    const names = ['Ana Souza', 'Bruno Lima', 'Carla Oliveira', 'Diego Santos', 'Elisa Costa', 'Felipe Rocha', 'Gabriela Alves', 'Henrique Martins', 'Isabela Ribeiro'];
    const counts = [3, 4, 5, 4, 7, 9];
    for (let m = 0; m < 6; m++) {
      const date = new Date(V.data.month + '-01T12:00:00'); date.setMonth(date.getMonth() - (5-m));
      const month = date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2, '0'); const cards = [];
      for (let n = 0; n < counts[m]; n++) {
        let card = await V.api('cards', 'POST', { board_id: +board.id, title: names[n] + (m === 5 ? '' : ' · mês ' + (m+1)), visit_date: month + '-01', phone: '+55419999999' + String(n).padStart(2, '0'), email: 'visitante-' + m + '-' + n + '@example.test', assigned_to: +user.id, description: 'Família interessada em conhecer um pequeno grupo e participar do próximo encontro.', source: n % 2 ? 'Convite de amigos' : 'Celebração de domingo', address: 'Bairro de teste, Curitiba' });
        people.push(+card.id);
        card = await V.api('cards/' + card.id, 'PATCH', { version: +card.version, list_id: +board.lists[m === 5 && n === 8 ? 3 : n % 4].id, completed: m === 5 && n === 8, ...(m === 5 && n === 4 ? { due_at: new Date(Date.now()-86400000).toISOString() } : {}) });
        cards.push(card);
        if (n < 2) await V.api('cards/' + card.id + '/visits', 'POST', { date: month + '-01', content: 'Conversa de acolhimento realizada. Combinamos um novo encontro com a equipe.' });
      }
      byMonth.push({ month, cards });
    }
    const current = byMonth[5], previous = byMonth[4];
    await V.api('cards/' + current.cards[0].id + '/visits', 'POST', { date: current.month + '-01', content: 'Segunda visita de acompanhamento à mesma pessoa.' });
    await V.api('cards/' + current.cards[2].id + '/comments', 'POST', { content: 'Contato por telefone. Retorno combinado com a família.' });
    await V.api('cards/' + previous.cards[2].id + '/visits', 'POST', { date: current.month + '-01', content: 'Acompanhamento atual de uma pessoa que chegou no mês anterior.' });
    await V.refresh();
    return { board, user, people, current, previous };
  });
  await page.locator('[data-action="dashboard"]').click();
  await page.locator('#v-dashboard-filters').waitFor();
  await page.locator('#v-dashboard-board').selectOption(String(fixture.board.id));
  await page.locator('#v-dashboard-filters button[type="submit"]').click();
  await page.waitForFunction(() => window.V.dashboardData?.filters.board_id === +document.querySelector('#v-dashboard-board')?.value && window.V.dashboardData?.totals.visitors === 9);
  assert.equal(await page.locator('[data-metric="visitors"]').textContent(), '9');
  assert.equal(await page.locator('[data-metric="accompanied"]').textContent(), '4');
  assert.equal(await page.locator('[data-metric="visited"]').textContent(), '4');
  assert.equal(await page.locator('[data-metric="overdue"]').textContent(), '1');
  assert.equal(await page.locator('.v-stage-row').count(), 4);
  assert.equal(await page.locator('.v-trend-month').count(), 6);
  await page.screenshot({ path: path.join(output, 'dashboard-desktop.png'), fullPage: true });
  console.log('PASS monthly visitor, accompanied-person, visit-record and overdue KPIs; custom steps and six-month chart');

  await page.locator(`.v-stage-row[data-list="${fixture.board.lists[1].id}"]`).click();
  await page.locator('.v-dashboard-person').first().waitFor();
  assert.equal(await page.locator('.v-dashboard-person').count(), 2);
  await page.locator('.v-dashboard-person').filter({ hasText: 'Bruno Lima' }).click();
  await page.locator('#v-dashboard-visit-form').waitFor();
  assert.ok((await page.locator('.v-visitor-overview').textContent()).includes('Família interessada'));
  assert.ok((await page.locator('.v-visitor-facts').textContent()).includes('Marina QA'));
  assert.ok((await page.locator('#v-dashboard-followups').textContent()).includes('Conversa de acolhimento realizada'));
  await page.locator('#v-dashboard-visit-form textarea').fill('Visita realizada: a família deseja participar do pequeno grupo. Retorno combinado para a próxima semana.');
  await page.locator('#v-dashboard-visit-form button[type="submit"]').click();
  await page.locator('#v-dashboard-followups .v-followup-record').first().filter({ hasText: 'Visita realizada: a família' }).waitFor();
  assert.equal(await page.locator('[data-metric="visited"]').textContent(), '5');
  assert.equal(await page.locator('[data-metric="accompanied"]').textContent(), '4');
  await page.screenshot({ path: path.join(output, 'visitor-desktop.png'), fullPage: true });
  await page.locator('[data-action="dashboard-back"]').click();
  await page.locator('.v-dashboard-person').first().waitFor();
  assert.equal(await page.locator('.v-dashboard-person').count(), 2);
  await page.locator('[data-action="close"]').click();
  console.log('PASS step drilldown opens visitor details, saves a dated visit, updates metrics and returns to the same list');

  await page.locator('.v-dashboard-metric-accompanied').click();
  await page.locator('#v-dashboard-search').fill(fixture.previous.cards[2].title);
  await page.waitForFunction(() => document.querySelectorAll('.v-dashboard-person').length === 1);
  await page.locator('.v-dashboard-person').click();
  await page.locator('#v-dashboard-visit-form').waitFor();
  assert.ok((await page.locator('.v-visitor-facts').textContent()).includes('01/' + fixture.previous.month.slice(5)));
  await page.locator('[data-action="dashboard-full-card"]').click();
  await page.locator('#v-card-form').waitFor();
  await page.locator('[data-action="card-tab"][data-tab="history"]').click();
  assert.ok((await page.locator('#v-activity').textContent()).includes('Visita realizada em'));
  await page.locator('#v-interaction-kind').selectOption('visit');
  await page.locator('#v-comment-text').fill('Nova observação de visita pelo cartão completo.');
  await page.locator('[data-action="save-comment"]').click();
  await page.locator('#v-activity .v-activity').first().filter({ hasText: 'Nova observação de visita pelo cartão completo.' }).waitFor();
  await page.locator('#v-card-form button[type="submit"]').click();
  await page.locator('.v-modal').waitFor({ state: 'detached' });
  await page.locator('#v-dashboard-filters').waitFor();
  assert.equal(await page.evaluate(() => window.V.screen), 'dashboard');
  assert.equal(await page.locator('#v-dashboard-board').inputValue(), String(fixture.board.id));
  console.log('PASS earlier visitors remain in current-month followup metrics; full card records visits and returns to dashboard');

  await page.locator('#v-dashboard-month').fill(fixture.previous.month);
  await page.locator('#v-dashboard-filters button[type="submit"]').click();
  await page.waitForFunction(() => document.querySelector('[data-metric="visitors"]')?.textContent === '7');
  await page.locator('#v-dashboard-all').check();
  await page.locator('#v-dashboard-filters button[type="submit"]').click();
  await page.waitForFunction(() => document.querySelector('[data-metric="visitors"]')?.textContent === '32');
  await page.locator('#v-dashboard-all').uncheck();
  await page.locator('#v-dashboard-month').fill(fixture.current.month);
  await page.locator('#v-dashboard-filters button[type="submit"]').click();
  await page.waitForFunction(() => document.querySelector('[data-metric="visitors"]')?.textContent === '9');
  await page.reload(); await page.locator('#v-dashboard-filters').waitFor();
  assert.equal(await page.locator('#v-dashboard-board').inputValue(), String(fixture.board.id));
  assert.equal(await page.locator('#v-dashboard-month').inputValue(), fixture.current.month);
  console.log('PASS month filter, all-month totals and refresh preserve selected board and period');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForFunction(() => getComputedStyle(document.querySelector('.v-sidebar')).transform.startsWith('matrix(1, 0, 0, 1, -245'));
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  await page.screenshot({ path: path.join(output, 'dashboard-mobile.png'), fullPage: true, animations: 'disabled' });
  await page.locator('.v-dashboard-metric-visitors').click();
  await page.locator('.v-dashboard-person').first().waitFor();
  await page.screenshot({ path: path.join(output, 'visitor-list-mobile.png'), fullPage: true });
  assert.equal(await page.locator('.v-modal').evaluate(el => el.scrollWidth <= el.clientWidth), true);
  await page.locator('.v-dashboard-person').first().click();
  await page.locator('#v-dashboard-visit-form').waitFor();
  await page.screenshot({ path: path.join(output, 'visitor-mobile.png'), fullPage: true });
  assert.equal(await page.locator('.v-modal-body').evaluate(el => el.scrollWidth <= el.clientWidth), true);
  await page.locator('#v-dashboard-visit-form textarea').fill('Visita registrada pelo celular. Acolhimento realizado e novo encontro combinado.');
  await page.locator('#v-dashboard-visit-form button[type="submit"]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(output, 'visitor-observations-mobile.png'), fullPage: true, animations: 'disabled' });
  await page.locator('#v-dashboard-visit-form button[type="submit"]').click();
  await page.locator('#v-dashboard-followups .v-followup-record').first().filter({ hasText: 'Visita registrada pelo celular.' }).waitFor();
  await page.locator('[data-action="close"]').click();
  console.log('PASS responsive dashboard, visitor lists and visitor details fit mobile; a visit is saved from mobile');

  // A completed visit task updates the dashboard even for an earlier visitor.
  const beforeVisitChecks = Number(await page.locator('[data-metric="visited"]').textContent());
  const visitTaskCard = fixture.previous.cards[6];
  await page.evaluate(async ({ board, id }) => {
    const V = window.V;
    V.board = await V.api('boards/' + board, 'PATCH', { workflow: { enabled: true, require_order: false } });
    const card = await V.api('cards/' + id);
    const updated = await V.api('cards/' + id, 'PATCH', { version: +card.version, use_board_workflow: true, checklists: [...card.checklists, { id: 'dashboard-visit', title: 'Visita pastoral', items: [{ id: 'done', text: 'Visita realizada', notes: 'Visita concluída pela equipe de acolhimento.' }] }] });
    await V.editCard(updated);
  }, { board: fixture.board.id, id: visitTaskCard.id });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.locator('[data-action="card-tab"][data-tab="checklists"]').click();
  await page.locator('[data-checklist="dashboard-visit"] [data-item-done]').check();
  await page.waitForFunction(expected => !window.V.checklistSaving && Number(document.querySelector('[data-metric="visited"]')?.textContent) === expected, beforeVisitChecks + 1);
  await page.locator('[data-action="close"]').first().click();
  await page.screenshot({ path: path.join(output, 'dashboard-checklist-visit-desktop.png'), fullPage: true, animations: 'disabled' });
  await page.locator('.v-dashboard-metric-visited').click();
  await page.locator(`.v-modal [data-action="dashboard-visitor"][data-id="${visitTaskCard.id}"]`).click();
  await page.locator('#v-dashboard-followups .v-followup-kind').filter({ hasText: 'Visita pelo checklist' }).waitFor();
  assert.ok((await page.locator('#v-dashboard-followups').textContent()).includes('Visita concluída pela equipe'));
  await page.locator('[data-action="dashboard-full-card"]').click();
  await page.locator('[data-action="card-tab"][data-tab="checklists"]').click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('[data-checklist="dashboard-visit"] [data-item-done]').uncheck();
  await page.waitForFunction(expected => !window.V.checklistSaving && Number(document.querySelector('[data-metric="visited"]')?.textContent) === expected, beforeVisitChecks);
  await page.locator('[data-checklist="dashboard-visit"] [data-item-done]').check();
  await page.waitForFunction(expected => !window.V.checklistSaving && Number(document.querySelector('[data-metric="visited"]')?.textContent) === expected, beforeVisitChecks + 1);
  await page.locator('#v-card-form button[type="submit"]').click();
  await page.locator('.v-modal').waitFor({ state: 'detached' });
  await page.waitForFunction(expected => Number(document.querySelector('[data-metric="visited"]')?.textContent) === expected, beforeVisitChecks + 1);
  assert.equal(await page.locator('#v-dashboard-month').inputValue(), fixture.current.month);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  await page.locator('.v-dashboard-metric-visited').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(output, 'dashboard-checklist-visit-mobile.png'), fullPage: true, animations: 'disabled' });
  assert.ok(await page.evaluate(() => window.V.followupRecordsHTML([{ kind: 'checklist_visit', content: 'Check anterior', created_at: '2026-10-01 12:00:00', occurred_at: null, user_id: 0 }]).includes('Data de conclusão não registrada')));
  console.log('PASS visit task checks update the KPI, drilldown and history; mobile uncheck/recheck and repeated saves keep one visit with the same monthly filter');

  const anon = await browser.newContext();
  const response = await anon.request.get(base + '/?rest_route=/visitas/v1/dashboard'); assert.equal(response.status(), 403);
  const noNonce = await context.request.get(base + '/?rest_route=/visitas/v1/dashboard'); assert.equal(noNonce.status(), 403);
  await anon.close();
  assert.deepEqual(errors, []);
  console.log('PASS anonymous requests and sessions without nonce are denied; no browser JavaScript errors');
  await page.evaluate(async fixture => {
    for (const id of fixture.people) await window.V.api('cards/' + id, 'DELETE');
    await window.V.api('boards/' + fixture.board.id, 'DELETE');
  }, fixture);
})().catch(async error => { if (activePage) await activePage.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {}); console.error(error); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close(); if (server) server.kill('SIGTERM');
});

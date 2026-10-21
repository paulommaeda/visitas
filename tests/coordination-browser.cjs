/* Coordination read-only screens and owned task completion, on disposable WordPress. */
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
if (process.env.VISITAS_TESTING !== '1' || !process.env.VISITAS_WP_ROOT) throw new Error('Use a disposable VISITAS_WP_ROOT and VISITAS_TESTING=1.');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8100', output = process.env.VISITAS_SCREENSHOTS || '/tmp/visitas-coordination-screenshots';
fs.mkdirSync(output, { recursive: true }); let server, browser, activePage;
const login = async (page, user, password) => {
  await page.goto(base + '/?visitas_app=1'); await page.locator('[name="login"]').fill(user); await page.locator('[name="password"]').fill(password); await page.locator('button[type="submit"]').click(); await page.locator('#v-kanban').waitFor();
};
(async () => {
  const args = process.env.VISITAS_PHP_INI ? ['-c', process.env.VISITAS_PHP_INI] : [];
  args.push('-d', 'auto_prepend_file=' + path.join(__dirname, 'mock-services.php'), '-S', new URL(base).host, '-t', process.env.VISITAS_WP_ROOT, path.join(__dirname, 'router.php'));
  server = spawn(process.env.VISITAS_PHP || 'php', args, { stdio: ['ignore','ignore','pipe'], env: process.env });
  server.stderr.on('data', data => { if (/Fatal error|Parse error/.test(String(data))) console.error(String(data)); });
  for (let n=0;n<40;n++) { try { await fetch(base+'/?visitas_app=1'); break; } catch { await new Promise(r=>setTimeout(r,100)); } }
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true, args: ['--no-sandbox'] });
  const adminContext = await browser.newContext({ viewport: { width:1440,height:1000 }, locale:'pt-BR', timezoneId:'America/Sao_Paulo' });
  const admin = activePage = await adminContext.newPage(); const errors=[];
  admin.on('pageerror', error=>errors.push(error.message)); admin.on('dialog', dialog=>dialog.accept());
  await login(admin, process.env.TEST_ADMIN_USER || 'admin', process.env.TEST_ADMIN_PASSWORD || 'QA-Test-Password-2026');
  const fixture = await admin.evaluate(async () => {
    const V=window.V, suffix=Date.now().toString(), password='QA-Coordination-Password-2026';
    const user=await V.api('users','POST',{name:'Coordenação QA',login:'qa_coord_'+suffix,email:'coord-'+suffix+'@example.test',password,roles:['visitas_coordenacao']});
    const other=await V.api('users','POST',{name:'Recepção QA',login:'qa_reception_'+suffix,email:'reception-'+suffix+'@example.test',password,roles:['visitas_recepcao']});
    let board=await V.api('boards','POST',{name:'Acompanhamento · Coordenação QA',seed:true});
    const templates=[{id:'care',title:'Cuidados com o visitante',items:[
      {id:'mine',text:'Visita realizada',assigned_to:+user.id,notes:'',rule:{amount:3,unit:'business_days',target_list_id:+board.lists[1].id}},
      {id:'other',text:'Primeiro contato da recepção',assigned_to:+other.id,rule:{amount:3,unit:'business_days'}},
      {id:'unassigned',text:'Encaminhar ao pequeno grupo',rule:{amount:3,unit:'business_days'}},
      {id:'completed',text:'Conferência anterior concluída',assigned_to:+user.id}
    ]}];
    board=await V.api('boards/'+board.id,'PATCH',{templates,workflow:{enabled:true,require_order:false},members:[{user_id:+user.id,role:'manager'}]});
    const lists=structuredClone(board.templates);lists[0].items[0].notes='Orientações já registradas pela gestão.';lists[0].items[3].done=true;
    const card=await V.api('cards','POST',{board_id:+board.id,title:'Visitante da coordenação',assigned_to:V.data.user.id,checklists:lists});
    const manual=await V.api('cards','POST',{board_id:+board.id,title:'Tarefa sem automação',disable_workflow:true,checklists:[{id:'manual',title:'Acompanhamento manual',items:[{id:'mine',text:'Confirmar acolhimento',assigned_to:+user.id}]}]});
    await V.refresh(); return {board,card,manual,login:'qa_coord_'+suffix,password,user:+user.id};
  });
  await admin.evaluate(()=>window.V.actions.roles()); await admin.locator('#v-role-picker').selectOption('visitas_coordenacao');
  assert.equal(await admin.locator('[name="cap_manage_boards"]').isDisabled(),true); assert.equal(await admin.locator('[name="cap_edit_cards"]').isDisabled(),true);
  assert.equal(await admin.locator('[name="cap_complete_assigned_tasks"]').isDisabled(),false); assert.equal(await admin.locator('[name="cap_complete_assigned_tasks"]').isChecked(),true);
  await admin.screenshot({path:path.join(output,'coordination-role-desktop.png'),fullPage:true,animations:'disabled'}); await admin.evaluate(()=>window.V.close(true));
  const context=await browser.newContext({viewport:{width:1440,height:1000},locale:'pt-BR',timezoneId:'America/Sao_Paulo'});
  const page=activePage=await context.newPage(), dialogs=[];page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>{dialogs.push(dialog.message());dialog.accept();});
  await login(page,fixture.login,fixture.password); await page.evaluate(async id=>window.V.openBoard(id),fixture.board.id);
  for (const action of ['board-settings','new-card','new-board','new-list','users','settings']) assert.equal(await page.locator(`[data-action="${action}"]`).count(),0,action+' is not offered to coordination');
  assert.equal(await page.locator('.v-card[draggable="true"]').count(),0); assert.equal(await page.locator('#wpadminbar').count(),0);
  await page.locator(`[data-card="${fixture.card.id}"] .v-card-open`).click();
  assert.equal(await page.locator('[name="due_at"]').isDisabled(),true); assert.equal(await page.locator('[name="assigned_to"]').isDisabled(),true); assert.equal(await page.locator('[name="title"]').isDisabled(),true);
  assert.equal(await page.locator('#v-card-form [type="submit"]').count(),0);
  await page.locator('[data-action="card-tab"][data-tab="checklists"]').click();
  const mine=()=>page.locator('[data-item="mine"] [data-item-done]');
  assert.equal(await mine().isDisabled(),false);
  for(const id of ['other','unassigned','completed']) assert.equal(await page.locator(`[data-item="${id}"] [data-item-done]`).isDisabled(),true);
  assert.equal(await page.locator('[data-item="mine"] [data-rule-amount]').evaluate(el=>el.readOnly),true);
  assert.equal(await page.locator('[data-item="mine"] [data-item-notes]').evaluate(el=>el.readOnly),true);
  assert.equal(await page.locator('[data-item="mine"] [data-item-assigned]').isDisabled(),true);
  assert.equal(await page.locator('[data-item="other"] [data-item-text]').evaluate(el=>el.readOnly),true);
  for(const action of ['add-checklist','add-checkitem','remove-checklist','remove-checkitem','workflow-order','apply-template']) assert.equal(await page.locator(`[data-action="${action}"]:visible`).count(),0);
  assert.equal(await page.locator('[data-action="workflow-use-board"],[data-action="workflow-manual"]').count(),0);
  await mine().scrollIntoViewIfNeeded(); await page.screenshot({path:path.join(output,'coordination-tasks-desktop.png'),fullPage:true,animations:'disabled'});
  console.log('PASS coordination has read-only visitor data, models, deadlines, owners and other tasks; only its pending checks are enabled');
  const match=url=>(url.searchParams.get('rest_route')||url.pathname).endsWith('/visitas/v1/cards/'+fixture.card.id+'/tasks/complete');
  let release;const gate=new Promise(resolve=>release=resolve), sent=[];
  const held=async route=>{sent.push({method:route.request().method(),body:route.request().postDataJSON()});await gate;await route.continue();};await page.route(match,held);
  await mine().check();await page.waitForFunction(()=>window.V.checklistSaving);assert.equal(await mine().isDisabled(),true);release();
  await page.waitForFunction(()=>!window.V.checklistSaving&&window.V.editingCard.checklists[0].items[0].done);await page.unroute(match,held);
  assert.equal(sent.length,1);assert.equal(sent[0].method,'POST');assert.deepEqual(Object.keys(sent[0].body).sort(),['checklist_id','item_id','version']);assert.equal(sent[0].body.item_id,'mine');
  assert.equal(await mine().isDisabled(),true);const saved=await page.evaluate(()=>window.V.editingCard);
  assert.equal(saved.due_at,fixture.card.due_at);assert.equal(+saved.list_id,+fixture.board.lists[1].id);assert.equal(+saved.checklists[0].items[0].completed_by,fixture.user);assert.equal(saved.checklists[0].items[0].notes,'Orientações já registradas pela gestão.');
  assert.equal(await page.locator('[data-item="other"] [data-item-done]').isChecked(),false);
  await page.locator('[data-action="close"]').first().click();await page.locator('.v-modal').waitFor({state:'detached'});assert.deepEqual(dialogs,[],'Completing a task has no false unsaved-change confirmation');
  console.log('PASS one scoped completion request preserves deadlines and other task data, applies the configured hook and prevents reopening');
  await page.setViewportSize({width:390,height:844});await page.locator(`[data-card="${fixture.manual.id}"] .v-card-open`).click();await page.locator('[data-action="card-tab"][data-tab="checklists"]').click();
  const manualMatch=url=>(url.searchParams.get('rest_route')||url.pathname).endsWith('/visitas/v1/cards/'+fixture.manual.id+'/tasks/complete');
  const reject=route=>route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({message:'Falha simulada ao concluir tarefa.'})});await page.route(manualMatch,reject);
  await mine().check();await page.locator('#visitas-toast').filter({hasText:'Falha simulada'}).waitFor();assert.equal(await mine().isChecked(),false);assert.equal(await mine().isDisabled(),false);await page.unroute(manualMatch,reject);
  await mine().check();await page.waitForFunction(()=>!window.V.checklistSaving&&window.V.editingCard.checklists[0].items[0].done);assert.equal(await mine().isDisabled(),true);
  await mine().scrollIntoViewIfNeeded();assert.equal(await page.locator('.v-modal-body').evaluate(el=>el.scrollWidth<=el.clientWidth),true);await page.screenshot({path:path.join(output,'coordination-tasks-mobile.png'),fullPage:true,animations:'disabled'});
  await page.locator('[data-action="close"]').first().click();assert.deepEqual(dialogs,[]);
  await page.evaluate(()=>window.V.openDashboard({month:window.V.data.month,board_id:window.V.board.id,include_archived:false}));await page.waitForFunction(()=>document.querySelector('[data-metric="visited"]')?.textContent==='1');
  await page.locator('.v-dashboard-metric-visited').click();await page.locator('.v-dashboard-person').first().click();await page.locator('#v-dashboard-followups').waitFor();assert.equal(await page.locator('#v-dashboard-visit-form').count(),0);
  await page.locator('[data-action="close"]').first().click();
  console.log('PASS mobile task completion works without automatic planning, rolls back a failed save, and keeps dashboard visits and observations read-only');
  const missingNonce=await context.request.post(base+'/?rest_route=/visitas/v1/cards/'+fixture.card.id+'/tasks/complete',{data:{version:saved.version,checklist_id:'care',item_id:'mine'}});assert.equal(missingNonce.status(),403);
  const wpAdmin=await context.request.get(base+'/wp-admin/',{maxRedirects:0});assert.equal(wpAdmin.status(),302);assert.ok(!wpAdmin.headers().location.includes('wp-admin'));
  assert.deepEqual(errors,[]);console.log('PASS missing nonce is denied; coordination cannot access wp-admin; no JavaScript errors');
  await admin.evaluate(async fixture=>{for(const id of [fixture.card.id,fixture.manual.id])await window.V.api('cards/'+id,'DELETE');await window.V.api('boards/'+fixture.board.id,'DELETE');},fixture);
})().catch(async error=>{if(activePage)await activePage.screenshot({path:path.join(output,'failure.png'),fullPage:true}).catch(()=>{});console.error(error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();if(server)server.kill('SIGTERM');});

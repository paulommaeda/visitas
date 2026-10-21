<?php
/** Dashboard metrics, permissions and local month boundaries, in a disposable WordPress. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT')) { fwrite(STDERR, "Use VISITAS_TESTING=1 and a disposable VISITAS_WP_ROOT.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'), '/').'/wp-load.php';
use Shalom\Visitas\{Access, Store, Settings};
$passed = 0; $boards = []; $users = [];
function expect_dashboard($condition, string $label): void { global $passed; if (!$condition) { throw new RuntimeException('FAIL: '.$label); } $passed++; echo 'PASS '.$label.PHP_EOL; }
function dashboard_request(string $method, string $route, array $body = [], int $status = 200): array {
    [$path, $query] = array_pad(explode('?', $route, 2), 2, ''); parse_str($query, $params);
    $request = new WP_REST_Request($method, '/visitas/v1/'.$path); $request->set_query_params($params);
    $request->set_header('content-type', 'application/json'); $request->set_body(wp_json_encode($body));
    $response = rest_do_request($request);
    expect_dashboard($response->get_status() === $status, "$method $path HTTP $status (got {$response->get_status()})");
    return $response->get_data();
}
add_filter('pre_wp_mail', '__return_true', 2000);
add_filter('pre_http_request', static fn()=>new WP_Error('qa_no_http', 'External HTTP disabled'), 2000);
$original_settings = get_option('visitas_settings'); $original_zone = get_option('timezone_string'); $original_version = get_option('visitas_version');
$coord_role = get_role('visitas_coordenacao'); $original_cap = $coord_role->capabilities['visitas_view_dashboard'] ?? null;
$admin = get_users(['role'=>'administrator', 'number'=>1])[0]->ID;
try {
    update_option('timezone_string', 'America/Sao_Paulo');
    update_option('visitas_settings', array_merge(Settings::get(), ['email_enabled'=>false, 'sms_enabled'=>false, 'welcome_enabled'=>false]));
    wp_set_current_user($admin);
    $suffix = substr(wp_generate_uuid4(), 0, 8);
    add_role('visitas_dashboard_test', 'Dashboard scoped test', ['read'=>true, 'visitas_access'=>true, 'visitas_view_dashboard'=>true, 'visitas_comment'=>true, 'visitas_edit_cards'=>true]);
    foreach (['coord'=>'visitas_coordenacao', 'observer'=>'visitas_observador', 'scoped'=>'visitas_dashboard_test'] as $name=>$role) {
        $id = wp_insert_user(['user_login'=>'qa_dashboard_'.$name.'_'.$suffix, 'user_pass'=>wp_generate_password(30), 'role'=>$role]);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $users[$name] = $id;
    }
    $bid = Store::create_board(['name'=>'Dashboard QA '.$suffix, 'seed'=>true]); $boards[] = $bid;
    $private = Store::create_board(['name'=>'Private dashboard QA '.$suffix, 'visibility'=>'private']); $boards[] = $private;
    $empty = Store::create_board(['name'=>'Empty dashboard QA '.$suffix]); $boards[] = $empty;
    $lists = Store::lists($bid);
    $start = new DateTimeImmutable(wp_date('Y-m').'-01', wp_timezone());
    $month = $start->format('Y-m'); $first = $start->format('Y-m-d');
    $previous = $start->modify('-1 month')->format('Y-m-d');
    $next = $start->modify('+1 month')->format('Y-m-d');
    $make = static function($title, $date, $more = []) use($bid) { return Store::save_card(array_merge(['board_id'=>$bid, 'title'=>$title, 'visit_date'=>$date], $more)); };
    $ana = $make('Ana Dashboard', $first, ['assigned_to'=>$users['scoped'], 'description'=>'Observações do cadastro.']);
    $bruno = $make('Bruno Dashboard', $first); Store::update('cards', ['list_id'=>$lists[1]['id'], 'due_at'=>gmdate('Y-m-d H:i:s', time()-3600)], ['id'=>$bruno['id']]);
    $carla = $make('Carla Dashboard', $first, ['completed'=>true]); Store::update('cards', ['list_id'=>$lists[3]['id'], 'due_at'=>gmdate('Y-m-d H:i:s', time()-3600)], ['id'=>$carla['id']]);
    $diego = $make('Diego anterior', $previous);
    $eva = $make('Eva arquivada', $first, ['archived'=>true]);
    $frida = $make('Frida sem data', ''); Store::update('cards', ['created_at'=>$first.' 03:00:00'], ['id'=>$frida['id']]);
    $gabriel = $make('Gabriel limite UTC', ''); Store::update('cards', ['created_at'=>$first.' 01:00:00'], ['id'=>$gabriel['id']]);
    $helena = $make('Helena próximo mês', ''); Store::update('cards', ['created_at'=>$next.' 03:00:00'], ['id'=>$helena['id']]);
    $secret = Store::save_card(['board_id'=>$private, 'title'=>'Segredo privado', 'visit_date'=>$first]);
    Store::activity($ana['id'], 'comment', 'Contato no mês.');
    Store::activity($ana['id'], 'visit', 'Primeira visita de acompanhamento.', $first);
    Store::activity($ana['id'], 'visit', 'Segunda visita à mesma pessoa.', $first);
    Store::activity($ana['id'], 'visit', 'Visita no mês anterior.', $previous);
    Store::activity($bruno['id'], 'comment', 'Interação no mês.');
    Store::activity($diego['id'], 'visit', 'Chegou antes e recebeu visita neste mês.', $first);
    Store::activity($eva['id'], 'visit', 'Visita de pessoa arquivada.', $first);
    Store::activity($secret['id'], 'visit', 'Registro privado.', $first);
    // UTC beginning of month is still the previous local month in São Paulo.
    $boundary = Store::insert('activity', ['card_id'=>$bruno['id'], 'user_id'=>$admin, 'kind'=>'comment', 'content'=>'Limite do mês local.', 'occurred_on'=>null, 'created_at'=>$first.' 01:00:00']);
    $query = "month=$month&board_id=$bid";
    wp_set_current_user(0); dashboard_request('GET', 'dashboard?'.$query, [], 403);
    wp_set_current_user($users['observer']); dashboard_request('GET', 'dashboard?'.$query, [], 403); dashboard_request('GET', 'dashboard/visitors?'.$query, [], 403);
    wp_set_current_user($users['coord']);
    $d = dashboard_request('GET', 'dashboard?'.$query);
    expect_dashboard($d['totals']['visitors'] === 4, 'Month counts first-visit dates and local-time fallback correctly');
    expect_dashboard($d['totals']['accompanied'] === 3, 'Accompanied counts people once, including earlier arrivals');
    expect_dashboard($d['totals']['visits'] === 3 && $d['totals']['visited_people'] === 2, 'Visit records and distinct visited people are separate');
    expect_dashboard($d['totals']['overdue'] === 1 && $d['totals']['completed'] === 1, 'Completed people do not count as overdue');
    expect_dashboard(array_sum(array_column($d['stages'], 'total')) === 4, 'Step counts add up to the visitor cohort');
    expect_dashboard($d['stages'][0]['total'] === 2 && $d['stages'][1]['total'] === 1 && $d['stages'][3]['total'] === 1, 'Free-form list positions are counted separately');
    expect_dashboard(count($d['trend']) === 6 && $d['trend'][5]['visitors'] === 4 && $d['trend'][5]['accompanied'] === 3, 'Six-month chart uses the same metric definitions');
    expect_dashboard($d['trend'][4]['visitors'] === 2 && $d['trend'][4]['accompanied'] === 2, 'Month boundaries use the WordPress timezone for legacy timestamps');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query);
    expect_dashboard($v['total'] === 4 && !in_array('Diego anterior', array_column($v['items'], 'title')), 'Visitor drilldown matches its KPI');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&group=accompanied');
    expect_dashboard($v['total'] === 3 && in_array('Diego anterior', array_column($v['items'], 'title')), 'Accompanied drilldown includes earlier arrivals and deduplicates repeated visits');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&group=visited'); expect_dashboard($v['total'] === 2, 'Visit drilldown lists distinct people');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&group=overdue'); expect_dashboard($v['total'] === 1 && $v['items'][0]['id'] == $bruno['id'], 'Overdue drilldown matches its KPI');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&list_id='.$lists[0]['id']); expect_dashboard($v['total'] === 2, 'Step drilldown matches the chart');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&q=Ana'); expect_dashboard($v['total'] === 1, 'Visitor search filters results');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&q=%25'); expect_dashboard($v['total'] === 0, 'Search escapes wildcard characters');
    $d = dashboard_request('GET', 'dashboard?'.$query.'&include_archived=1'); expect_dashboard($d['totals']['visitors'] === 5 && $d['totals']['accompanied'] === 4, 'Archived visitors are included only when requested');
    dashboard_request('GET', 'dashboard?month=invalid', [], 422);
    dashboard_request('GET', 'dashboard?month=2026-13', [], 422);
    dashboard_request('GET', 'dashboard?board_id=-1', [], 422);
    dashboard_request('GET', 'dashboard/visitors?'.$query.'&group=unknown', [], 422);
    dashboard_request('GET', 'dashboard?month='.$month.'&board_id='.$private, [], 403);
    dashboard_request('GET', 'dashboard/visitors?month='.$month.'&list_id='.Store::lists($private)[0]['id'], [], 403);
    $d = dashboard_request('GET', 'dashboard?month='.$month);
    expect_dashboard(!in_array($private, array_column($d['stages'], 'board_id')) && !in_array('Segredo privado', array_column($d['recent'], 'title')), 'All-board aggregates and recent records do not leak private boards');
    $d = dashboard_request('GET', 'dashboard?month='.$month.'&board_id='.$empty);
    expect_dashboard($d['totals']['visitors'] === 0 && $d['totals']['accompanied'] === 0 && $d['stages'][0]['total'] === 0, 'Empty states return zeros without dropping the list');
    wp_set_current_user($users['scoped']);
    $d = dashboard_request('GET', 'dashboard?'.$query);
    expect_dashboard($d['totals']['visitors'] === 1 && $d['totals']['accompanied'] === 1 && $d['totals']['visits'] === 2, 'Dashboard permission does not bypass assigned-card scope');
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query); expect_dashboard($v['total'] === 1 && $v['items'][0]['id'] == $ana['id'], 'Drilldown uses the same assigned-card scope');
    dashboard_request('GET', 'cards/'.$bruno['id'].'/followups', [], 403);
    dashboard_request('POST', 'cards/'.$bruno['id'].'/visits', ['date'=>$first, 'content'=>'Forbidden'], 403);
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>$first, 'content'=>'Observações salvas na visita.']);
    $records = dashboard_request('GET', 'cards/'.$ana['id'].'/followups');
    expect_dashboard($records['items'][0]['occurred_on'] === $first && $records['items'][0]['kind'] === 'visit' && $records['can_comment'], 'Visit saves occurrence date, author and observations independently from registration time');
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>$next, 'content'=>'Future visit'], 422);
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>'2026-02-30', 'content'=>'Invalid date'], 422);
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>$first, 'content'=>'   '], 422);
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['content'=>'Missing date'], 422);
    wp_set_current_user($users['observer']);
    $records = dashboard_request('GET', 'cards/'.$ana['id'].'/followups'); expect_dashboard(!$records['can_comment'], 'Read-only visitor history has no write permission');
    dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>$first, 'content'=>'Observer write'], 403);
    wp_set_current_user($admin);
    Store::insert('members', ['board_id'=>$bid, 'user_id'=>$users['scoped'], 'role'=>'viewer']);
    wp_set_current_user($users['scoped']); dashboard_request('POST', 'cards/'.$ana['id'].'/visits', ['date'=>$first, 'content'=>'Member read-only'], 403);
    wp_set_current_user($admin);
    for ($i=0; $i<25; $i++) { $make('Página QA '.str_pad((string)$i, 2, '0', STR_PAD_LEFT), $first); Store::activity($ana['id'], 'comment', 'Histórico paginado '.$i); }
    $v = dashboard_request('GET', 'dashboard/visitors?'.$query.'&q='.rawurlencode('Página QA'));
    $nextpage = dashboard_request('GET', 'dashboard/visitors?'.$query.'&q='.rawurlencode('Página QA').'&page=2');
    expect_dashboard($v['total'] === 25 && count($v['items']) === 20 && $v['has_more'] && count($nextpage['items']) === 5 && !$nextpage['has_more'], 'Visitor search paginates complete results in the server');
    expect_dashboard(!array_intersect(array_column($v['items'], 'id'), array_column($nextpage['items'], 'id')), 'Visitor pages do not repeat people');
    $records = dashboard_request('GET', 'cards/'.$ana['id'].'/followups');
    $older = dashboard_request('GET', 'cards/'.$ana['id'].'/followups?page=2');
    expect_dashboard(count($records['items']) === 20 && $records['has_more'] && count($older['items']) > 0, 'Visitor modal can load previous observations');
    expect_dashboard(!array_intersect(array_column($records['items'], 'id'), array_column($older['items'], 'id')), 'Observation pages do not repeat records');
    $coord_role->remove_cap('visitas_view_dashboard'); update_option('visitas_version', '1.0.2'); Access::install();
    expect_dashboard(get_role('visitas_coordenacao')->has_cap('visitas_view_dashboard'), 'Existing coordination roles gain the new permission on upgrade');
    get_role('visitas_coordenacao')->add_cap('visitas_view_dashboard', false); Access::install();
    expect_dashboard(!get_role('visitas_coordenacao')->has_cap('visitas_view_dashboard'), 'An explicit denial is preserved during migration');
    update_option('visitas_version', '1.1.0'); get_role('visitas_coordenacao')->remove_cap('visitas_view_dashboard'); Access::install();
    expect_dashboard(!get_role('visitas_coordenacao')->has_cap('visitas_view_dashboard'), 'Later activations preserve edited dashboard permissions');
    echo "\n$passed dashboard assertions passed. No real messages sent.\n";
} finally {
    wp_set_current_user($admin); global $wpdb;
    foreach ($boards as $bid) {
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d', $bid)));
        if ($ids) { foreach (['activity','notifications'] as $table) { $wpdb->query('DELETE FROM '.Store::table($table).' WHERE card_id IN ('.implode(',', $ids).')'); } }
        foreach (['cards','members','lists'] as $table) { $wpdb->delete(Store::table($table), ['board_id'=>$bid]); }
        $wpdb->delete(Store::table('boards'), ['id'=>$bid]);
    }
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $id) { wp_delete_user($id); }
    remove_role('visitas_dashboard_test');
    if ($original_cap === null) { get_role('visitas_coordenacao')->remove_cap('visitas_view_dashboard'); } else { get_role('visitas_coordenacao')->add_cap('visitas_view_dashboard', $original_cap); }
    update_option('visitas_settings', $original_settings); update_option('timezone_string', $original_zone); update_option('visitas_version', $original_version);
}

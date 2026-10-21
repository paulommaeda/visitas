<?php
/** Task ownership and access against an isolated WordPress; all delivery is blocked. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT')) { fwrite(STDERR, "Use a disposable WordPress with VISITAS_TESTING=1.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'), '/').'/wp-load.php';
use Shalom\Visitas\{Access, Store, Settings};
$passed = 0; $boards = []; $users = [];
function ta_check($ok, string $message): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } $passed++; echo 'PASS '.$message.PHP_EOL; }
function ta_api(string $method, string $route, array $body = [], int $status = 200): array {
    [$path, $query] = array_pad(explode('?', $route, 2), 2, ''); parse_str($query, $params);
    $r = new WP_REST_Request($method, '/visitas/v1/'.$path); $r->set_query_params($params); $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body));
    $response = rest_do_request($r); ta_check($response->get_status() === $status, "$method $path returns $status (got {$response->get_status()})"); return $response->get_data();
}
function ta_patch(array $card, array $body, int $status = 200): array { return ta_api('PATCH', 'cards/'.$card['id'], array_merge(['version'=>(int)$card['version']], $body), $status); }
add_filter('pre_wp_mail', '__return_true', 2000);
add_filter('pre_http_request', static fn()=>new WP_Error('qa_blocked', 'External HTTP blocked'), 2000);
$saved = get_option('visitas_settings'); $admin = (int)get_users(['role'=>'administrator', 'number'=>1])[0]->ID;
try {
    wp_set_current_user($admin);
    update_option('visitas_settings', array_merge(Settings::get(), ['welcome_enabled'=>false, 'assignment_enabled'=>false, 'due_enabled'=>false, 'email_enabled'=>false, 'sms_enabled'=>false]));
    foreach (['worker'=>'visitas_acompanhamento', 'second'=>'visitas_acompanhamento', 'outside'=>'visitas_acompanhamento', 'viewer'=>'visitas_recepcao'] as $key=>$role) {
        $id = wp_insert_user(['user_login'=>'qa_task_'.$key.'_'.wp_generate_password(8, false), 'user_pass'=>wp_generate_password(30), 'display_name'=>'QA Task '.$key, 'role'=>$role]);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $users[$key] = (int)$id;
    }
    get_userdata($users['worker'])->add_cap('visitas_view_dashboard');
    $b = ta_api('POST', 'boards', ['name'=>'Tasks QA '.wp_generate_uuid4(), 'visibility'=>'private', 'seed'=>true]); $bid = (int)$b['id']; $boards[] = $bid;
    $members = [['user_id'=>$users['worker'], 'role'=>'editor'], ['user_id'=>$users['second'], 'role'=>'editor'], ['user_id'=>$users['viewer'], 'role'=>'viewer']];
    $b = ta_api('PATCH', 'boards/'.$bid, ['members'=>$members]);
    $choices = array_column($b['assignees'], 'id');
    ta_check(in_array($users['worker'], $choices, true) && !in_array($users['outside'], $choices, true) && !in_array($users['viewer'], $choices, true), 'Only eligible editors appear in a private board task picker');
    $templates = [['id'=>'care', 'title'=>'Acolhimento', 'items'=>[
        ['id'=>'first', 'text'=>'Primeiro contato', 'assigned_to'=>$users['worker'], 'rule'=>['amount'=>3, 'unit'=>'business_days']],
        ['id'=>'second', 'text'=>'Visita', 'assigned_to'=>$users['worker'], 'rule'=>['amount'=>3, 'unit'=>'business_days']],
    ]]];
    $b = ta_api('PATCH', 'boards/'.$bid, ['templates'=>$templates, 'workflow'=>['enabled'=>true]]);
    $card = ta_api('POST', 'cards', ['board_id'=>$bid, 'title'=>'Visitante com tarefas', 'assigned_to'=>$admin]);
    $cid = (int)$card['id']; $forecast = $card['due_at'];
    ta_check($card['checklists'][0]['items'][0]['assigned_to'] === $users['worker'] && $card['workflow']['active']['assigned_to'] === $users['worker'], 'Models copy task owners and expose the active task owner');
    global $wpdb;
    ta_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('task_assignees').' WHERE card_id=%d', $cid)) === 1, 'Multiple tasks for one person produce one access index entry');
    wp_set_current_user($users['worker']);
    ta_api('GET', 'cards/'.$cid);
    $list = ta_api('GET', 'boards/'.$bid.'/cards'); ta_check($list['total'] === 1 && (int)$list['cards'][0]['id'] === $cid, 'Task owner can list the card without being its general owner');
    $bootstrap = ta_api('GET', 'bootstrap'); $visible = array_values(array_filter($bootstrap['boards'], static fn($board)=>(int)$board['id'] === $bid));
    ta_check((int)$visible[0]['stats']['total'] === 1, 'Board statistics include task-assigned cards');
    $dashboard = ta_api('GET', 'dashboard?month=all&board_id='.$bid); ta_check($dashboard['totals']['visitors'] === 1, 'Dashboard includes a task-assigned card exactly once');
    $visitors = ta_api('GET', 'dashboard/visitors?month=all&board_id='.$bid); ta_check($visitors['total'] === 1, 'Dashboard drilldown uses the same task access scope');
    $items = $card['checklists']; $items[0]['items'][0]['done'] = true; $items[0]['items'][0]['notes'] = 'Contato realizado.';
    $card = ta_patch($card, ['checklists'=>$items, 'assigned_to'=>$admin]);
    $completed_at = $card['checklists'][0]['items'][0]['completed_at'];
    ta_check($card['assigned_to'] == $admin && $card['due_at'] === $forecast && $card['checklists'][0]['items'][0]['completed_by'] === $users['worker'], 'Task worker completes the task without changing general owner or forecast');
    $bad = $card['checklists']; $bad[0]['items'][1]['assigned_to'] = $users['second']; ta_patch($card, ['checklists'=>$bad], 403);
    wp_set_current_user($users['outside']); ta_api('GET', 'cards/'.$cid, [], 403);
    wp_set_current_user($admin);
    foreach ([$users['outside'], $users['viewer'], 999999999, -1, 'not-a-user', []] as $invalid) {
        $bad = $card['checklists']; $bad[0]['items'][1]['assigned_to'] = $invalid; ta_patch($card, ['checklists'=>$bad], 422);
    }
    $unchanged = ta_api('GET', 'cards/'.$cid); ta_check($unchanged['version'] === $card['version'], 'Invalid task owners leave the card and access index untouched');
    $legacy = $card['checklists']; foreach ($legacy[0]['items'] as &$item) { unset($item['assigned_to']); } unset($item);
    $card = ta_patch($card, ['checklists'=>$legacy]); ta_check($card['checklists'][0]['items'][0]['assigned_to'] === $users['worker'], 'Older clients omitting task owners preserve existing assignments');
    $items = $card['checklists']; $items[0]['items'][0]['assigned_to'] = $users['second']; $card = ta_patch($card, ['checklists'=>$items]);
    ta_check($card['checklists'][0]['items'][0]['completed_at'] === $completed_at && $card['checklists'][0]['items'][0]['notes'] === 'Contato realizado.' && $card['due_at'] === $forecast, 'Reassigning a completed task preserves completion, notes and deadlines');
    ta_check(count(array_filter($card['activity'], static fn($a)=>$a['kind'] === 'checklist_assignment')) >= 2, 'Assignment changes are recorded in card history');
    $templates[0]['items'][0]['assigned_to'] = $users['second']; ta_api('PATCH', 'boards/'.$bid, ['templates'=>$templates]);
    $card = ta_api('GET', 'cards/'.$cid); ta_check($card['checklists'][0]['items'][1]['assigned_to'] === $users['worker'], 'Model changes do not replace existing task owners');
    $next = ta_api('POST', 'cards', ['board_id'=>$bid, 'title'=>'Novo visitante']); ta_check($next['checklists'][0]['items'][0]['assigned_to'] === $users['second'], 'New cards inherit the current model owners');
    $items = $card['checklists']; $items[0]['items'][1]['assigned_to'] = 0; $card = ta_patch($card, ['checklists'=>$items]);
    wp_set_current_user($users['worker']); ta_api('GET', 'cards/'.$cid, [], 403);
    $list = ta_api('GET', 'boards/'.$bid.'/cards'); ta_check($list['total'] === 1 && (int)$list['cards'][0]['id'] === (int)$next['id'], 'Removing the last owned task removes only that specific card from the worker scope');
    wp_set_current_user($admin);
    ta_api('PATCH', 'boards/'.$bid, ['members'=>array_values(array_filter($members, static fn($m)=>$m['user_id'] !== $users['second']))]);
    wp_set_current_user($users['second']); ta_api('GET', 'cards/'.$cid, [], 403); ta_api('GET', 'boards/'.$bid.'/cards', [], 403);
    wp_set_current_user($admin);
    $card = ta_patch($card, ['checklists'=>$card['checklists']]); ta_check($card['checklists'][0]['items'][0]['assigned_to'] === $users['second'], 'A revoked editor does not block saving an unchanged historic task assignment');
    ta_api('DELETE', 'cards/'.$cid); ta_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('task_assignees').' WHERE card_id=%d', $cid)) === 0, 'Deleting a card removes its task access index');
    echo "\n$passed task ownership assertions passed. No live messages sent.\n";
} finally {
    wp_set_current_user($admin); global $wpdb;
    foreach ($boards as $bid) {
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d', $bid)));
        if ($ids) { foreach (['activity','notifications','task_assignees'] as $table) { $wpdb->query('DELETE FROM '.Store::table($table).' WHERE card_id IN ('.implode(',', $ids).')'); } }
        foreach (['cards','lists','members'] as $table) { $wpdb->delete(Store::table($table), ['board_id'=>$bid]); } $wpdb->delete(Store::table('boards'), ['id'=>$bid]);
    }
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $id) { wp_delete_user($id); } update_option('visitas_settings', $saved);
}

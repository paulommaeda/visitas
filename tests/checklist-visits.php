<?php
/** Checklist-derived dashboard visits, on disposable WordPress only. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT')) { fwrite(STDERR, "Use an isolated VISITAS_WP_ROOT with VISITAS_TESTING=1.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'), '/').'/wp-load.php';
use Shalom\Visitas\{Store,Settings,Installer};
$passed=0; $boards=[]; $users=[];
function cv_check($ok, string $message): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } $passed++; echo 'PASS '.$message.PHP_EOL; }
function cv_api(string $method, string $route, array $body=[], int $status=200): array {
    [$path,$query]=array_pad(explode('?', $route, 2),2,''); parse_str($query,$params);
    $r=new WP_REST_Request($method,'/visitas/v1/'.$path); $r->set_query_params($params); $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body));
    $response=rest_do_request($r); cv_check($response->get_status()===$status,"$method $path returns $status (got {$response->get_status()})"); return $response->get_data();
}
function cv_patch(array $card, array $body, int $status=200): array { return cv_api('PATCH','cards/'.$card['id'],array_merge(['version'=>(int)$card['version']],$body),$status); }
add_filter('pre_wp_mail','__return_true',2000);
add_filter('pre_http_request',static fn()=>new WP_Error('qa_blocked','No external requests'),2000);
$settings=get_option('visitas_settings'); $zone=get_option('timezone_string'); $admin=(int)get_users(['role'=>'administrator','number'=>1])[0]->ID;
try {
    wp_set_current_user($admin); update_option('timezone_string','America/Sao_Paulo');
    update_option('visitas_settings',array_merge(Settings::get(),['welcome_enabled'=>false,'assignment_enabled'=>false,'due_enabled'=>false,'email_enabled'=>false,'sms_enabled'=>false]));
    $worker=wp_insert_user(['user_login'=>'qa_visits_'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(30),'role'=>'visitas_acompanhamento']); $users[]=$worker; get_userdata($worker)->add_cap('visitas_view_dashboard');
    $b=cv_api('POST','boards',['name'=>'Checklist visits QA '.wp_generate_uuid4(),'seed'=>true]); $bid=(int)$b['id']; $boards[]=$bid;
    $month=wp_date('Y-m'); $start=new DateTimeImmutable($month.'-01',wp_timezone()); $previous=$start->modify('-1 month')->format('Y-m');
    $c=cv_api('POST','cards',['board_id'=>$bid,'title'=>'Visitante de mês anterior','assigned_to'=>$worker,'visit_date'=>$previous.'-01','checklists'=>[['id'=>'care','title'=>'Acompanhamento','items'=>[
        ['id'=>'visit','text'=>'Visita realizada'],['id'=>'other','text'=>'Agendar visita'],['id'=>'second','text'=>'VISITA   REALIZADA!']
    ]]]]);
    $query='month='.$month.'&board_id='.$bid;
    $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===0,'Unchecked visits do not count');
    $items=$c['checklists']; $items[0]['items'][0]['done']=true; $items[0]['items'][0]['notes']='Família acolhida durante a visita.'; $items[0]['items'][0]['completed_at']='2000-01-01T00:00:00Z'; $items[0]['items'][1]['done']=true;
    $c=cv_patch($c,['checklists'=>$items]); $completion=$c['checklists'][0]['items'][0]['completed_at'];
    $d=cv_api('GET','dashboard?'.$query);
    cv_check($d['totals']['visits']===1 && $d['totals']['visited_people']===1 && $d['totals']['visitors']===0 && $d['totals']['accompanied']===1,'Visit check counts for its completion month even when registration was earlier');
    cv_check($d['trend'][5]['accompanied']===1 && in_array('checklist_visit',array_column($d['recent'],'kind'),true),'Trend and recent records include the completed visit task');
    $v=cv_api('GET','dashboard/visitors?'.$query.'&group=visited'); cv_check($v['total']===1 && (int)$v['items'][0]['id']===(int)$c['id'],'Visit drilldown includes the person behind the check');
    $records=cv_api('GET','cards/'.$c['id'].'/followups'); $visits=array_values(array_filter($records['items'],static fn($a)=>$a['kind']==='checklist_visit')); $projection=$visits[0];
    cv_check(count($visits)===1 && $projection['occurred_at']===str_replace(['T','Z'],[' ',''],$completion) && (int)$projection['user_id']===$admin && str_contains($projection['content'],'Família acolhida'),'Derived visit uses server completion time, author and task notes');
    $c=cv_patch($c,['checklists'=>$c['checklists']]); $items=$c['checklists']; $items[0]['items'][0]['notes']='Observações atualizadas.'; $c=cv_patch($c,['checklists'=>$items]);
    $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===1,'Repeated saves and note edits never duplicate a checked visit');
    $records=cv_api('GET','cards/'.$c['id'].'/followups'); $visits=array_values(array_filter($records['items'],static fn($a)=>$a['kind']==='checklist_visit'));
    cv_check(count($visits)===1 && $visits[0]['id']===$projection['id'] && $visits[0]['occurred_at']===$projection['occurred_at'],'Derived visit identity and completion time remain stable');
    $items=$c['checklists']; $items[0]['items'][2]['done']=true; $c=cv_patch($c,['checklists'=>$items]);
    cv_api('POST','cards/'.$c['id'].'/visits',['date'=>$month.'-01','content'=>'Outra visita lançada pelo formulário.']);
    $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===3 && $d['totals']['visited_people']===1,'Two visit tasks and a manual visit count as three records for one person');
    $items=$c['checklists']; $items[0]['items'][0]['done']=false; $c=cv_patch($c,['checklists'=>$items]);
    $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===2,'Unchecking a task removes its visit while retaining the manual visit');
    $items=$c['checklists']; $items[0]['items'][0]['done']=true; $c=cv_patch($c,['checklists'=>$items]);
    cv_patch($c,['checklists'=>$items,'version'=>1],409); $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===3,'Rechecking counts one current visit; stale saves cannot add duplicates');
    $items=$c['checklists']; array_pop($items[0]['items']); $items[0]['items'][0]['text']='Acompanhamento concluído'; $c=cv_patch($c,['checklists'=>$items]);
    $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===1,'Deleting or renaming visit tasks removes only derived records');
    $items=$c['checklists']; $items[0]['items'][0]['text']='Visita realizada'; $c=cv_patch($c,['checklists'=>$items]);
    $c=cv_patch($c,['list_id'=>$b['lists'][1]['id']]); $d=cv_api('GET','dashboard?'.$query); cv_check($d['totals']['visits']===2,'Moving the card keeps the same checked visit');
    // Simulate a pre-update checklist whose completion was just before a local month boundary.
    $items=$c['checklists']; $items[0]['items'][0]['completed_at']=$start->modify('-1 second')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    Store::update('cards',['checklists_json'=>wp_json_encode($items)],['id'=>$c['id']]);
    global $wpdb; $wpdb->delete(Store::table('activity'),['card_id'=>$c['id'],'kind'=>'checklist_visit']);
    $before=Store::row('cards',$c['id']); update_option('visitas_version','1.2.1'); Installer::activate();
    cv_check(Store::row('cards',$c['id'])===$before,'Upgrade backfill leaves all card data, assignments, notes and planning untouched');
    $d=cv_api('GET','dashboard?'.$query); $old=cv_api('GET','dashboard?month='.$previous.'&board_id='.$bid);
    cv_check($d['totals']['visits']===1 && $old['totals']['visits']===1,'Existing checks are backfilled using the local completion month, not the migration date');
    update_option('visitas_version','1.2.1'); Installer::activate(); $old=cv_api('GET','dashboard?month='.$previous.'&board_id='.$bid); cv_check($old['totals']['visits']===1,'Repeating migration does not duplicate an existing check');
    $items[0]['items'][0]['completed_at']=null; $items[0]['items'][0]['completed_by']=null; Store::update('cards',['checklists_json'=>wp_json_encode($items)],['id'=>$c['id']]); Store::sync_checklist_visits((int)$c['id'],$items);
    $d=cv_api('GET','dashboard?'.$query); $all=cv_api('GET','dashboard?month=all&board_id='.$bid);
    cv_check($d['totals']['visits']===1 && $all['totals']['visits']===2,'Undated historic checks count in all-month totals without inventing a monthly completion date');
    $p=cv_api('POST','boards',['name'=>'Private checklist visits QA','visibility'=>'private']); $boards[]=(int)$p['id'];
    cv_api('POST','cards',['board_id'=>$p['id'],'title'=>'Visita privada','checklists'=>[['id'=>'private','title'=>'Privado','items'=>[['id'=>'visit','text'=>'Visita realizada','done'=>true]]]]]);
    wp_set_current_user($worker); $d=cv_api('GET','dashboard?month=all'); cv_check($d['totals']['visits']===2 && $d['totals']['visited_people']===1,'Checklist visits obey assigned-card and private-board scope');
    $v=cv_api('GET','dashboard/visitors?month=all&group=visited'); cv_check($v['total']===1,'Visit drilldown never exposes another board through a task check');
    wp_set_current_user($admin); cv_api('DELETE','cards/'.$c['id']);
    cv_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('activity')." WHERE card_id=%d AND kind='checklist_visit'",$c['id']))===0,'Deleting a card removes its derived visits');
    echo "\n$passed checklist visit assertions passed. No live messages sent.\n";
} finally {
    wp_set_current_user($admin); global $wpdb;
    foreach ($boards as $bid) {
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d',$bid)));
        if ($ids) { foreach (['activity','notifications','task_assignees'] as $table) { $wpdb->query('DELETE FROM '.Store::table($table).' WHERE card_id IN ('.implode(',',$ids).')'); } }
        foreach (['cards','lists','members'] as $table) { $wpdb->delete(Store::table($table),['board_id'=>$bid]); } $wpdb->delete(Store::table('boards'),['id'=>$bid]);
    }
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $uid) { if (!is_wp_error($uid)) { wp_delete_user($uid); } }
    update_option('visitas_settings',$settings); update_option('timezone_string',$zone);
}

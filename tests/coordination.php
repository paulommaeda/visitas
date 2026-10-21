<?php
/** Coordination can only complete its own assigned tasks; disposable WordPress only. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT')) { fwrite(STDERR,"Use a disposable VISITAS_WP_ROOT with VISITAS_TESTING=1.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'),'/').'/wp-load.php';
use Shalom\Visitas\{Access,Store,Settings};
$passed=0; $boards=[]; $users=[];
function cp_check($ok,string $message):void { global $passed; if(!$ok)throw new RuntimeException('FAIL: '.$message); $passed++; echo 'PASS '.$message.PHP_EOL; }
function cp_api(string $method,string $route,array $body=[],int $status=200):array {
    [$path,$query]=array_pad(explode('?',$route,2),2,'');parse_str($query,$params);
    $r=new WP_REST_Request($method,'/visitas/v1/'.$path);$r->set_query_params($params);$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));
    $response=rest_do_request($r);cp_check($response->get_status()===$status,"$method $path returns $status (got {$response->get_status()})");return $response->get_data();
}
function cp_user(int $id):void { wp_set_current_user(0);wp_set_current_user($id); }
function cp_complete(array $card,string $item,array $extra=[],int $status=200):array { return cp_api('POST','cards/'.$card['id'].'/tasks/complete',array_merge(['version'=>(int)$card['version'],'checklist_id'=>'care','item_id'=>$item],$extra),$status); }
add_filter('pre_wp_mail','__return_true',2000);add_filter('pre_http_request',static fn()=>new WP_Error('qa_blocked','External HTTP blocked'),2000);
$settings=get_option('visitas_settings');$saved_caps=get_role('visitas_coordenacao')->capabilities;$saved_version=get_option('visitas_version');$admin=(int)get_users(['role'=>'administrator','number'=>1])[0]->ID;
try {
    cp_user($admin);update_option('visitas_settings',array_merge(Settings::get(),['email_enabled'=>false,'sms_enabled'=>false,'welcome_enabled'=>false,'assignment_enabled'=>false,'due_enabled'=>false]));
    foreach(['coord'=>'visitas_coordenacao','other'=>'visitas_recepcao','mixed'=>'visitas_coordenacao']as$key=>$role){$uid=wp_insert_user(['user_login'=>'qa_coord_'.$key.'_'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(30),'role'=>$role]);if(is_wp_error($uid))throw new RuntimeException($uid->get_error_message());$users[$key]=(int)$uid;}
    get_userdata($users['mixed'])->add_role('visitas_pastor');
    $b=cp_api('POST','boards',['name'=>'Coordination QA '.wp_generate_uuid4(),'seed'=>true]);$bid=(int)$b['id'];$boards[]=$bid;
    $p=cp_api('POST','boards',['name'=>'Private coordination QA','visibility'=>'private']);$boards[]=(int)$p['id'];
    $model=[['id'=>'care','title'=>'Cuidados','items'=>[
        ['id'=>'mine','text'=>'Visita realizada','assigned_to'=>$users['coord'],'rule'=>['amount'=>3,'unit'=>'business_days','target_list_id'=>$b['lists'][1]['id']]],
        ['id'=>'other','text'=>'Outro contato','assigned_to'=>$users['other'],'rule'=>['amount'=>3,'unit'=>'business_days']],
        ['id'=>'unassigned','text'=>'Sem responsável','rule'=>['amount'=>3,'unit'=>'business_days']],
    ]]];
    $b=cp_api('PATCH','boards/'.$bid,['templates'=>$model,'workflow'=>['enabled'=>true,'require_order'=>false],'members'=>[['user_id'=>$users['coord'],'role'=>'manager']]]);
    cp_check(in_array($users['coord'],array_column($b['assignees'],'id'),true),'Coordination remains eligible to receive checklist tasks');
    $c=cp_api('POST','cards',['board_id'=>$bid,'title'=>'Visitante preservado','assigned_to'=>$admin]);$forecast=$c['due_at'];
    $private=cp_api('POST','cards',['board_id'=>$p['id'],'title'=>'Visitante privado']);
    cp_user($users['coord']);$data=cp_api('GET','bootstrap');
    cp_check($data['capabilities']['complete_assigned_tasks']&&$data['capabilities']['view_dashboard']&&!$data['capabilities']['edit_cards']&&!$data['capabilities']['manage_boards']&&!$data['capabilities']['manage_users'],'Coordination has task completion and read capabilities, without mutation or management');
    $read=cp_api('GET','boards/'.$bid);cp_check(!$read['can_manage'],'Board manager membership cannot bypass coordination restrictions');
    cp_api('PATCH','boards/'.$bid,['templates'=>[]],403);cp_api('PATCH','boards/'.$bid,['workflow'=>['enabled'=>false]],403);cp_api('POST','boards/'.$bid.'/lists',['name'=>'Forbidden'],403);cp_api('PATCH','lists/'.$b['lists'][1]['id'],['name'=>'Forbidden'],403);cp_api('DELETE','boards/'.$bid,[],403);
    cp_api('POST','boards',['name'=>'Forbidden'],403);cp_api('POST','cards',['board_id'=>$bid,'title'=>'Forbidden'],403);
    foreach([['due_at'=>'2030-01-01T12:00:00Z'],['list_id'=>$b['lists'][2]['id']],['checklists'=>$c['checklists']],['title'=>'Forbidden'],['use_board_workflow'=>true],['disable_workflow'=>true],['assigned_to'=>$users['coord']]]as$change){cp_api('PATCH','cards/'.$c['id'],array_merge(['version'=>$c['version']],$change),403);}
    cp_api('DELETE','cards/'.$c['id'],[],403);cp_api('POST','cards/'.$c['id'].'/comments',['content'=>'Forbidden'],403);cp_api('POST','cards/'.$c['id'].'/visits',['date'=>wp_date('Y-m-d'),'content'=>'Forbidden'],403);cp_api('POST','users',[],403);
    cp_complete($c,'other',[],403);cp_complete($c,'unassigned',[],403);
    foreach([['due_at'=>'2030-01-01T12:00:00Z'],['checklists'=>[]],['done'=>false],['assigned_to'=>$users['coord']],['completed_at'=>'2000-01-01T00:00:00Z'],['notes'=>'Forbidden']]as$extra){cp_complete($c,'mine',$extra,403);}
    cp_api('POST','cards/'.$private['id'].'/tasks/complete',['version'=>$private['version'],'checklist_id'=>'care','item_id'=>'mine'],403);
    $before=Store::row('cards',(int)$c['id']);$saved=cp_complete($c,'mine');$item=$saved['checklists'][0]['items'][0];
    cp_check($item['done']&&(int)$item['completed_by']===$users['coord']&&!empty($item['completed_at']),'Coordination completes its own task with server-stamped actor and time');
    cp_check((int)$saved['list_id']===(int)$b['lists'][1]['id']&&$saved['due_at']===$forecast&&$saved['title']===$c['title']&&(int)$saved['assigned_to']===$admin,'Only configured automation moves the card; deadlines, visitor and general owner are preserved');
    cp_check(!$saved['checklists'][0]['items'][1]['done']&&!$saved['checklists'][0]['items'][2]['done']&&$saved['checklists'][0]['items'][1]['rule']===$c['checklists'][0]['items'][1]['rule'],'Other task checks and configured rules remain unchanged');
    $d=cp_api('GET','dashboard?month=all&board_id='.$bid);cp_check($d['totals']['visits']===1,'Completing an assigned visit task updates the dashboard');
    $same=cp_complete($saved,'mine');cp_check($same['version']===$saved['version']&&$same['checklists'][0]['items'][0]['completed_at']===$item['completed_at'],'Repeating completion preserves version, date and one visit');
    cp_complete($c,'mine',[],409);cp_complete($saved,'missing',[],404);cp_complete($saved,'mine',['item_id'=>[]],422);cp_complete($saved,'mine',['done'=>false],403);
    cp_user($users['mixed']);cp_api('PATCH','cards/'.$c['id'],['version'=>$saved['version'],'title'=>'Forbidden'],403);cp_api('PATCH','boards/'.$bid,['templates'=>[]],403);
    cp_user($admin);
    // Manual planning still allows only the owner's check, without any edit endpoint.
    $manual=cp_api('POST','cards',['board_id'=>$bid,'title'=>'Sem automação','disable_workflow'=>true,'checklists'=>[['id'=>'care','title'=>'Manual','items'=>[['id'=>'mine','text'=>'Contato manual','assigned_to'=>$users['coord']]]]]]);
    cp_user($users['coord']);$manual=cp_complete($manual,'mine');cp_check(!$manual['workflow']['enabled']&&$manual['checklists'][0]['items'][0]['done'],'Own tasks can be completed with automatic planning disabled');
    cp_user($admin);
    $ordered=cp_api('POST','cards',['board_id'=>$bid,'title'=>'Ordem protegida','checklists'=>[['id'=>'care','title'=>'Ordem','items'=>[['id'=>'first','text'=>'Primeiro','assigned_to'=>$users['other']],['id'=>'mine','text'=>'Depois','assigned_to'=>$users['coord']]]]]]);
    $flow=Store::json(Store::row('cards',(int)$ordered['id'])['workflow_json']);$flow['require_order']=true;Store::update('cards',['workflow_json'=>wp_json_encode($flow)],['id'=>$ordered['id']]);
    cp_user($users['coord']);cp_complete($ordered,'mine',[],422);cp_check(Store::row('cards',(int)$ordered['id'])['version']==$ordered['version'],'Order violations reject atomically');
    cp_user($admin);
    $noted=cp_api('POST','cards',['board_id'=>$bid,'title'=>'Observação obrigatória','checklists'=>[['id'=>'care','title'=>'Registro','items'=>[['id'=>'mine','text'=>'Concluir','assigned_to'=>$users['coord'],'rule'=>['require_note'=>true]]]]]]);
    cp_user($users['coord']);cp_complete($noted,'mine',[],422);cp_complete($noted,'mine',['notes'=>'Forbidden'],403);
    cp_user($admin);$lists=$noted['checklists'];$lists[0]['items'][0]['notes']='Orientação registrada pela gestão.';$noted=cp_api('PATCH','cards/'.$noted['id'],['version'=>$noted['version'],'checklists'=>$lists]);
    cp_user($users['coord']);$noted=cp_complete($noted,'mine');cp_check($noted['checklists'][0]['items'][0]['notes']==='Orientação registrada pela gestão.','Required observations are preserved and cannot be edited by coordination');
    cp_user($admin);$members=[['user_id'=>$users['coord'],'role'=>'viewer']];cp_api('PATCH','boards/'.$bid,['members'=>$members]);
    cp_user($users['coord']);cp_complete($manual,'mine',[],403);
    cp_user($admin);cp_api('PATCH','boards/'.$bid,['members'=>[['user_id'=>$users['coord'],'role'=>'editor']]]);
    $original=Store::row('cards',(int)$c['id']);$coord=get_role('visitas_coordenacao');$coord->add_cap('visitas_edit_cards');$coord->add_cap('visitas_manage_boards');$coord->remove_cap('visitas_complete_assigned_tasks');$coord->add_cap('visitas_view_dashboard',false);
    get_userdata($users['coord'])->add_cap('visitas_edit_cards');update_option('visitas_version','1.2.2');Access::install();
    cp_check(!$coord->has_cap('visitas_edit_cards')&&!$coord->has_cap('visitas_manage_boards')&&$coord->has_cap('visitas_complete_assigned_tasks')&&!$coord->has_cap('visitas_view_dashboard'),'Migration removes old write grants, adds completion, and preserves an explicit dashboard denial');
    cp_check(Store::row('cards',(int)$c['id'])===$original,'Role migration leaves visitor data, checks and dates untouched');
    cp_user($users['coord']);cp_check(!Access::can('edit_cards'),'Direct user grants cannot bypass the coordination restriction');cp_api('PATCH','cards/'.$c['id'],['version'=>$saved['version'],'title'=>'Forbidden'],403);
    cp_user($admin);$role_before=$coord->capabilities;cp_api('POST','roles',['key'=>'visitas_coordenacao','name'=>'Coordenação','caps'=>['access'=>true,'manage_boards'=>true]],422);cp_check($coord->capabilities===$role_before,'Role editor rejects management grants without partial changes');
    update_option('visitas_version','1.2.3');$coord->add_cap('visitas_complete_assigned_tasks',false);Access::install();cp_user($users['coord']);cp_complete($saved,'mine',[],403);cp_check(!Access::can('complete_assigned_tasks'),'Explicit denial of task completion is preserved on later installs');
    cp_user($admin);get_userdata($admin)->add_role('visitas_coordenacao');cp_user($admin);$updated=cp_api('PATCH','cards/'.$c['id'],['version'=>$saved['version'],'title'=>'Admin remains in control']);cp_check($updated['title']==='Admin remains in control'&&Access::can('manage_boards'),'WordPress administrators retain full access even with coordination as an additional role');get_userdata($admin)->remove_role('visitas_coordenacao');
    echo "\n$passed coordination assertions passed. No live messages sent.\n";
} finally {
    get_userdata($admin)->remove_role('visitas_coordenacao');cp_user($admin);global $wpdb;
    foreach($boards as$bid){$ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d',$bid)));if($ids)foreach(['activity','notifications','task_assignees']as$table)$wpdb->query('DELETE FROM '.Store::table($table).' WHERE card_id IN ('.implode(',',$ids).')');foreach(['cards','lists','members']as$table)$wpdb->delete(Store::table($table),['board_id'=>$bid]);$wpdb->delete(Store::table('boards'),['id'=>$bid]);}
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$uid)wp_delete_user($uid);$coord=get_role('visitas_coordenacao');foreach(array_keys($coord->capabilities)as$cap)$coord->remove_cap($cap);foreach($saved_caps as$cap=>$grant)$coord->add_cap($cap,$grant);update_option('visitas_version',$saved_version);update_option('visitas_settings',$settings);
}

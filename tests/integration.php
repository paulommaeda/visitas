<?php
/** Run only against a disposable WordPress: VISITAS_WP_ROOT=/path/to/wp php tests/integration.php */
if (!getenv('VISITAS_WP_ROOT') || getenv('VISITAS_TESTING') !== '1') { fwrite(STDERR,"Use a disposable WordPress and set VISITAS_TESTING=1 and VISITAS_WP_ROOT.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'),'/').'/wp-load.php';
use Shalom\Visitas\{Access,Store,Api,Settings,Notifications};
$passed=0;
function check($condition,string $label): void { global $passed; if (!$condition) { throw new RuntimeException('FAIL: '.$label); } $passed++; echo 'PASS '.$label.PHP_EOL; }
function as_user(int $id): void { wp_set_current_user(0); wp_set_current_user($id); }
function call_api(string $method,string $route,array $body=[],int $status=200): array {
    $r=new WP_REST_Request($method,'/visitas/v1/'.$route); $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body));
    $res=rest_do_request($r); check($res->get_status()===$status,$method.' '.$route.' HTTP '.$status.' (got '.$res->get_status().')'); return $res->get_data();
}
$mail=[];$sms=[];$sms_fail=false;$sms_code=202;
add_filter('pre_wp_mail',static function($r,$args) use(&$mail) { $mail[]=$args;return true; },999,2);
add_filter('pre_http_request',static function($r,$args,$url) use(&$sms,&$sms_fail,&$sms_code) {
    if (str_starts_with($url,'https://api.sms-gate.app/')) {
        if (($args['method']??'GET')==='POST') {
            $payload=json_decode($args['body'],true); $sms[]=['url'=>$url,'args'=>$args,'payload'=>$payload];
            return ['headers'=>[],'response'=>['code'=>$sms_fail?503:$sms_code,'message'=>'Mock'],'body'=>wp_json_encode(['id'=>$payload['id'],'state'=>'Pending']),'cookies'=>[]];
        }
        return ['headers'=>[],'response'=>['code'=>200,'message'=>'OK'],'body'=>wp_json_encode(['id'=>basename($url),'state'=>'Delivered']),'cookies'=>[]];
    }
    return new WP_Error('test_http_blocked','External HTTP is blocked during tests.');
},999,3);
$admin=get_users(['role'=>'administrator','number'=>1])[0]->ID;
$saved_settings=get_option('visitas_settings');
$board_ids=[];$user_ids=[];
try {
    as_user(0); call_api('GET','bootstrap',[],403);
    as_user($admin); $initial=Api::bootstrap(); check(!empty($initial['boards']),'Activation creates an initial board'); check(count(Access::roles())>=5,'Custom WordPress profiles installed');
    $suffix=substr(wp_generate_uuid4(),0,8);
    foreach (['visitas_acompanhamento','visitas_observador','visitas_recepcao','visitas_coordenacao'] as $role) {
        $id=wp_insert_user(['user_login'=>'qa_'.$role.'_'.$suffix,'user_email'=>$role.'_'.$suffix.'@example.test','user_pass'=>'QA-Password-2026','role'=>$role,'display_name'=>'QA '.$role]);
        check(!is_wp_error($id),'Create fixture '.$role);$user_ids[$role]=(int)$id;
    }
    $b=call_api('POST','boards',['name'=>'QA Board '.$suffix,'seed'=>true]);$bid=(int)$b['id'];$board_ids[]=$bid;
    $priv=call_api('POST','boards',['name'=>'QA Private '.$suffix,'visibility'=>'private']);$private_id=(int)$priv['id'];$board_ids[]=$private_id;
    $list=call_api('POST','boards/'.$bid.'/lists',['name'=>'Lista de teste']);
    $list=call_api('PATCH','lists/'.$list['id'],['name'=>'Lista renomeada','direction'=>'left']);check($list['name']==='Lista renomeada','List edits persist');call_api('DELETE','lists/'.$list['id']);
    $b=call_api('PATCH','boards/'.$bid,['fields'=>[['id'=>'qa_group','label'=>'Grupo de interesse','type'=>'select','required'=>true,'options'=>['Jovens','Famílias']]],'templates'=>[['id'=>'qa_template','title'=>'Primeiros contatos','items'=>[['id'=>'qa_step','text'=>'Telefonar']]]]]);
    call_api('POST','cards',['board_id'=>$bid,'title'=>'Missing attribute'],422);
    $c=call_api('POST','cards',['board_id'=>$bid,'title'=>'Visitante de Teste','phone'=>'(41) 99999-9999','email'=>'visitante@example.test','assigned_to'=>$user_ids['visitas_acompanhamento'],'consent_email'=>true,'consent_sms'=>true,'attributes'=>['qa_group'=>'Jovens'],'list_id'=>$b['lists'][1]['id']]);$cid=(int)$c['id'];
    check((int)$c['list_id']===(int)$b['initial_list_id'],'New visitor always enters the configured initial list');
    check($c['phone']==='+5541999999999','Brazilian numbers normalized to E.164');
    check(count($c['checklists'])===1 && !$c['checklists'][0]['items'][0]['done'],'Board checklist copied with fresh progress');
    call_api('PATCH','boards/'.$bid,['templates'=>[['id'=>'qa_template','title'=>'Changed template','items'=>[['id'=>'different','text'=>'New step']]]]]);
    $c=call_api('GET','cards/'.$cid);check($c['checklists'][0]['items'][0]['text']==='Telefonar','Existing checklist preserved when template changes');
    $updated=call_api('PATCH','cards/'.$cid,['version'=>$c['version'],'list_id'=>$b['lists'][1]['id'],'labels'=>['Primeira visita'],'due_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'checklists'=>[['id'=>'qa_template','title'=>'Primeiros contatos','items'=>[['id'=>'qa_step','text'=>'Telefonar','done'=>true]]]]]);
    check((int)$updated['list_id']===(int)$b['lists'][1]['id'] && $updated['checklists'][0]['items'][0]['done'],'Move and checklist progress persist');
    call_api('PATCH','cards/'.$cid,['version'=>$c['version'],'title'=>'Lost update'],409);
    call_api('PATCH','cards/'.$cid,['version'=>$updated['version'],'list_id'=>$priv['lists'][0]['id']],422);
    call_api('DELETE','boards/'.$bid,[],409);call_api('DELETE','lists/'.$b['initial_list_id'],[],409);
    as_user($user_ids['visitas_observador']);call_api('PATCH','cards/'.$cid,['version'=>$updated['version'],'title'=>'Forbidden'],403);call_api('GET','boards/'.$private_id,[],403);call_api('PATCH','settings',['app_name'=>'Forbidden'],403);call_api('POST','roles',['key'=>'administrator','caps'=>['manage_options'=>true]],403);
    as_user($user_ids['visitas_acompanhamento']);call_api('GET','cards/'.$cid);call_api('POST','cards/'.$cid.'/comments',['content'=>'Contato realizado.']);
    $hidden=call_api('GET','boards/'.$bid.'/cards');check(count($hidden['cards'])===1,'Assigned-only profile sees its assigned card');
    call_api('POST','users',['name'=>'Forbidden'],403);
    as_user($admin);$another=call_api('POST','cards',['board_id'=>$bid,'title'=>'Unassigned fixture','attributes'=>['qa_group'=>'Famílias']]);
    as_user($user_ids['visitas_acompanhamento']);call_api('GET','cards/'.$another['id'],[],403);
    as_user($admin);call_api('PATCH','boards/'.$bid,['members'=>[['user_id'=>$user_ids['visitas_acompanhamento'],'role'=>'viewer']]]);
    as_user($user_ids['visitas_acompanhamento']);call_api('PATCH','cards/'.$cid,['version'=>$updated['version'],'title'=>'Forbidden by membership'],403);
    as_user($admin);call_api('PATCH','boards/'.$bid,['members'=>[]]);
    as_user($user_ids['visitas_coordenacao']);call_api('POST','users',['name'=>'Escalation','login'=>'qa_forbidden_'.$suffix,'email'=>'forbidden@example.test','password'=>'QA-Password-2026','roles'=>['visitas_pastor']],403);
    as_user($admin);
    $settings=call_api('PATCH','settings',['email_enabled'=>true,'sms_enabled'=>true,'welcome_enabled'=>true,'sms_mode'=>'cloud','sms_username'=>'mock-user','sms_password'=>'test-password-not-real','sms_device'=>'mock-device']);
    check(!isset($settings['sms_secret']) && $settings['sms_password_set'],'Integration credential never returned to browser');
    check(Settings::sms_password()==='test-password-not-real' && Settings::get()['sms_secret']!=='test-password-not-real','SMS credential encrypted at rest');
    $welcome=call_api('POST','cards',['board_id'=>$bid,'title'=>'Notificação Teste','email'=>'notification@example.test','phone'=>'+5541999999998','consent_email'=>true,'consent_sms'=>true,'attributes'=>['qa_group'=>'Jovens']]);
    Notifications::event('welcome',Store::row('cards',(int)$welcome['id']));Notifications::process();
    check(count($sms)===1,'Welcome event deduplicated by event/channel/recipient');
    check($sms[0]['url']==='https://api.sms-gate.app/3rdparty/v1/messages','Official cloud endpoint used');
    check($sms[0]['payload']['textMessage']['text'] && $sms[0]['payload']['phoneNumbers']===['+5541999999998'] && $sms[0]['payload']['deviceId']==='mock-device','SMS request contains textMessage, E.164 recipient and selected device');
    check(str_starts_with($sms[0]['args']['headers']['Authorization'],'Basic '),'SMS uses Basic authentication');
    global $wpdb;
    $log=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::table('notifications')." WHERE card_id=%d AND channel='sms'",$welcome['id']),ARRAY_A);
    check($log['state']==='delivered','Delivery confirmation retrieved separately from API acceptance');
    $opt=call_api('POST','cards',['board_id'=>$bid,'title'=>'Revoked consent','email'=>'cancel@example.test','phone'=>'+5541999999997','consent_email'=>true,'consent_sms'=>true,'attributes'=>['qa_group'=>'Jovens']]);
    call_api('PATCH','cards/'.$opt['id'],['version'=>$opt['version'],'consent_email'=>false,'consent_sms'=>false]);Notifications::process();
    check(count($sms)===1,'Consent rechecked before queue dispatch');
    $no=call_api('POST','cards',['board_id'=>$bid,'title'=>'No consent','phone'=>'+5541999999996','attributes'=>['qa_group'=>'Jovens']]);call_api('POST','cards/'.$no['id'].'/notify',['channel'=>'sms','subject'=>'Test','body'=>'Will not send'],422);
    Notifications::due();Notifications::due();
    $n=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('notifications')." WHERE card_id=%d AND event_key LIKE 'due:%%' AND channel='inapp'",$cid));check($n===1,'Due reminders deduplicated across cron runs');
    $sms_fail=true;$retry=call_api('POST','cards',['board_id'=>$bid,'title'=>'Retry fixture','phone'=>'+5541999999995','consent_sms'=>true,'attributes'=>['qa_group'=>'Jovens']]);Notifications::process();
    $retrylog=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::table('notifications')." WHERE card_id=%d AND channel='sms'",$retry['id']),ARRAY_A);
    check($retrylog['state']==='queued' && (int)$retrylog['attempts']===1 && strtotime($retrylog['next_attempt'].' UTC')>time(),'Temporary gateway failure schedules retry with backoff');
    $sms_fail=false;Store::update('notifications',['next_attempt'=>gmdate('Y-m-d H:i:s',time()-10)],['id'=>$retrylog['id']]);Notifications::process();
    check($sms[count($sms)-1]['payload']['id']===$retrylog['provider_id'],'SMS retries reuse the same idempotency identifier');
    $role=call_api('POST','roles',['key'=>'visitas_qa_'.$suffix,'name'=>'QA Custom Profile','caps'=>['access'=>true,'create_cards'=>true,'manage_options'=>true]]);
    check(!get_role('visitas_qa_'.$suffix)->has_cap('manage_options'),'Role editor cannot grant WordPress administrative powers');
    remove_role('visitas_qa_'.$suffix);
    call_api('DELETE','boards/'.$private_id);
    call_api('PATCH','settings',['sms_enabled'=>false]);
    $test_input=['phone'=>'(41) 99999-9981','message'=>'Teste direto do gateway','request_id'=>'qa-test-'.$suffix.'-direct'];$sent_before=count($sms);
    $test=call_api('POST','sms/test',$test_input);
    check(count($sms)===$sent_before+1 && $test['state']==='accepted','Test SMS is dispatched immediately with automations disabled');
    check($test['recipient']==='+5541999999981' && str_contains($test['detail'],'HTTP 202') && $test['provider_id'],'Test returns normalized recipient, gateway HTTP result and message identifier');
    check(!Settings::get()['sms_enabled'],'Test does not enable SMS automations');
    $same=call_api('POST','sms/test',$test_input);check($same['id']===$test['id'] && count($sms)===$sent_before+1,'Repeated test request is idempotent');
    call_api('POST','sms/test',array_merge($test_input,['phone'=>'(41) 99999-9982']),409);
    $confirmed=call_api('GET','sms/test/'.$test['id']);check($confirmed['state']==='delivered','Test delivery status can be consulted independently');
    as_user($user_ids['visitas_recepcao']);call_api('POST','sms/test',$test_input,403);call_api('GET','sms/test/'.$test['id'],[],403);
    as_user($admin);call_api('GET','sms/test/99999999',[],404);call_api('POST','sms/test',array_merge($test_input,['phone'=>'invalid']),422);
    $sms_code=401;$denied=call_api('POST','sms/test',array_merge($test_input,['request_id'=>'qa-test-'.$suffix.'-auth']));
    check($denied['state']==='failed' && str_contains($denied['detail'],'HTTP 401'),'Test exposes useful authentication failure without credentials');
    $sms_code=503;$uncertain_input=array_merge($test_input,['request_id'=>'qa-test-'.$suffix.'-uncertain']);$uncertain=call_api('POST','sms/test',$uncertain_input);
    check($uncertain['state']==='unknown','Unconfirmed test send is identified separately');
    $after_uncertain=count($sms);Notifications::process();call_api('POST','sms/test',$uncertain_input);
    check(count($sms)===$after_uncertain,'Unconfirmed direct tests are not retried by cron or duplicate requests');
    $sms_code=202;$resolved=call_api('GET','sms/test/'.$uncertain['id']);check($resolved['state']==='delivered','Uncertain test can be reconciled by status query');
    call_api('PATCH','settings',['clear_sms_password'=>true]);call_api('POST','sms/test',array_merge($test_input,['request_id'=>'qa-test-'.$suffix.'-unconfigured']),422);
    echo "\n$passed assertions passed. No live messages sent.\n";
} finally {
    as_user($admin);update_option('visitas_settings',$saved_settings,false);
    global $wpdb;
    if (isset($suffix)) { $wpdb->query($wpdb->prepare('DELETE FROM '.Store::table('notifications').' WHERE event_key LIKE %s','test:'.$admin.':qa-test-'.$suffix.'%')); }
    foreach ($board_ids as $bid) {
        $ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d',$bid));
        foreach ($ids as $id) { $wpdb->delete(Store::table('notifications'),['card_id'=>$id]);$wpdb->delete(Store::table('activity'),['card_id'=>$id]); }
        foreach (['cards','lists','members'] as $t) { $wpdb->delete(Store::table($t),['board_id'=>$bid]); }$wpdb->delete(Store::table('boards'),['id'=>$bid]);
    }
    require_once ABSPATH.'wp-admin/includes/user.php';foreach ($user_ids as $id) { wp_delete_user($id); }
}

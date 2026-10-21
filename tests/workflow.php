<?php
/** Checklist scheduling and actions against a disposable WordPress; no real messages. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT')) { fwrite(STDERR,"Set VISITAS_TESTING=1 and VISITAS_WP_ROOT to a disposable installation.\n"); exit(1); }
require rtrim(getenv('VISITAS_WP_ROOT'),'/').'/wp-load.php';
use Shalom\Visitas\{Access,Store,Workflow,Settings,Notifications};
$passed=0;$boards=[];$users=[];
function wf_check($ok,string $message):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$message);$passed++;echo 'PASS '.$message.PHP_EOL;}
function wf_api(string $method,string $route,array $body=[],int $status=200):array{
 [$path,$query]=array_pad(explode('?',$route,2),2,'');parse_str($query,$params);
 $r=new WP_REST_Request($method,'/visitas/v1/'.$path);$r->set_query_params($params);$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));
 $response=rest_do_request($r);wf_check($response->get_status()===$status,"$method $path HTTP $status (got {$response->get_status()})");return $response->get_data();
}
function wf_patch(array $card,array $body=[],int $status=200):array{return wf_api('PATCH','cards/'.$card['id'],array_merge(['version'=>(int)$card['version']],$body),$status);}
add_filter('pre_wp_mail','__return_true',2000);
add_filter('pre_http_request',static fn()=>new WP_Error('qa_blocked','External HTTP blocked'),2000);
$saved_settings=get_option('visitas_settings');$saved_zone=get_option('timezone_string');
$admin=get_users(['role'=>'administrator','number'=>1])[0]->ID;
try{
 wp_set_current_user($admin);update_option('timezone_string','America/Sao_Paulo');
 update_option('visitas_settings',array_merge(Settings::get(),['welcome_enabled'=>false,'assignment_enabled'=>false,'email_enabled'=>false,'sms_enabled'=>false,'due_enabled'=>false]));
 $b=wf_api('POST','boards',['name'=>'Checklist QA '.wp_generate_uuid4(),'seed'=>true]);$boards[]=(int)$b['id'];$bid=(int)$b['id'];
 $other=wf_api('POST','boards',['name'=>'Other checklist board']);$boards[]=(int)$other['id'];
 $settings=Workflow::settings(['enabled'=>true,'complete_card'=>true]);
 $templates=[['id'=>'journey','title'=>'Jornada de acolhimento','description'=>'Receber, acompanhar e integrar.','items'=>[
  ['id'=>'register','text'=>'Cadastro','rule'=>['amount'=>3,'unit'=>'business_days','target_list_id'=>$b['lists'][1]['id']]],
  ['id'=>'contact','text'=>'Primeiro contato','rule'=>['amount'=>3,'unit'=>'business_days','target_list_id'=>$b['lists'][2]['id']]],
  ['id'=>'connect','text'=>'Integração','rule'=>['amount'=>3,'unit'=>'business_days','target_list_id'=>$b['lists'][3]['id']]],
 ]]];
 $b=wf_api('PATCH','boards/'.$bid,['workflow'=>$settings,'templates'=>$templates]);
 $normalized=Store::checklists($templates,false,$bid);$board=Store::row('boards',$bid);
 $plan=Workflow::apply($normalized,null,$board,'2026-10-02 13:00:00');
 wf_check($plan['workflow']['planned_due_at']==='2026-10-15T13:00:00Z','Three three-business-day checks total nine business days from a Friday registration');
 wf_check(array_column($plan['checklists'][0]['items'],'planned_due_at')===['2026-10-07T13:00:00Z','2026-10-12T13:00:00Z','2026-10-15T13:00:00Z'],'Each checklist item receives its sequential planned date');
 $holiday_board=$board;$holiday_board['workflow_json']=wp_json_encode(array_merge($settings,['holidays'=>['2026-10-12']]));
 $holiday=Workflow::apply($normalized,null,$holiday_board,'2026-10-02 13:00:00');
 wf_check($holiday['workflow']['planned_due_at']==='2026-10-16T13:00:00Z','Configured holidays are excluded from business-day totals');
 $custom=Workflow::settings(['weekdays'=>[1,3,5]]);
 $d=Workflow::add_time(new DateTimeImmutable('2026-10-02T13:00:00Z'),['amount'=>3,'unit'=>'business_days'],$custom);
 wf_check($d->setTimezone(new DateTimeZone('UTC'))->format('c')==='2026-10-09T13:00:00+00:00','Custom working weekdays are honored');
 $d=Workflow::add_time(new DateTimeImmutable('2026-10-03T13:00:00Z'),['amount'=>1,'unit'=>'business_days'],$settings);
 wf_check($d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')==='2026-10-05','Registration on a weekend starts counting on the next working day');
 $ny=Workflow::settings(['timezone'=>'America/New_York']);$dst=new DateTimeImmutable('2026-03-07T15:00:00Z');
 $hours=Workflow::add_time($dst,['amount'=>24,'unit'=>'hours'],$ny);$days=Workflow::add_time($dst,['amount'=>1,'unit'=>'days'],$ny);
 wf_check($hours->getTimestamp()-$dst->getTimestamp()===86400&&$days->getTimestamp()-$dst->getTimestamp()===82800,'Hours are elapsed time; calendar days keep the local clock across DST');
 $half=Workflow::add_time($dst,['amount'=>.5,'unit'=>'hours'],$ny);wf_check($half->getTimestamp()-$dst->getTimestamp()===1800,'Fractional hours are supported');
 $old=['created_at'=>'2026-10-02 13:00:00','checklists_json'=>wp_json_encode($plan['checklists']),'workflow_json'=>wp_json_encode($plan['workflow'])];
 $next=$plan['checklists'];$next[0]['items'][0]['done']=true;
 $advanced=Workflow::apply(Store::checklists($next,false,$bid,$plan['checklists']),$old,$board,'2026-10-05 14:00:00');
 wf_check($advanced['target_list_id']===(int)$b['lists'][1]['id'],'Checking registration chooses its configured destination');
 wf_check($advanced['workflow']['active']['due_at']==='2026-10-08T14:00:00Z','Execution deadline starts when the next item becomes active');
 wf_check($advanced['workflow']['planned_due_at']===$plan['workflow']['planned_due_at'],'Actual completion does not shift the final registration-based forecast');
 $registration=$board;$registration['workflow_json']=wp_json_encode(array_merge($settings,['deadline_mode'=>'registration']));
 $start=Workflow::apply($normalized,null,$registration,'2026-10-02 13:00:00');
 $r_old=['created_at'=>'2026-10-02 13:00:00','checklists_json'=>wp_json_encode($start['checklists']),'workflow_json'=>wp_json_encode($start['workflow'])];
 $r_next=$start['checklists'];$r_next[0]['items'][0]['done']=true;
 $r=Workflow::apply(Store::checklists($r_next,false,$bid,$start['checklists']),$r_old,$registration,'2026-10-05 14:00:00');
 wf_check($r['workflow']['active']['due_at']==='2026-10-12T13:00:00Z','Registration mode uses the cumulative schedule for each execution deadline');

 $card=wf_api('POST','cards',['board_id'=>$bid,'title'=>'Visitante da jornada','assigned_to'=>$admin]);
 wf_check($card['workflow']['enabled']&&$card['due_at']===$card['workflow']['planned_due_at'],'New card receives independent rules and its final calculated due date');
 wf_check($card['step_due_at']===$card['checklists'][0]['items'][0]['execution_due_at'],'Current step deadline is exposed separately from final delivery');
 $invalid=$card['checklists'];$invalid[0]['items'][1]['done']=true;wf_patch($card,['checklists'=>$invalid],422);
 $unchanged=wf_api('GET','cards/'.$card['id']);wf_check($unchanged['version']===$card['version']&&!$unchanged['checklists'][0]['items'][1]['done'],'Out-of-order rejection leaves version and progress untouched');
 $items=$card['checklists'];$items[0]['items'][0]['rule']['require_note']=true;$card=wf_patch($card,['checklists'=>$items]);
 $items=$card['checklists'];$items[0]['items'][0]['done']=true;wf_patch($card,['checklists'=>$items],422);
 $items[0]['items'][0]['notes']='Cadastro validado. Próximo contato combinado.';$items[0]['notes']='Acompanhamento desta família.';
 $items[0]['items'][0]['completed_at']='2000-01-01T00:00:00Z';$items[0]['items'][0]['completed_by']=999999;
 $card=wf_patch($card,['checklists'=>$items]);
 wf_check((int)$card['list_id']===(int)$b['lists'][1]['id'],'Completion moves the persisted card atomically');
 wf_check($card['checklists'][0]['items'][0]['completed_by']===$admin&&$card['checklists'][0]['items'][0]['completed_at']!=='2000-01-01T00:00:00Z','Completion author and date are generated by the server');
 $completed_at=$card['checklists'][0]['items'][0]['completed_at'];$notes=$card['checklists'][0]['items'][0]['notes'];
 $records=wf_api('GET','cards/'.$card['id'].'/followups');wf_check(count($records['items'])===2&&$records['items'][0]['kind']==='checklist_note','Checklist and item observations appear in the visitor followup history');
 $card=wf_patch($card,['list_id'=>$b['lists'][3]['id']]);$card=wf_patch($card,['checklists'=>$card['checklists']]);
 wf_check((int)$card['list_id']===(int)$b['lists'][3]['id']&&$card['checklists'][0]['items'][0]['completed_at']===$completed_at,'Editing an already-checked item does not replay its action or change completion time');
 $legacy=array_map(static fn($list)=>['id'=>$list['id'],'title'=>$list['title'],'items'=>array_map(static fn($item)=>['id'=>$item['id'],'text'=>$item['text'],'done'=>$item['done']],$list['items'])],$card['checklists']);
 $card=wf_patch($card,['checklists'=>$legacy]);wf_check($card['checklists'][0]['items'][0]['notes']===$notes&&$card['checklists'][0]['items'][0]['rule']['amount']==3,'Older clients cannot erase notes or rules by omitting new fields');
 $templates[0]['items'][1]['rule']['amount']=5;
 wf_api('PATCH','boards/'.$bid,['workflow'=>array_merge($settings,['holidays'=>[wp_date('Y-m-d')]]),'templates'=>$templates]);
 $old_due=$card['due_at'];$card=wf_patch($card,['checklists'=>$card['checklists']]);
 wf_check($card['due_at']===$old_due&&$card['checklists'][0]['items'][1]['rule']['amount']==3,'Changing board models and calendar does not reset existing cards or dates');
 $card=wf_patch($card,['use_board_workflow'=>true]);
 wf_check($card['checklists'][0]['items'][0]['done']&&$card['checklists'][0]['items'][0]['notes']===$notes&&$card['checklists'][0]['items'][0]['completed_at']===$completed_at&&$card['checklists'][0]['items'][1]['rule']['amount']==5,'Explicit application updates rules while preserving completed checks and observations');
 $computed=$card['due_at'];$card=wf_patch($card,['due_at'=>'2099-01-01T12:00:00Z']);wf_check($card['due_at']===$computed,'Automatic final delivery cannot be overwritten by a stale form value');
 $card=wf_patch($card,['disable_workflow'=>true]);wf_check(!$card['workflow']['enabled']&&!$card['step_due_at']&&$card['due_at']===$computed,'Manual mode turns off hooks and preserves the previous final date');
 $card=wf_patch($card,['due_at'=>'2099-01-01T12:00:00Z']);wf_check($card['due_at']==='2099-01-01T12:00:00Z','Manual mode allows an independent card due date');
 $bad=$card['checklists'];$bad[0]['items'][1]['rule']['target_list_id']=$other['lists'][0]['id'];wf_patch($card,['checklists'=>$bad],422);
 $bad=$card['checklists'];$bad[0]['items'][1]['rule']['amount']=1.5;wf_patch($card,['checklists'=>$bad],422);
 $bad=$card['checklists'];$bad[0]['items'][1]['rule']['unit']='unknown';wf_patch($card,['checklists'=>$bad],422);
 wf_api('PATCH','boards/'.$bid,['workflow'=>['enabled'=>true,'weekdays'=>[]]],422);
 wf_api('PATCH','boards/'.$bid,['workflow'=>['holidays'=>['2026-02-30']]],422);
 $observer=wp_insert_user(['user_login'=>'qa_workflow_'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(30),'role'=>'visitas_observador']);$users[]=$observer;
 wp_set_current_user($observer);wf_patch($card,['checklists'=>$card['checklists']],403);wf_api('PATCH','boards/'.$bid,['workflow'=>$settings],403);wp_set_current_user($admin);
 $card=wf_patch($card,['use_board_workflow'=>true]);
 $items=$card['checklists'];foreach($items[0]['items'] as &$item){$item['done']=true;$item['notes']=$item['notes']?:'Etapa realizada.';}unset($item);
 $card=wf_patch($card,['checklists'=>$items]);wf_check((int)$card['completed']===1&&(int)$card['list_id']===(int)$b['lists'][3]['id']&&!$card['step_due_at'],'Completing all checks can complete the card and uses the last configured destination');
 $items=$card['checklists'];$items[0]['items'][2]['done']=false;$card=wf_patch($card,['checklists'=>$items]);
 wf_check((int)$card['completed']===0&&(int)$card['list_id']===(int)$b['lists'][3]['id']&&$card['checklists'][0]['items'][2]['completed_at']===null,'Reopening clears completion without moving backwards');
 $empty=wf_api('POST','boards/'.$bid.'/lists',['name'=>'Destino protegido']);
 $ref=[['id'=>'reference','title'=>'Referência','items'=>[['id'=>'step','text'=>'Mover','rule'=>['target_list_id'=>$empty['id']]]]]];
 wf_api('PATCH','boards/'.$bid,['templates'=>$ref]);wf_api('DELETE','lists/'.$empty['id'],[],409);
 $refcard=wf_api('POST','cards',['board_id'=>$bid,'title'=>'Cartão com destino protegido']);
 wf_api('PATCH','boards/'.$bid,['templates'=>[]]);wf_api('DELETE','lists/'.$empty['id'],[],409);
 $clear=$refcard['checklists'];$clear[0]['items'][0]['rule']['target_list_id']=0;wf_patch($refcard,['checklists'=>$clear]);wf_api('DELETE','lists/'.$empty['id']);

 $tiny=[['id'=>'timer','title'=>'Prazos em horas','items'=>[['id'=>'one','text'=>'Etapa um','rule'=>['amount'=>.01,'unit'=>'hours']],['id'=>'two','text'=>'Etapa dois','rule'=>['amount'=>.01,'unit'=>'hours']]]]];
 wf_api('PATCH','boards/'.$bid,['workflow'=>array_merge($settings,['complete_card'=>false]),'templates'=>$tiny]);
 $timer=wf_api('POST','cards',['board_id'=>$bid,'title'=>'Lembrete de etapa','assigned_to'=>$admin]);
 update_option('visitas_settings',array_merge(Settings::get(),['due_enabled'=>true,'email_enabled'=>true,'reminder_hours'=>24]));
 Notifications::due();Notifications::due();global $wpdb;
 $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('notifications')." WHERE card_id=%d AND channel='inapp' AND event_key LIKE 'due:%%'",$timer['id']));
 wf_check($count===1,'Current-step reminders are deduplicated across cron runs');
 $old_hash=Workflow::due_hash(Store::row('cards',$timer['id']));$items=$timer['checklists'];$items[0]['items'][0]['done']=true;$timer=wf_patch($timer,['checklists'=>$items]);Notifications::due();
 wf_check($old_hash!==Workflow::due_hash(Store::row('cards',$timer['id'])),'Reminder identity changes when the active checklist item changes');
 Notifications::process();
 $cancelled=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Store::table('notifications')." WHERE card_id=%d AND channel='email' AND event_key=%s AND state='cancelled'",$timer['id'],'due:'.$timer['id'].':'.$old_hash));
 wf_check($cancelled===1,'Queued reminder from the previous step is cancelled before sending');
 Store::update('cards',['step_due_at'=>gmdate('Y-m-d H:i:s',time()-3600),'due_at'=>gmdate('Y-m-d H:i:s',time()+10*DAY_IN_SECONDS)],['id'=>$timer['id']]);
 $d=wf_api('GET','dashboard?month=all&board_id='.$bid);wf_check($d['totals']['overdue']>=1,'Dashboard detects overdue steps even when final delivery is in the future');
 Store::update('cards',['step_due_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),'due_at'=>gmdate('Y-m-d H:i:s',time()-3600)],['id'=>$timer['id']]);
 $d=wf_api('GET','dashboard?month=all&board_id='.$bid);wf_check($d['totals']['overdue']>=1,'A late final delivery is not hidden by a future execution deadline');
 echo "\n$passed workflow assertions passed. No live messages sent.\n";
}finally{
 wp_set_current_user($admin);global $wpdb;
 foreach($boards as $bid){$ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d',$bid)));if($ids)foreach(['activity','notifications']as$t)$wpdb->query('DELETE FROM '.Store::table($t).' WHERE card_id IN ('.implode(',',$ids).')');foreach(['cards','lists','members']as$t)$wpdb->delete(Store::table($t),['board_id'=>$bid]);$wpdb->delete(Store::table('boards'),['id'=>$bid]);}
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)if(!is_wp_error($id))wp_delete_user($id);
 update_option('visitas_settings',$saved_settings);update_option('timezone_string',$saved_zone);
}

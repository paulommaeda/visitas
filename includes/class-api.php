<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Api {
    public static function register(): void {
        self::route('/bootstrap','GET',[self::class,'bootstrap']);
        self::route('/dashboard','GET',[Dashboard::class,'summary']);
        self::route('/dashboard/visitors','GET',[Dashboard::class,'visitors']);
        self::route('/boards','POST',static function($r) { Access::require_cap('create_boards'); return Store::board_data(Access::board(Store::create_board(self::body($r)))); });
        self::route('/boards/(?P<id>\d+)','GET',[self::class,'board']);
        self::route('/boards/(?P<id>\d+)','PATCH',[self::class,'save_board']);
        self::route('/boards/(?P<id>\d+)','DELETE',[self::class,'delete_board']);
        self::route('/boards/(?P<id>\d+)/lists','POST',[self::class,'create_list']);
        self::route('/lists/(?P<id>\d+)','PATCH',[self::class,'save_list']);
        self::route('/lists/(?P<id>\d+)','DELETE',[self::class,'delete_list']);
        self::route('/boards/(?P<id>\d+)/cards','GET',[self::class,'cards']);
        self::route('/cards','POST',static fn($r)=>Store::save_card(self::body($r)));
        self::route('/cards/(?P<id>\d+)','GET',[self::class,'card']);
        self::route('/cards/(?P<id>\d+)','PATCH',[self::class,'save_card']);
        self::route('/cards/(?P<id>\d+)/tasks/complete','POST',[self::class,'complete_task']);
        self::route('/cards/(?P<id>\d+)','DELETE',[self::class,'delete_card']);
        self::route('/cards/(?P<id>\d+)/comments','POST',[self::class,'comment']);
        self::route('/cards/(?P<id>\d+)/followups','GET',[Dashboard::class,'followups']);
        self::route('/cards/(?P<id>\d+)/visits','POST',[Dashboard::class,'visit']);
        self::route('/cards/(?P<id>\d+)/notify','POST',[self::class,'notify']);
        self::route('/profile','PATCH',[self::class,'profile']);
        self::route('/users','GET',[self::class,'users']);
        self::route('/users','POST',[self::class,'save_user']);
        self::route('/users/(?P<id>\d+)','PATCH',[self::class,'save_user']);
        self::route('/roles','GET',static fn()=>['roles'=>Access::roles(),'capabilities'=>Access::capabilities()]);
        self::route('/roles','POST',[self::class,'save_role']);
        self::route('/settings','GET',static function() { self::admin(); return Settings::editable(); });
        self::route('/settings','PATCH',static function($r) { self::admin(); return Settings::save(self::body($r)); });
        self::route('/sms/test','POST',static function($r) { self::admin(); return Notifications::test_sms(self::body($r)); });
        self::route('/sms/test/(?P<id>\d+)','GET',static function($r) { self::admin(); return Notifications::test_status((int)$r['id']); });
        self::route('/assets','POST',[self::class,'upload_asset']);
        self::route('/notifications','GET',[self::class,'notifications']);
        self::route('/notifications/read','POST',[self::class,'read_notifications']);
        self::route('/notifications/sync','POST',static function() { Access::require_cap('view_logs'); Notifications::sync(); return ['ok'=>true]; });
    }

    private static function route(string $path, string $method, callable $callback): void {
        register_rest_route('visitas/v1',$path,[
            'methods'=>$method,
            'permission_callback'=>static fn()=>is_user_logged_in() && Access::can('access') ? true : new \WP_Error('visitas_forbidden','Entre com um usuário autorizado.',['status'=>403]),
            'callback'=>static function($r) use ($callback) {
                try { $response=rest_ensure_response($callback($r)); $response->header('Cache-Control','private, no-store'); return $response; }
                catch (HttpError $e) { return new \WP_Error('visitas_error',$e->getMessage(),['status'=>$e->status]); }
                catch (\Throwable $e) { if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Visitas: '.$e->getMessage()); } return new \WP_Error('visitas_error','Não foi possível concluir esta operação. Tente novamente.',['status'=>500]); }
            },
        ]);
    }

    public static function body(\WP_REST_Request $r): array {
        $body=$r->get_json_params();
        if (!is_array($body)) { throw new HttpError('Envie os dados em formato JSON.',422); }
        return $body;
    }

    private static function admin(): void { if (!Access::admin()) { throw new HttpError('Apenas administradores podem alterar configurações e permissões.',403); } }

    private static function card_scope(): string {
        return Access::card_scope_sql();
    }

    public static function bootstrap(): array {
        global $wpdb;
        $boards=[];
        foreach ($wpdb->get_results('SELECT * FROM '.Store::table('boards').' ORDER BY name',ARRAY_A) as $b) {
            try { Access::board((int)$b['id']); } catch (HttpError $e) { continue; }
            $stats=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, SUM(completed=1) AS completed, SUM(completed=0 AND '.Workflow::deadline_sql().'<%s) AS overdue FROM '.Store::table('cards').' WHERE board_id=%d AND archived=0'.self::card_scope(),current_time('mysql',true),$b['id']),ARRAY_A);
            $boards[]=['id'=>(int)$b['id'],'name'=>$b['name'],'description'=>$b['description'],'color'=>$b['color'],'visibility'=>$b['visibility'],'stats'=>$stats];
        }
        $u=wp_get_current_user(); $caps=[];
        foreach (Access::capabilities() as $cap=>$label) { $caps[$cap]=Access::can($cap); }
        return [
            'boards'=>$boards,'staff'=>Store::staff(),'capabilities'=>$caps,'is_admin'=>Access::admin(),'brand'=>Settings::public_brand(),
            'user'=>['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'login'=>$u->user_login,'roles'=>$u->roles,
                'phone'=>(string)get_user_meta($u->ID,'visitas_phone',true),'sms_optin'=>get_user_meta($u->ID,'visitas_sms_optin',true)==='1','email_optout'=>get_user_meta($u->ID,'visitas_email_optout',true)==='1'],
            'portal_url'=>Portal::url(),'version'=>VISITAS_VERSION,'today'=>wp_date('Y-m-d'),'month'=>wp_date('Y-m'),
        ];
    }

    public static function board($r): array { return Store::board_data(Access::board((int)$r['id'])); }

    public static function save_board($r): array {
        global $wpdb;
        $b=Access::board((int)$r['id'],true); $in=self::body($r); $data=['updated_at'=>current_time('mysql',true)];
        if (isset($in['name'])) { $data['name']=Store::name($in['name']); }
        if (isset($in['description'])) { $data['description']=sanitize_textarea_field(substr((string)$in['description'],0,4000)); }
        if (isset($in['color'])) { $data['color']=sanitize_hex_color($in['color'])?:'#001BE1'; }
        if (isset($in['visibility'])) { $data['visibility']=$in['visibility']==='private'?'private':'team'; }
        if (isset($in['initial_list_id'])) {
            $l=Store::row('lists',absint($in['initial_list_id']));
            if (!$l || (int)$l['board_id']!==(int)$b['id']) { throw new HttpError('Escolha uma lista deste quadro.',422); }
            $data['initial_list_id']=(int)$l['id'];
        }
        if (isset($in['fields'])) { $data['schema_json']=wp_json_encode(Store::schema((array)$in['fields'])); }
        if (isset($in['templates'])) { $data['templates_json']=wp_json_encode(Store::checklists((array)$in['templates'],true,(int)$b['id'],Store::json($b['templates_json']))); }
        if (isset($in['workflow'])) {
            if (!is_array($in['workflow'])) { throw new HttpError('Configuração de planejamento inválida.',422); }
            $data['workflow_json']=wp_json_encode(Workflow::settings($in['workflow']));
        }
        $members=[];
        if (isset($in['members'])) {
            foreach (array_slice((array)$in['members'],0,500) as $m) {
                $uid=absint($m['user_id']??0); $role=$m['role']??'';
                if (!Access::can('access',$uid) || !in_array($role,['manager','editor','viewer'],true)) { throw new HttpError('Membro ou nível de acesso inválido.',422); }
                $members[$uid]=['board_id'=>$b['id'],'user_id'=>$uid,'role'=>$role];
            }
        }
        Store::transaction(static function() use($b,$data,$members,$in) {
            global $wpdb;
            Store::update('boards',$data,['id'=>$b['id']]);
            if (isset($in['members'])) {
                $wpdb->delete(Store::table('members'),['board_id'=>$b['id']]);
                foreach ($members as $m) { Store::insert('members',$m); }
            }
        });
        return Store::board_data(Access::board((int)$b['id']));
    }

    public static function delete_board($r): array {
        global $wpdb;
        $b=Access::board((int)$r['id'],true);
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE board_id=%d LIMIT 1',$b['id']))) { throw new HttpError('Este quadro contém cartões. Exclua os cartões antes de excluir o quadro.',409); }
        Store::transaction(static function() use($b) { global $wpdb; $wpdb->delete(Store::table('lists'),['board_id'=>$b['id']]); $wpdb->delete(Store::table('members'),['board_id'=>$b['id']]); $wpdb->delete(Store::table('boards'),['id'=>$b['id']]); });
        return ['deleted'=>true];
    }

    public static function create_list($r): array {
        global $wpdb;
        $b=Access::board((int)$r['id'],true); $in=self::body($r);
        $pos=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position),0)+1 FROM '.Store::table('lists').' WHERE board_id=%d',$b['id']));
        return Store::row('lists',Store::insert('lists',['board_id'=>$b['id'],'name'=>Store::name($in['name']??''),'color'=>sanitize_hex_color($in['color']??'')?:$b['color'],'position'=>$pos]));
    }

    public static function save_list($r): array {
        $l=Store::row('lists',(int)$r['id']); if (!$l) { throw new HttpError('Lista não encontrada.',404); }
        Access::board((int)$l['board_id'],true); $in=self::body($r); $d=[];
        if (isset($in['name'])) { $d['name']=Store::name($in['name']); }
        if (isset($in['color'])) { $d['color']=sanitize_hex_color($in['color'])?:'#001BE1'; }
        if (isset($in['direction'])) {
            $lists=Store::lists((int)$l['board_id']); $ids=array_column($lists,'id'); $i=array_search($l['id'],$ids); $j=$i+($in['direction']==='left'?-1:1);
            if (isset($lists[$j])) { Store::transaction(static function() use($lists,$i,$j) { foreach ($lists as $idx=>$x) { Store::update('lists',['position'=>$idx===$i?$j:($idx===$j?$i:$idx)],['id'=>$x['id']]); } }); }
        }
        if ($d) { Store::update('lists',$d,['id'=>$l['id']]); }
        return Store::row('lists',(int)$l['id']);
    }

    public static function delete_list($r): array {
        global $wpdb;
        $l=Store::row('lists',(int)$r['id']); if (!$l) { throw new HttpError('Lista não encontrada.',404); }
        $b=Access::board((int)$l['board_id'],true);
        if ((int)$b['initial_list_id']===(int)$l['id']) { throw new HttpError('Defina outra lista inicial antes de excluir esta lista.',409); }
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Store::table('cards').' WHERE list_id=%d LIMIT 1',$l['id']))) { throw new HttpError('Mova ou exclua os cartões desta lista antes de excluí-la.',409); }
        if (Workflow::references_list(Store::json($b['templates_json']), (int)$l['id'])) { throw new HttpError('Esta lista é destino de um checklist. Altere as ações dos modelos antes de excluí-la.',409); }
        $last = 0;
        do {
            $batch = $wpdb->get_results($wpdb->prepare('SELECT id,checklists_json FROM '.Store::table('cards').' WHERE board_id=%d AND id>%d ORDER BY id LIMIT 100',$b['id'],$last),ARRAY_A);
            foreach ($batch as $card) {
                if (Workflow::references_list(Store::json($card['checklists_json']), (int)$l['id'])) { throw new HttpError('Esta lista é destino de um checklist de cartão. Altere as ações antes de excluí-la.',409); }
                $last = (int)$card['id'];
            }
        } while (count($batch) === 100);
        $wpdb->delete(Store::table('lists'),['id'=>$l['id']]); return ['deleted'=>true];
    }

    public static function cards($r): array {
        global $wpdb;
        $id=(int)$r['id']; Access::board($id);
        $page=max(1,absint($r->get_param('page')?:1)); $size=100;
        $archived=$r->get_param('archived')==='1'?1:0;
        $where=$wpdb->prepare(' WHERE board_id=%d AND archived=%d',$id,$archived).self::card_scope();
        $total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Store::table('cards').$where);
        $cards=$wpdb->get_results('SELECT * FROM '.Store::table('cards').$where.$wpdb->prepare(' ORDER BY position,id LIMIT %d OFFSET %d',$size,($page-1)*$size),ARRAY_A);
        return ['cards'=>array_map([Store::class,'card_data'],$cards),'total'=>$total,'has_more'=>$page*$size<$total];
    }

    public static function card($r): array {
        global $wpdb;
        $c=Access::card((int)$r['id']); $c=Store::card_data($c);
        $page=max(1,absint($r->get_param('activity_page')?:1));
        $activities=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::table('activity').' WHERE card_id=%d ORDER BY id DESC LIMIT 51 OFFSET %d',$c['id'],($page-1)*50),ARRAY_A);
        $c['activity_more']=count($activities)>50; $c['activity']=array_slice($activities,0,50);
        return $c;
    }

    public static function save_card($r): array {
        global $wpdb;
        $input = self::body($r);
        $card = Store::save_card($input, Access::card((int)$r['id'],'edit_cards'));
        if (array_key_exists('checklists',$input) || !empty($input['use_board_workflow']) || !empty($input['disable_workflow'])) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::table('activity').' WHERE card_id=%d ORDER BY id DESC LIMIT 51', $card['id']), ARRAY_A);
            $card['activity'] = array_slice($rows, 0, 50); $card['activity_more'] = count($rows) > 50;
        }
        return $card;
    }

    public static function complete_task($r): array {
        global $wpdb;
        $input = self::body($r);
        if (array_diff(array_keys($input), ['version','checklist_id','item_id'])) { throw new HttpError('Esta ação permite apenas concluir uma tarefa atribuída a você.', 403); }
        $card = Store::complete_task((int)$r['id'], $input);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::table('activity').' WHERE card_id=%d ORDER BY id DESC LIMIT 51', $card['id']), ARRAY_A);
        $card['activity'] = array_slice($rows, 0, 50); $card['activity_more'] = count($rows) > 50;
        return $card;
    }

    public static function delete_card($r): array {
        $c=Access::card((int)$r['id'],'delete_cards');
        Store::transaction(static function() use($c) { global $wpdb; foreach (['notifications','activity','task_assignees'] as $t) { $wpdb->delete(Store::table($t),['card_id'=>$c['id']]); } $wpdb->delete(Store::table('cards'),['id'=>$c['id']]); });
        return ['deleted'=>true];
    }

    public static function comment($r): array {
        $c=Access::card((int)$r['id'],'comment'); $in=self::body($r);
        $text=sanitize_textarea_field(substr((string)($in['content']??''),0,8000));
        if ($text==='') { throw new HttpError('Escreva uma interação.',422); }
        Store::activity((int)$c['id'],'comment',$text); return ['saved'=>true];
    }

    public static function notify($r): array {
        $c=Access::card((int)$r['id'],'notify'); $in=self::body($r); $s=Settings::get();
        $channel=$in['channel']??'';
        if (!in_array($channel,['email','sms'],true) || !$s[$channel.'_enabled']) { throw new HttpError('Este canal está desativado nas configurações.',422); }
        if (!$c['consent_'.$channel]) { throw new HttpError('Registre a autorização do visitante para este canal antes de enviar.',422); }
        $recipient=$channel==='email'?$c['email']:$c['phone'];
        if (!$recipient) { throw new HttpError('Preencha o contato do visitante.',422); }
        $subject=Store::name($in['subject']??'Mensagem de '.Settings::get()['brand_name']);
        $body=sanitize_textarea_field(substr((string)($in['body']??''),0,4000));
        if (!$body) { throw new HttpError('Escreva a mensagem.',422); }
        $id=Notifications::enqueue('manual:'.wp_generate_uuid4(),$channel,$recipient,$subject,$body,(int)$c['id']);
        Store::activity((int)$c['id'],'notification','Comunicação por '.$channel.' adicionada à fila.');
        return ['id'=>$id,'state'=>'queued'];
    }

    public static function profile($r): array {
        $in=self::body($r); $u=wp_get_current_user(); $data=['ID'=>$u->ID];
        if (!empty($in['name'])) { $data['display_name']=Store::name($in['name']); }
        $email=isset($in['email'])?strtolower(sanitize_email($in['email'])):$u->user_email;
        if (!is_email($email)) { throw new HttpError('Informe um e-mail válido.',422); }
        if ($email!==$u->user_email || !empty($in['password'])) {
            if (!wp_check_password((string)($in['current_password']??''),$u->user_pass,$u->ID)) { throw new HttpError('Confirme sua senha atual para alterar e-mail ou senha.',403); }
            $data['user_email']=$email;
        }
        if (!empty($in['password'])) { if (strlen($in['password'])<10) { throw new HttpError('Use uma senha com ao menos 10 caracteres.',422); } $data['user_pass']=$in['password']; }
        $phone=isset($in['phone'])?Store::phone((string)$in['phone']):(string)get_user_meta($u->ID,'visitas_phone',true);
        $id=wp_update_user($data); if (is_wp_error($id)) { throw new HttpError($id->get_error_message(),422); }
        update_user_meta($u->ID,'visitas_phone',$phone);
        if (isset($in['sms_optin'])) { update_user_meta($u->ID,'visitas_sms_optin',rest_sanitize_boolean($in['sms_optin'])?'1':'0'); }
        if (isset($in['email_optout'])) { update_user_meta($u->ID,'visitas_email_optout',rest_sanitize_boolean($in['email_optout'])?'1':'0'); }
        if (!empty($in['password'])) { wp_set_auth_cookie($u->ID,false,is_ssl()); }
        return ['saved'=>true,'reauthenticate'=>!empty($in['password'])];
    }

    public static function users($r): array {
        Access::require_cap('manage_users'); $out=[];
        foreach (get_users(['number'=>-1,'orderby'=>'display_name']) as $u) {
            if (!Access::can('access',(int)$u->ID) && !array_filter($u->roles,static fn($role)=>str_starts_with($role,'visitas_'))) { continue; }
            $out[]=['id'=>$u->ID,'name'=>$u->display_name,'login'=>$u->user_login,'email'=>$u->user_email,'roles'=>$u->roles,'is_admin'=>Access::admin($u),'phone'=>(string)get_user_meta($u->ID,'visitas_phone',true)];
        }
        return ['users'=>$out,'roles'=>Access::roles()];
    }

    public static function save_user($r): array {
        Access::require_cap('manage_users'); $in=self::body($r); $id=absint($r['id']??0); $existing=$id?get_userdata($id):null;
        if ($id && (!$existing || Access::admin($existing) || $id===get_current_user_id())) { throw new HttpError('Edite seu usuário em Meu perfil. Administradores são gerenciados pelo WordPress.',403); }
        if ($existing && array_filter($existing->roles,static fn($role)=>!str_starts_with($role,'visitas_'))) { throw new HttpError('Este usuário tem perfis de outro sistema. Gerencie-o no WordPress.',403); }
        $roles=(array)($in['roles']??[]);
        if (!$roles) { throw new HttpError('Selecione pelo menos um perfil.',422); }
        $available=array_column(Access::roles(),'key');
        foreach ($roles as $role) {
            if (!in_array($role,$available,true)) { throw new HttpError('Perfil inválido.',422); }
            if (!Access::admin()) { foreach ((array)get_role($role)->capabilities as $cap=>$grant) { if ($grant && $cap!=='read' && !current_user_can($cap)) { throw new HttpError('Você não pode conceder um perfil com permissões superiores às suas.',403); } } }
        }
        if ($existing && !Access::admin()) {
            foreach ($existing->allcaps as $cap=>$grant) { if ($grant && str_starts_with($cap,'visitas_') && !current_user_can($cap)) { throw new HttpError('Você não pode editar um usuário com permissões superiores às suas.',403); } }
        }
        $name=Store::name($in['name']??''); $email=strtolower(sanitize_email($in['email']??''));
        if (!is_email($email)) { throw new HttpError('Informe um e-mail válido.',422); }
        $phone=Store::phone((string)($in['phone']??''));
        $data=['display_name'=>$name,'user_email'=>$email];
        if ($existing) { $data['ID']=$id; } else { $data['user_login']=sanitize_user($in['login']??'',true); if (!$data['user_login']) { throw new HttpError('Informe o nome de usuário.',422); } $data['role']=$roles[0]; }
        if (!$existing || !empty($in['password'])) { $pass=(string)($in['password']??''); if (strlen($pass)<10) { throw new HttpError('Use uma senha com ao menos 10 caracteres.',422); } $data['user_pass']=$pass; }
        $result=$existing?wp_update_user($data):wp_insert_user($data);
        if (is_wp_error($result)) { throw new HttpError($result->get_error_message(),422); }
        $u=get_userdata($result); $u->set_role($roles[0]); foreach (array_slice($roles,1) as $role) { $u->add_role($role); }
        update_user_meta($result,'visitas_phone',$phone);
        return ['id'=>$result,'saved'=>true];
    }

    public static function save_role($r): array {
        self::admin(); $in=self::body($r); $key=sanitize_key($in['key']??'');
        if (!preg_match('/^visitas_[a-z0-9_]{1,52}$/',$key)) { throw new HttpError('O identificador deve começar com visitas_ e conter apenas letras minúsculas, números e sublinhados.',422); }
        if ($key === 'visitas_coordenacao') {
            foreach (Access::capabilities() as $cap=>$label) {
                if (!empty($in['caps'][$cap]) && !in_array($cap, Access::coordination_caps(), true)) { throw new HttpError('Coordenação permite somente consulta e conclusão de tarefas próprias. Use outro perfil para gestão.', 422); }
            }
        }
        $name=Store::name($in['name']??''); $role=get_role($key);
        if (!$role) { add_role($key,$name,['read'=>true]); $role=get_role($key); }
        foreach (Access::capabilities() as $cap=>$label) {
            if (!empty($in['caps'][$cap])) { $role->add_cap('visitas_'.$cap); } else { $role->remove_cap('visitas_'.$cap); }
        }
        // Only plugin capabilities can be edited. No WordPress administrative privileges are granted here.
        $roles=wp_roles(); $roles->roles[$key]['name']=$name; update_option($roles->role_key,$roles->roles);
        return ['saved'=>true,'roles'=>Access::roles()];
    }

    public static function upload_asset($r): array {
        self::admin();
        $files=$r->get_file_params();
        if (empty($files['file']) || ($files['file']['size']??0)>5*MB_IN_BYTES) { throw new HttpError('Escolha uma imagem PNG, JPG, WebP ou ICO de até 5 MB.',422); }
        require_once ABSPATH.'wp-admin/includes/file.php';
        $uploaded=wp_handle_upload($files['file'],['test_form'=>false,'mimes'=>['png'=>'image/png','jpg|jpeg'=>'image/jpeg','webp'=>'image/webp','ico'=>'image/x-icon']]);
        if (!empty($uploaded['error'])) { throw new HttpError($uploaded['error'],422); }
        $id=wp_insert_attachment(['post_mime_type'=>$uploaded['type'],'post_title'=>sanitize_file_name(pathinfo($files['file']['name'],PATHINFO_FILENAME)),'post_status'=>'inherit'],$uploaded['file']);
        if ($id) { require_once ABSPATH.'wp-admin/includes/image.php'; wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$uploaded['file'])); }
        return ['url'=>$uploaded['url'],'id'=>$id];
    }

    public static function notifications($r): array {
        global $wpdb;
        $logs=$r->get_param('logs')==='1'; $page=max(1,absint($r->get_param('page')?:1));
        if ($logs) { Access::require_cap('view_logs'); }
        $where=$logs?" WHERE channel<>'inapp'":$wpdb->prepare(" WHERE channel='inapp' AND user_id=%d",get_current_user_id());
        $rows=$wpdb->get_results('SELECT * FROM '.Store::table('notifications').$where.' ORDER BY id DESC',ARRAY_A); $out=[]; $unread=0;
        foreach ($rows as $n) {
            if ($n['card_id']) { try { Access::card((int)$n['card_id']); } catch (HttpError $e) { continue; } }
            if (!$n['read_at']) { $unread++; }
            unset($n['event_key']); $out[]=$n;
        }
        return ['items'=>array_slice($out,($page-1)*50,50),'has_more'=>$page*50<count($out),'unread'=>$unread];
    }

    public static function read_notifications($r): array {
        global $wpdb;
        $wpdb->update(Store::table('notifications'),['read_at'=>current_time('mysql',true)],['channel'=>'inapp','user_id'=>get_current_user_id()]); return ['ok'=>true];
    }
}

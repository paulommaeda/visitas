<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Notifications {
    public static function boot(): void {
        add_filter('cron_schedules', static function ($s) { $s['visitas_minute']=['interval'=>60,'display'=>'Visitas: a cada minuto']; return $s; });
        add_action('visitas_queue',[self::class,'process']);
        add_action('visitas_due',[self::class,'due']);
        self::schedule();
    }

    public static function schedule(): void {
        // Activation runs before the plugin's cron filter on a fresh installation.
        add_filter('cron_schedules', static function ($s) { $s['visitas_minute']=['interval'=>60,'display'=>'Visitas: a cada minuto']; return $s; });
        if (!wp_next_scheduled('visitas_queue')) { wp_schedule_event(time()+60,'visitas_minute','visitas_queue'); }
        if (!wp_next_scheduled('visitas_due')) { wp_schedule_event(time()+120,'hourly','visitas_due'); }
    }

    public static function render(string $template, array $card): string {
        $b=Store::row('boards',(int)$card['board_id']); $u=get_userdata((int)$card['assigned_to']);
        return strtr($template,[
            '{nome}'=>$card['title'],'{igreja}'=>Settings::get()['brand_name'],'{quadro}'=>$b['name'] ?? '',
            '{responsavel}'=>$u ? $u->display_name : '', '{link}'=>add_query_arg(['board'=>$card['board_id'],'card'=>$card['id']],Portal::url()),
            '{prazo}'=>$card['due_at'] ? wp_date('d/m/Y H:i',strtotime($card['due_at'].' UTC')) : 'sem prazo',
        ]);
    }

    public static function event(string $event, array $card): void {
        $s=Settings::get();
        if ($event === 'welcome' && !$s['welcome_enabled']) { return; }
        if ($event === 'assignment' && !$s['assignment_enabled']) { return; }
        if ($event === 'due' && !$s['due_enabled']) { return; }
        $subject=self::render($s[$event.'_subject'],$card); $body=self::render($s[$event.'_body'],$card);
        $key=$event.':'.$card['id'].':'.($event==='due' ? md5($card['due_at'].':'.$card['assigned_to']) : $card['version']);
        if ($event==='welcome') {
            foreach (['email','sms'] as $channel) {
                $recipient=$channel==='email' ? $card['email'] : $card['phone'];
                if ($s[$channel.'_enabled'] && $card['consent_'.$channel] && $recipient) { self::enqueue($key,$channel,$recipient,$subject,$body,(int)$card['id'],0); }
            }
        } else {
            $uid=(int)$card['assigned_to'];
            if (!$uid || !Store::can_assign((int)$card['board_id'],$uid)) { return; }
            $u=get_userdata($uid);
            self::enqueue($key,'inapp',(string)$uid,$subject,$body,(int)$card['id'],$uid);
            if ($s['email_enabled'] && get_user_meta($uid,'visitas_email_optout',true)!=='1') { self::enqueue($key,'email',$u->user_email,$subject,$body,(int)$card['id'],$uid); }
            $phone=(string)get_user_meta($uid,'visitas_phone',true);
            if ($s['sms_enabled'] && $phone && get_user_meta($uid,'visitas_sms_optin',true)==='1') { self::enqueue($key,'sms',$phone,$subject,$body,(int)$card['id'],$uid); }
        }
    }

    public static function enqueue(string $key, string $channel, string $recipient, string $subject, string $body, int $card=0, int $user=0, string $initial_state='queued'): int {
        global $wpdb;
        $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Store::table('notifications').' WHERE event_key=%s AND channel=%s AND recipient=%s',$key,$channel,$recipient));
        if ($existing) { return (int)$existing; }
        $now=current_time('mysql',true);
        // Unique key is the final guard if two cron requests discover the same reminder.
        $insert=$wpdb->insert(Store::table('notifications'),[
            'card_id'=>$card,'user_id'=>$user,'event_key'=>$key,'channel'=>$channel,'recipient'=>$recipient,
            'subject'=>sanitize_text_field($subject),'body'=>sanitize_textarea_field($body),
            'state'=>$channel==='inapp'?'available':$initial_state,'attempts'=>0,
            'provider_id'=>$channel==='sms'?wp_generate_uuid4():'','detail'=>'',
            'next_attempt'=>$now,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return $insert ? (int)$wpdb->insert_id : (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Store::table('notifications').' WHERE event_key=%s AND channel=%s AND recipient=%s',$key,$channel,$recipient));
    }

    public static function test_sms(array $input): array {
        $phone=Store::phone((string)($input['phone']??''));
        $body=sanitize_textarea_field((string)($input['message']??''));
        $request_id=(string)($input['request_id']??'');
        if (!$phone || !$body) { throw new HttpError('Informe o número com DDD e a mensagem de teste.',422); }
        if (preg_match_all('/./us',$body)>480) { throw new HttpError('Use uma mensagem de teste com até 480 caracteres.',422); }
        if (!preg_match('/^[a-zA-Z0-9-]{10,80}$/',$request_id)) { throw new HttpError('Identificador do teste inválido. Reabra as configurações e tente novamente.',422); }
        $s=Settings::get();
        if (!$s['sms_username'] || !Settings::sms_password()) { throw new HttpError('Salve o usuário e a senha do SMS Gateway antes de testar.',422); }
        $key='test:'.get_current_user_id().':'.$request_id;
        global $wpdb;
        $prior=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::table('notifications')." WHERE event_key=%s AND channel='sms' LIMIT 1",$key),ARRAY_A);
        if ($prior && ($prior['recipient']!==$phone || $prior['body']!==$body)) { throw new HttpError('Este identificador já foi usado em outro teste. Inicie um novo teste.',409); }
        // A direct test is an explicit administrator action. It does not enable automations,
        // require a visitor, enter the cron queue or retry an uncertain send automatically.
        $id=self::enqueue($key,'sms',$phone,'Teste de SMS',$body,0,get_current_user_id(),'test_ready');
        if (!$id) { throw new HttpError('Não foi possível registrar o teste.',500); }
        $n=Store::row('notifications',$id);
        if ($n['body']!==$body) { throw new HttpError('Este identificador já foi usado com outra mensagem. Inicie um novo teste.',409); }
        if (!Store::update('notifications',['state'=>'testing','attempts'=>1,'updated_at'=>current_time('mysql',true)],['id'=>$id,'state'=>'test_ready'])) { return self::test_result(Store::row('notifications',$id)); }
        try {
            $r=self::sms_request('POST','',[
                'id'=>$n['provider_id'],'textMessage'=>['text'=>$body],'phoneNumbers'=>[$phone],
                'simNumber'=>(int)$s['sms_sim'],'ttl'=>3600,'withDeliveryReport'=>true,
            ]);
            $detail='SMS Gateway retornou HTTP '.$r['_http_status'].'. Estado: '.sanitize_text_field($r['state']??'Pending').'.';
            Store::update('notifications',['state'=>self::provider_state($r['state']??'Pending'),'detail'=>$detail,'updated_at'=>current_time('mysql',true)],['id'=>$id]);
        } catch (\Throwable $e) {
            $state=$e->getCode()===422?'failed':'unknown';
            $detail=sanitize_text_field($e->getMessage());
            if ($state==='unknown') { $detail.=' Consulte o status antes de iniciar outro teste; o gateway pode ter recebido a solicitação.'; }
            Store::update('notifications',['state'=>$state,'detail'=>$detail,'updated_at'=>current_time('mysql',true)],['id'=>$id]);
        }
        return self::test_result(Store::row('notifications',$id));
    }

    private static function test_result(array $n): array {
        return ['id'=>(int)$n['id'],'recipient'=>$n['recipient'],'provider_id'=>$n['provider_id'],'state'=>$n['state'],'detail'=>$n['detail']];
    }

    public static function test_status(int $id): array {
        $n=Store::row('notifications',$id);
        if (!$n || $n['channel']!=='sms' || !str_starts_with($n['event_key'],'test:') || (int)$n['user_id']!==get_current_user_id()) { throw new HttpError('Teste não encontrado.',404); }
        try {
            $r=self::sms_request('GET',$n['provider_id']);
            Store::update('notifications',['state'=>self::provider_state($r['state']??'Pending'),'detail'=>'SMS Gateway retornou HTTP '.$r['_http_status'].'. Estado: '.sanitize_text_field($r['state']??'Pending').'.','updated_at'=>current_time('mysql',true)],['id'=>$id]);
            return self::test_result(Store::row('notifications',$id));
        } catch (\Throwable $e) { return array_merge(self::test_result($n),['query_error'=>sanitize_text_field($e->getMessage())]); }
    }

    public static function due(): void {
        global $wpdb;
        $s=Settings::get(); if (!$s['due_enabled']) { return; }
        $until=gmdate('Y-m-d H:i:s',time()+$s['reminder_hours']*HOUR_IN_SECONDS);
        $cards=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::table('cards').' WHERE archived=0 AND completed=0 AND assigned_to>0 AND due_at IS NOT NULL AND due_at<=%s AND due_at>=%s ORDER BY due_at LIMIT 500',$until,gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS)),ARRAY_A);
        foreach ($cards as $card) { self::event('due',$card); }
    }

    private static function still_allowed(array $n): bool {
        $s=Settings::get();
        if (empty($s[$n['channel'].'_enabled'])) { return false; }
        if (!$n['card_id']) { return true; }
        $c=Store::row('cards',(int)$n['card_id']);
        if (!$c || $c['archived']) { return false; }
        if (str_starts_with($n['event_key'],'due:') && ($c['completed'] || md5($c['due_at'].':'.$c['assigned_to'])!==explode(':',$n['event_key'])[2])) { return false; }
        if ($n['user_id']) {
            $uid=(int)$n['user_id']; $u=get_userdata($uid);
            if (!$u || (int)$c['assigned_to']!==$uid || !Store::can_assign((int)$c['board_id'],$uid)) { return false; }
            return $n['channel']==='email' ? get_user_meta($uid,'visitas_email_optout',true)!=='1' && $u->user_email===$n['recipient'] : get_user_meta($uid,'visitas_sms_optin',true)==='1' && get_user_meta($uid,'visitas_phone',true)===$n['recipient'];
        }
        return !empty($c['consent_'.$n['channel']]) && $n['recipient']===($n['channel']==='email'?$c['email']:$c['phone']);
    }

    public static function process(): void {
        global $wpdb;
        $t=Store::table('notifications'); $now=current_time('mysql',true);
        $wpdb->query($wpdb->prepare("UPDATE $t SET state='queued' WHERE state='processing' AND updated_at<%s",gmdate('Y-m-d H:i:s',time()-600)));
        $batch=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE state='queued' AND next_attempt<=%s ORDER BY id LIMIT 10",$now),ARRAY_A);
        foreach ($batch as $n) {
            if (!Store::update('notifications',['state'=>'processing','updated_at'=>$now],['id'=>$n['id'],'state'=>'queued'])) { continue; }
            if (!self::still_allowed($n)) { Store::update('notifications',['state'=>'cancelled','detail'=>'Envio cancelado: preferência ou acesso alterado.','updated_at'=>$now],['id'=>$n['id']]); continue; }
            $attempt=(int)$n['attempts']+1;
            try {
                $s=Settings::get(); $detail=''; $state='accepted';
                if ($n['channel']==='email') {
                    $headers=['Content-Type: text/plain; charset=UTF-8'];
                    if ($s['from_email']) { $headers[]='From: '.sanitize_text_field($s['from_name']).' <'.$s['from_email'].'>'; }
                    if (!wp_mail($n['recipient'],$n['subject'],$n['body'],$headers)) { throw new \RuntimeException('O WordPress não aceitou o e-mail para envio.'); }
                    $detail='Aceito pelo serviço de e-mail do WordPress.';
                } else {
                    $response=self::sms_request('POST','',[
                        'id'=>$n['provider_id'],'textMessage'=>['text'=>$n['body']], 'phoneNumbers'=>[$n['recipient']],
                        'simNumber'=>(int)$s['sms_sim'],'ttl'=>3600,'withDeliveryReport'=>true,
                    ]);
                    $state=self::provider_state($response['state'] ?? 'Pending');
                    $detail='Estado do SMS Gateway: '.sanitize_text_field($response['state'] ?? 'Pending');
                }
                Store::update('notifications',['state'=>$state,'detail'=>$detail,'attempts'=>$attempt,'updated_at'=>current_time('mysql',true)],['id'=>$n['id']]);
            } catch (\Throwable $e) {
                $final=$attempt>=4 || $e->getCode()===422;
                Store::update('notifications',[
                    'state'=>$final?'failed':'queued','attempts'=>$attempt,'detail'=>sanitize_text_field($e->getMessage()),
                    'next_attempt'=>gmdate('Y-m-d H:i:s',time()+(60*(2**$attempt))),'updated_at'=>current_time('mysql',true),
                ],['id'=>$n['id']]);
            }
        }
        self::sync();
    }

    public static function provider_state(string $state): string {
        return match($state) { 'Delivered'=>'delivered','Failed','Cancelled'=>'failed','Sent'=>'sent',default=>'accepted' };
    }

    private static function sms_request(string $method, string $id='', array $payload=[]): array {
        $s=Settings::get(); $password=Settings::sms_password();
        if (!$s['sms_username'] || !$password) { throw new \RuntimeException('Configure o usuário e a senha do SMS Gateway.',422); }
        $base=untrailingslashit($s['sms_url']);
        $parts=wp_parse_url($base);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { throw new \RuntimeException('URL do SMS Gateway inválida.',422); }
        if ($s['sms_mode']!=='local' && ($parts['scheme']??'')!=='https') { throw new \RuntimeException('O SMS Gateway requer HTTPS neste modo.',422); }
        $path=$s['sms_mode']==='local'?'/message':'/messages';
        $url=$base.$path.($id?'/'.rawurlencode($id):'');
        if ($method==='POST' && $s['sms_device'] && $s['sms_mode']!=='local') { $payload['deviceId']=$s['sms_device']; }
        $args=['method'=>$method,'timeout'=>12,'redirection'=>0,'sslverify'=>true,'headers'=>[
            'Authorization'=>'Basic '.base64_encode($s['sms_username'].':'.$password),'Content-Type'=>'application/json','Accept'=>'application/json',
        ]];
        if ($method==='POST') { $args['body']=wp_json_encode($payload); }
        // Only an administrator can set this endpoint. Local mode intentionally permits LAN addresses.
        $r=wp_remote_request($url,$args);
        if (is_wp_error($r)) { throw new \RuntimeException('Não foi possível conectar ao SMS Gateway. Verifique endereço e rede.'); }
        $code=wp_remote_retrieve_response_code($r); $body=json_decode(wp_remote_retrieve_body($r),true);
        if ($code===409 && $method==='POST') { return self::sms_request('GET',(string)$payload['id']); }
        if ($code<200 || $code>=300) {
            $hint=match($code) { 401,403=>' Verifique as credenciais e permissões do gateway.',404=>' Confira o modo e a URL base do gateway.',429=>' Aguarde antes de tentar novamente.',default=>'' };
            throw new \RuntimeException('SMS Gateway retornou HTTP '.$code.'.'.$hint,($code>=400 && $code<500 && $code!==429)?422:0);
        }
        if (!is_array($body) || empty($body['id'])) { throw new \RuntimeException('O SMS Gateway retornou uma resposta sem identificação da mensagem.'); }
        if ($body['id']!==($method==='POST'?$payload['id']:$id)) { throw new \RuntimeException('O SMS Gateway não preservou o identificador da mensagem.',422); }
        $body['_http_status']=$code;
        return $body;
    }

    public static function sync(): void {
        global $wpdb;
        $test_only=Settings::get()['sms_enabled']?'':" AND event_key LIKE 'test:%'";
        $rows=$wpdb->get_results('SELECT * FROM '.Store::table('notifications')." WHERE channel='sms' AND state IN ('accepted','sent')".$test_only." AND created_at>='".esc_sql(gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS))."' ORDER BY updated_at LIMIT 5",ARRAY_A);
        foreach ($rows as $n) {
            try {
                $r=self::sms_request('GET',$n['provider_id']);
                Store::update('notifications',['state'=>self::provider_state($r['state']??'Pending'),'detail'=>'Estado do SMS Gateway: '.sanitize_text_field($r['state']??'Pending'),'updated_at'=>current_time('mysql',true)],['id'=>$n['id']]);
            } catch (\Throwable $e) { /* Preserve the last confirmed state; never resubmit an accepted message. */ }
        }
    }
}

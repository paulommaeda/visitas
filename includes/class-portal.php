<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Portal {
    public static string $error='';
    public static string $notice='';

    public static function url(): string {
        $id=(int)get_option('visitas_portal_page');
        return $id && get_post_status($id)==='publish' ? get_permalink($id) : add_query_arg('visitas_app','1',home_url('/'));
    }

    public static function is_portal(): bool {
        if (isset($_GET['visitas_app'])) { return true; }
        if (Settings::get()['use_as_home']??false) { if (is_front_page()) { return true; } }
        $post=get_queried_object();
        return is_page((int)get_option('visitas_portal_page')) || ($post instanceof \WP_Post && has_shortcode($post->post_content,'visitas_portal'));
    }

    public static function boot(): void {
        add_shortcode('visitas_portal', static fn()=>'<p><a href="'.esc_url(self::url()).'">Abrir o portal de acolhimento</a></p>');
        add_action('template_redirect',static function() {
            if (!self::is_portal()) { return; }
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
            nocache_headers(); header('X-Robots-Tag: noindex, nofollow',true);
            self::post();
            if (isset($_GET['visitas_app'])) { require VISITAS_DIR.'templates/portal.php'; exit; }
        },0);
        add_filter('template_include',static fn($template)=>self::is_portal()?VISITAS_DIR.'templates/portal.php':$template,PHP_INT_MAX);
        add_action('login_init',static function() {
            $action=sanitize_key($_REQUEST['action']??'login');
            if (in_array($action,['postpass','logout'],true)) { return; }
            $args=[];
            if (in_array($action,['lostpassword','retrievepassword'],true)) { $args['screen']='lost'; }
            if (in_array($action,['rp','resetpass'],true)) { $args=['screen'=>'reset','key'=>sanitize_text_field($_GET['key']??''),'login'=>sanitize_user($_GET['login']??'')]; }
            wp_safe_redirect(add_query_arg($args,self::url())); exit;
        });
        add_action('admin_menu',static function() {
            add_menu_page('Visitas','Visitas','manage_options','visitas',static function() {
                echo '<div class="wrap"><h1>Visitas — Acolhimento</h1><p>Quadros, identidade, equipe e permissões ficam no portal.</p><p><a class="button button-primary" href="'.esc_url(self::url()).'">Abrir o portal</a></p><p>Shortcode: <code>[visitas_portal]</code></p></div>';
            },'dashicons-groups',25);
        });
        add_filter('plugin_action_links_'.plugin_basename(VISITAS_FILE),static function($links) { array_unshift($links,'<a href="'.esc_url(self::url()).'">Abrir portal</a>'); return $links; });
    }

    private static function limiter(string $scope): string {
        return 'visitas_rate_'.hash_hmac('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??''),wp_salt('nonce'));
    }

    private static function post(): void {
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || empty($_POST['visitas_action'])) { return; }
        $action=sanitize_key($_POST['visitas_action']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'visitas_'.$action)) { self::$error='A sessão do formulário expirou. Recarregue a página.'; return; }
        if ($action==='logout') { wp_logout(); wp_safe_redirect(self::url()); exit; }
        $bucket=self::limiter($action);
        $count=(int)get_transient($bucket);
        if ($count>=10) { self::$error='Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.'; return; }
        if ($action==='login') {
            $login=sanitize_text_field(wp_unslash($_POST['login']??''));
            $user_bucket='visitas_user_rate_'.hash_hmac('sha256',strtolower($login),wp_salt('nonce'));
            if ((int)get_transient($user_bucket)>=20) { self::$error='Muitas tentativas. Aguarde 15 minutos.'; return; }
            $user=wp_signon(['user_login'=>$login,'user_password'=>(string)wp_unslash($_POST['password']??''),'remember'=>!empty($_POST['remember'])],is_ssl());
            if (is_wp_error($user)) {
                set_transient($bucket,$count+1,15*MINUTE_IN_SECONDS); set_transient($user_bucket,(int)get_transient($user_bucket)+1,15*MINUTE_IN_SECONDS);
                self::$error='Não foi possível entrar. Confira seus dados ou procure a administração.'; return;
            }
            delete_transient($bucket); delete_transient($user_bucket); wp_safe_redirect(self::url()); exit;
        }
        set_transient($bucket,$count+1,15*MINUTE_IN_SECONDS);
        if ($action==='lost') {
            $login=sanitize_text_field(wp_unslash($_POST['login']??''));
            $u=get_user_by(is_email($login)?'email':'login',$login);
            if ($u && Access::can('access',(int)$u->ID)) {
                $key=get_password_reset_key($u);
                if (!is_wp_error($key)) {
                    $link=add_query_arg(['screen'=>'reset','key'=>$key,'login'=>$u->user_login],self::url());
                    wp_mail($u->user_email,'Redefinição de senha — '.Settings::get()['app_name'],"Você solicitou uma nova senha. Acesse o link abaixo para defini-la:\n\n".$link."\n\nSe você não solicitou, ignore esta mensagem.",['Content-Type: text/plain; charset=UTF-8']);
                }
            }
            self::$notice='Se houver uma conta autorizada com esses dados, você receberá um link por e-mail.';
        }
        if ($action==='reset') {
            $u=check_password_reset_key(sanitize_text_field(wp_unslash($_POST['key']??'')),sanitize_user(wp_unslash($_POST['login']??'')));
            if (is_wp_error($u) || !Access::can('access',(int)$u->ID)) { self::$error='Este link é inválido ou expirou. Solicite uma nova redefinição.'; return; }
            $pass=(string)wp_unslash($_POST['password']??'');
            if (strlen($pass)<10 || $pass!==(string)wp_unslash($_POST['password_confirm']??'')) { self::$error='Use ao menos 10 caracteres e repita a mesma senha nos dois campos.'; return; }
            reset_password($u,$pass); wp_safe_redirect(add_query_arg('reset','success',self::url())); exit;
        }
    }
}

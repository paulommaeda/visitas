<?php
defined('ABSPATH') || exit;
use Shalom\Visitas\{Settings,Access,Portal};
$s=Settings::public_brand();
$authenticated=is_user_logged_in() && Access::can('access');
$screen=in_array($_GET['screen']??'', ['lost','reset'],true)?$_GET['screen']:'login';
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="<?php echo esc_attr($s['secondary']); ?>">
<title><?php echo esc_html($s['app_name'].' · '.$s['brand_name']); ?></title>
<?php if ($s['favicon_url']): ?><link rel="icon" href="<?php echo esc_url($s['favicon_url']); ?>"><?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url(VISITAS_URL.'assets/app.css?v='.VISITAS_VERSION); ?>">
<style><?php echo Settings::css(); // All values are validated colors, numbers, fixed font names or escaped URLs. ?></style>
</head><body class="<?php echo $authenticated?'v-portal':'v-login'; ?> <?php echo $s['density']==='compact'?'v-compact':''; ?>">
<?php if (!$authenticated): ?>
<main class="v-login-layout">
    <section class="v-login-story">
        <div class="v-login-brand"><?php if ($s['logo_url']): ?><img src="<?php echo esc_url($s['logo_url']); ?>" alt="<?php echo esc_attr($s['brand_name']); ?>"><?php else: ?><span class="v-brand-name"><?php echo esc_html($s['brand_name']); ?></span><?php endif; ?></div>
        <div><p class="v-eyebrow">CUIDADO QUE APROXIMA</p><h1><?php echo esc_html($s['login_title']); ?></h1><p class="v-login-description"><?php echo esc_html($s['login_description']); ?></p></div>
        <p class="v-login-tagline"><?php echo esc_html($s['tagline']); ?></p>
    </section>
    <section class="v-login-form-wrap"><div class="v-login-form">
        <div class="v-app-wordmark"><?php echo esc_html($s['app_name']); ?><span>PORTAL DE ACOLHIMENTO</span></div>
        <h2><?php echo $screen==='lost'?'Recuperar acesso':($screen==='reset'?'Definir nova senha':'Bem-vindo de volta'); ?></h2>
        <p class="v-muted"><?php echo $screen==='lost'?'Informe seu usuário ou e-mail para receber o link.':($screen==='reset'?'Escolha uma senha com pelo menos 10 caracteres.':'Entre para continuar cuidando de pessoas.'); ?></p>
        <?php if (Portal::$error): ?><div class="v-alert v-alert-error" role="alert"><?php echo esc_html(Portal::$error); ?></div><?php endif; ?>
        <?php if (Portal::$notice || ($_GET['reset']??'')==='success'): ?><div class="v-alert" role="status"><?php echo esc_html(Portal::$notice?:'Senha alterada. Entre com sua nova senha.'); ?></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(add_query_arg($screen==='login'?[]:['screen'=>$screen],Portal::url())); ?>">
            <input type="hidden" name="visitas_action" value="<?php echo esc_attr($screen); ?>"><?php wp_nonce_field('visitas_'.$screen); ?>
            <?php if ($screen==='reset'): ?>
                <input type="hidden" name="key" value="<?php echo esc_attr($_GET['key']??$_POST['key']??''); ?>"><input type="hidden" name="login" value="<?php echo esc_attr($_GET['login']??$_POST['login']??''); ?>">
                <label>Nova senha<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                <label>Repita a senha<input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label>
            <?php else: ?><label>Usuário ou e-mail<input name="login" type="text" required autocomplete="username" autofocus></label><?php endif; ?>
            <?php if ($screen==='login'): ?><label>Senha<span class="v-password"><input id="login-password" type="password" name="password" required autocomplete="current-password"><button type="button" id="toggle-password" aria-label="Mostrar senha">Mostrar</button></span></label><div class="v-login-options"><label class="v-check"><input type="checkbox" name="remember" value="1"> Manter conectado</label><a href="<?php echo esc_url(add_query_arg('screen','lost',Portal::url())); ?>">Esqueci a senha</a></div><?php endif; ?>
            <button class="v-btn v-primary v-full" type="submit"><?php echo $screen==='lost'?'Enviar link':($screen==='reset'?'Salvar nova senha':'Entrar no portal'); ?> <span aria-hidden="true">→</span></button>
        </form>
        <?php if ($screen!=='login'): ?><a class="v-back-login" href="<?php echo esc_url(Portal::url()); ?>">← Voltar para o login</a><?php endif; ?>
        <?php if (is_user_logged_in()): ?><form method="post"><input type="hidden" name="visitas_action" value="logout"><?php wp_nonce_field('visitas_logout'); ?><button class="v-btn v-full">Sair desta conta</button></form><?php endif; ?>
        <p class="v-login-help">Acesso exclusivo para a equipe autorizada.</p>
    </div></section>
</main>
<script>document.getElementById('toggle-password')?.addEventListener('click',function(){const i=document.getElementById('login-password');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Mostrar':'Ocultar';this.setAttribute('aria-label',i.type==='password'?'Mostrar senha':'Ocultar senha');});</script>
<?php else: ?>
<div id="visitas-app" aria-live="polite"><div class="v-boot"><span class="v-spinner"></span><p>Preparando seu espaço de cuidado…</p></div></div>
<div id="visitas-modal"></div><div id="visitas-toast" role="status" aria-live="polite"></div>
<form id="visitas-logout" method="post" action="<?php echo esc_url(Portal::url()); ?>" hidden><input type="hidden" name="visitas_action" value="logout"><?php wp_nonce_field('visitas_logout'); ?></form>
<script>window.VISITAS_CONFIG=<?php echo wp_json_encode(['api'=>rest_url('visitas/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'portal'=>Portal::url(),'admin_url'=>Access::admin()?admin_url():null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script defer src="<?php echo esc_url(VISITAS_URL.'assets/app.js?v='.VISITAS_VERSION); ?>"></script>
<script defer src="<?php echo esc_url(VISITAS_URL.'assets/forms.js?v='.VISITAS_VERSION); ?>"></script>
<script defer src="<?php echo esc_url(VISITAS_URL.'assets/admin.js?v='.VISITAS_VERSION); ?>"></script>
<script defer src="<?php echo esc_url(VISITAS_URL.'assets/dashboard.js?v='.VISITAS_VERSION); ?>"></script>
<script defer src="<?php echo esc_url(VISITAS_URL.'assets/workflow.js?v='.VISITAS_VERSION); ?>"></script>
<?php endif; ?>
</body></html>

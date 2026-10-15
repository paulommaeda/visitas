<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Settings {
    public static function defaults(): array {
        return [
            'brand_name' => 'Comunhão Cristã Shalom', 'app_name' => 'Acolher',
            'tagline' => 'Cada pessoa importa. Cada encontro é um começo.',
            'logo_url' => '', 'favicon_url' => '', 'primary' => '#001BE1', 'secondary' => '#000A52',
            'background' => '#F4F6FB', 'surface' => '#FFFFFF', 'text' => '#182445',
            'font' => 'Montserrat', 'font_url' => '', 'radius' => 16, 'density' => 'comfortable', 'use_as_home' => false,
            'login_mode' => 'gradient', 'login_color' => '#000A52', 'login_color_end' => '#001BE1',
            'login_image' => '', 'login_surface' => '#FFFFFF', 'login_text' => '#182445',
            'login_title' => 'Acolher é o primeiro passo.',
            'login_description' => 'Um espaço para cuidar de quem chega e cultivar relacionamentos.',
            'email_enabled' => false, 'sms_enabled' => false, 'welcome_enabled' => false,
            'assignment_enabled' => true, 'due_enabled' => true, 'reminder_hours' => 24,
            'from_name' => 'Comunhão Cristã Shalom', 'from_email' => '',
            'welcome_subject' => 'Que bom receber você, {nome}!',
            'welcome_body' => 'Olá, {nome}! Foi uma alegria receber você na {igreja}. Conte conosco. Esperamos encontrar você novamente!',
            'assignment_subject' => 'Um novo acompanhamento para você',
            'assignment_body' => 'Olá, {responsavel}. Você foi designado(a) para acompanhar {nome} no quadro {quadro}. Acesse: {link}',
            'due_subject' => 'Lembrete de acompanhamento: {nome}',
            'due_body' => 'Olá, {responsavel}. O acompanhamento de {nome}, no quadro {quadro}, tem prazo em {prazo}. Acesse: {link}',
            'sms_mode' => 'cloud', 'sms_url' => 'https://api.sms-gate.app/3rdparty/v1',
            'sms_username' => '', 'sms_secret' => '', 'sms_device' => '', 'sms_sim' => 1,
        ];
    }

    public static function get(): array {
        return array_merge(self::defaults(), (array) get_option('visitas_settings', []));
    }

    public static function public_brand(): array {
        return array_intersect_key(self::get(), array_flip([
            'brand_name','app_name','tagline','logo_url','favicon_url','primary','secondary','background','surface',
            'text','font','font_url','radius','density','login_mode','login_color','login_color_end','login_image',
            'login_surface','login_text','login_title','login_description',
        ]));
    }

    public static function editable(): array {
        $s = self::get();
        $s['sms_password_set'] = !empty($s['sms_secret']);
        unset($s['sms_secret']);
        return $s;
    }

    public static function save(array $input): array {
        $s = self::get();
        foreach (self::defaults() as $key => $default) {
            if (!array_key_exists($key, $input) || $key === 'sms_secret') { continue; }
            $value = $input[$key];
            if (is_bool($default)) { $s[$key] = rest_sanitize_boolean($value); }
            elseif (in_array($key, ['primary','secondary','background','surface','text','login_color','login_color_end','login_surface','login_text'], true)) {
                $s[$key] = sanitize_hex_color($value) ?: $default;
            } elseif (in_array($key, ['logo_url','favicon_url','font_url','login_image','sms_url'], true)) {
                $s[$key] = esc_url_raw((string) $value, ['http','https']);
            } elseif (str_ends_with($key, '_body')) { $s[$key] = sanitize_textarea_field(substr((string) $value, 0, 4000)); }
            elseif (is_int($default)) { $s[$key] = absint($value); }
            else { $s[$key] = sanitize_text_field(substr((string) $value, 0, 500)); }
        }
        if (!in_array($s['font'], ['Montserrat','Inter','Lato','Arial','Georgia','system-ui','Custom'], true)) { $s['font'] = 'Montserrat'; }
        if (!in_array($s['login_mode'], ['solid','gradient','image'], true)) { $s['login_mode'] = 'gradient'; }
        if (!in_array($s['density'], ['comfortable','compact'], true)) { $s['density'] = 'comfortable'; }
        if (!in_array($s['sms_mode'], ['cloud','local','private'], true)) { $s['sms_mode'] = 'cloud'; }
        if ($s['sms_mode'] === 'cloud') { $s['sms_url'] = 'https://api.sms-gate.app/3rdparty/v1'; }
        if ($s['sms_mode'] !== 'local' && strtolower((string) wp_parse_url($s['sms_url'], PHP_URL_SCHEME)) !== 'https') {
            throw new HttpError('Use HTTPS no servidor SMS em nuvem ou privado.', 422);
        }
        if (!empty($input['clear_sms_password'])) { $s['sms_secret'] = ''; }
        if (!empty($input['sms_password'])) { $s['sms_secret'] = self::encrypt((string) $input['sms_password']); }
        $s['radius'] = min(28, max(0, $s['radius']));
        $s['sms_sim'] = min(3, max(1, $s['sms_sim']));
        $s['reminder_hours'] = min(168, max(1, $s['reminder_hours']));
        $s['from_email'] = sanitize_email($s['from_email']);
        update_option('visitas_settings', $s, false);
        return self::editable();
    }

    private static function encrypt(string $value): string {
        if (!function_exists('openssl_encrypt')) { throw new HttpError('O servidor precisa da extensão OpenSSL para salvar a credencial.', 422); }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new HttpError('Não foi possível proteger a credencial.', 500); }
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function sms_password(): string {
        $raw = base64_decode(self::get()['sms_secret'], true);
        if (!$raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { return ''; }
        return (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    }

    public static function css(): string {
        $s = self::public_brand();
        $css = ':root{';
        foreach (['primary','secondary','background','surface','text','login_color','login_color_end','login_surface','login_text'] as $k) {
            $css .= '--v-' . str_replace('_','-', $k) . ':' . (sanitize_hex_color($s[$k]) ?: '#001BE1') . ';';
        }
        $css .= '--v-radius:' . absint($s['radius']) . 'px;--v-font:"' . esc_attr($s['font'] === 'Custom' ? 'VisitasCustom' : $s['font']) . '",Arial,sans-serif;}';
        if ($s['font_url']) { $css .= '@font-face{font-family:VisitasCustom;src:url("' . esc_url($s['font_url']) . '");font-display:swap;}'; }
        if ($s['login_mode'] === 'solid') { $css .= '.v-login{background:var(--v-login-color);}'; }
        if ($s['login_mode'] === 'image' && $s['login_image']) {
            $css .= '.v-login{background:linear-gradient(100deg,rgba(0,0,0,.66),rgba(0,0,0,.25)),url("' . esc_url($s['login_image']) . '") center/cover fixed;}';
        }
        return $css;
    }
}

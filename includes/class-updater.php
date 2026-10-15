<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

/** Native WordPress updates from the public repository's installable packages. */
final class Updater {
    private const REPOSITORY = 'https://github.com/paulommaeda/visitas';
    private const BASE = 'https://raw.githubusercontent.com/paulommaeda/visitas/main/dist/';
    private const CACHE = 'visitas_update_manifest';

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'update'], 10, 4);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 4);
        foreach (['plugin_action_links_', 'network_admin_plugin_action_links_'] as $hook) {
            add_filter($hook.plugin_basename(VISITAS_FILE), [self::class, 'links']);
        }
        add_action('admin_post_visitas_check_updates', [self::class, 'manual_check']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('network_admin_notices', [self::class, 'notice']);
    }

    private static function allowed(): bool {
        return Access::admin() && current_user_can('update_plugins');
    }

    private static function check_url(): string {
        return wp_nonce_url(admin_url('admin-post.php?action=visitas_check_updates'), 'visitas_check_updates');
    }

    public static function links(array $links): array {
        if (self::allowed()) {
            $links['visitas_updates'] = '<a href="'.esc_url(self::check_url()).'">Verificar atualizações</a>';
        }
        return $links;
    }

    /** Cache only public release metadata; never send site, visitor or gateway data. */
    private static function manifest(bool $force = false): array|\WP_Error {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached['data'] ?? new \WP_Error('visitas_updates_unavailable', 'Não foi possível consultar as versões no GitHub. Tente novamente.');
        }
        $response = wp_safe_remote_get(self::BASE.'update.json', [
            'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 65536,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'Visitas/'.VISITAS_VERSION,
        ]);
        $data = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200
            ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $valid = is_array($data) && ($data['slug'] ?? '') === 'visitas';
        foreach (['version', 'requires', 'requires_php', 'tested'] as $field) {
            $valid = $valid && isset($data[$field]) && is_string($data[$field])
                && preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data[$field]);
        }
        $valid = $valid && ($data['download_url'] ?? '') === self::BASE.'visitas-'.$data['version'].'.zip'
            && is_string($data['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $data['sha256']);
        if (!$valid) {
            set_site_transient(self::CACHE, ['checked_at' => time()], 5 * MINUTE_IN_SECONDS);
            return new \WP_Error('visitas_updates_unavailable', 'Não foi possível consultar uma versão válida no GitHub. Tente novamente.');
        }
        $data['sections'] = array_map('wp_kses_post', array_intersect_key(
            is_array($data['sections'] ?? null) ? array_filter($data['sections'], 'is_string') : [],
            array_flip(['description', 'installation', 'changelog'])
        ));
        set_site_transient(self::CACHE, ['checked_at' => time(), 'data' => $data], 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private static function payload(array $data): array {
        return [
            'id' => self::REPOSITORY, 'slug' => 'visitas', 'plugin' => plugin_basename(VISITAS_FILE),
            'version' => $data['version'], 'new_version' => $data['version'],
            'url' => self::REPOSITORY, 'package' => $data['download_url'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
            'tested' => $data['tested'], 'visitas_sha256' => $data['sha256'],
        ];
    }

    public static function update($update, array $plugin_data, string $plugin_file, array $locales = []) {
        if ($plugin_file !== plugin_basename(VISITAS_FILE) || ($plugin_data['UpdateURI'] ?? '') !== self::REPOSITORY) {
            return $update;
        }
        $data = self::manifest();
        return is_wp_error($data) ? $update : self::payload($data);
    }

    public static function details($result, string $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'visitas') { return $result; }
        $data = self::manifest();
        if (is_wp_error($data)) { return $data; }
        return (object) [
            'name' => 'Visitas — Acolhimento e acompanhamento', 'slug' => 'visitas',
            'version' => $data['version'], 'author' => 'Paulo Maeda',
            'homepage' => self::REPOSITORY, 'external' => true,
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'], 'tested' => $data['tested'],
            'download_link' => $data['download_url'], 'sections' => $data['sections'],
        ];
    }

    /** Refresh only this plugin's entry; leave other plugins and their update checks intact. */
    public static function check_now(): array|\WP_Error {
        if (!self::allowed()) { return new \WP_Error('visitas_update_forbidden', 'Apenas administradores autorizados podem verificar atualizações.'); }
        $data = self::manifest(true);
        if (is_wp_error($data)) { return $data; }
        $updates = get_site_transient('update_plugins');
        if (!is_object($updates)) { $updates = new \stdClass(); }
        foreach (['response', 'no_update', 'checked'] as $key) {
            if (!isset($updates->$key) || !is_array($updates->$key)) { $updates->$key = []; }
        }
        $file = plugin_basename(VISITAS_FILE);
        unset($updates->response[$file], $updates->no_update[$file]);
        $bucket = version_compare($data['version'], VISITAS_VERSION, '>') ? 'response' : 'no_update';
        $updates->{$bucket}[$file] = (object) self::payload($data);
        $updates->checked[$file] = VISITAS_VERSION;
        set_site_transient('update_plugins', $updates);
        return $data;
    }

    public static function manual_check(): void {
        if (!self::allowed()) { wp_die('Você não tem permissão para verificar atualizações.', '', ['response' => 403]); }
        check_admin_referer('visitas_check_updates');
        $result = self::check_now();
        $status = is_wp_error($result) ? 'error' : (version_compare($result['version'], VISITAS_VERSION, '>') ? 'available' : 'current');
        wp_safe_redirect(add_query_arg('visitas_update_check', $status, self_admin_url('plugins.php')));
        exit;
    }

    public static function notice(): void {
        global $pagenow;
        if ($pagenow !== 'plugins.php' || !self::allowed()) { return; }
        $status = sanitize_key($_GET['visitas_update_check'] ?? '');
        $messages = [
            'current' => 'Consulta concluída: o Visitas está na versão mais recente disponível no GitHub.',
            'available' => 'Há uma nova versão do Visitas. Use “Atualizar agora” na lista de plugins.',
            'error' => 'Não foi possível consultar as atualizações do Visitas no GitHub. Confira a conexão da hospedagem e tente novamente. A versão instalada foi preservada.',
        ];
        if (isset($messages[$status])) {
            echo '<div class="notice notice-'.($status === 'error' ? 'warning' : 'success').' is-dismissible"><p>'.esc_html($messages[$status]).'</p></div>';
        }
    }

    public static function panel(): void {
        if (!self::allowed()) { return; }
        $cached = get_site_transient(self::CACHE);
        echo '<h2>Atualizações</h2><p>Versão instalada: <strong>'.esc_html(VISITAS_VERSION).'</strong>. As novas versões são consultadas no GitHub e instaladas pela tela de plugins do WordPress.</p>';
        if (isset($cached['data']['version'])) {
            echo '<p>Última versão consultada: <strong>'.esc_html($cached['data']['version']).'</strong>.</p>';
        }
        echo '<p><a class="button" href="'.esc_url(self::check_url()).'">Verificar atualizações</a> <a class="button" href="'.esc_url(self_admin_url('plugins.php')).'">Abrir plugins</a></p>';
    }

    /** Verify the downloaded archive before WordPress changes installed files. */
    public static function download($reply, string $package, $upgrader, array $extra = []) {
        if (($extra['plugin'] ?? '') !== plugin_basename(VISITAS_FILE)) { return $reply; }
        $updates = get_site_transient('update_plugins');
        $update = $updates->response[plugin_basename(VISITAS_FILE)] ?? null;
        // Manual ZIP installs retain WordPress's standard upload flow.
        if (!$update || $package !== ($update->package ?? '')) { return $reply; }
        if (is_wp_error($reply)) { return $reply; }
        $version = $update->new_version ?? '';
        $hash = $update->visitas_sha256 ?? '';
        if (!is_string($version) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version)
            || $package !== self::BASE.'visitas-'.$version.'.zip'
            || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            return new \WP_Error('visitas_update_invalid', 'Os dados desta atualização são inválidos. Clique em Verificar atualizações e tente novamente.');
        }
        $file = $reply !== false ? $reply : download_url($package, 60);
        if (is_wp_error($file)) { return $file; }
        $actual = is_string($file) && is_readable($file) ? hash_file('sha256', $file) : false;
        if (!is_string($actual) || !hash_equals($hash, $actual)) {
            if (is_string($file) && $reply === false) { wp_delete_file($file); }
            return new \WP_Error('visitas_update_checksum', 'O pacote de atualização está incompleto ou foi alterado. A instalação foi interrompida antes de substituir o plugin. Verifique as atualizações novamente.');
        }
        return $file;
    }
}

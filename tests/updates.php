<?php
/** Run in a disposable COPY (never a symlink to the repository). All external services are mocked. */
if (getenv('VISITAS_TESTING') !== '1' || !getenv('VISITAS_WP_ROOT') || !getenv('VISITAS_TEST_PACKAGE')) {
    fwrite(STDERR, "Set VISITAS_TESTING=1, VISITAS_WP_ROOT and VISITAS_TEST_PACKAGE (a newer test ZIP).\n"); exit(1);
}
require rtrim(getenv('VISITAS_WP_ROOT'), '/').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
use Shalom\Visitas\{Updater, Settings};
if (is_link(WP_PLUGIN_DIR.'/visitas') || realpath(WP_PLUGIN_DIR.'/visitas') === realpath(dirname(__DIR__))) {
    throw new RuntimeException('Refusing to replace a linked checkout. Use a disposable copy of WordPress and the plugin.');
}
$passed = 0;
function verify($condition, string $label): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
    $passed++; echo 'PASS '.$label.PHP_EOL;
}
$base = 'https://raw.githubusercontent.com/paulommaeda/visitas/main/dist/';
$file = plugin_basename(VISITAS_FILE);
$original_cache = get_site_transient('visitas_update_manifest');
$original_updates = get_site_transient('update_plugins');
$original_settings = get_option('visitas_settings');
$original_request = $_REQUEST;
$requests = []; $mode = 'ok'; $bad_zip = false; $user_id = 0;
$package = getenv('VISITAS_TEST_PACKAGE');
$release = json_decode(file_get_contents(dirname(__DIR__).'/dist/update.json'), true);
$parts = explode('.', VISITAS_VERSION); $parts[count($parts)-1]++;
$future = implode('.', $parts);
$release['version'] = $future;
$release['download_url'] = $base.'visitas-'.$future.'.zip';
$release['sha256'] = hash_file('sha256', $package);
$release['sections']['description'] = '<p>Descrição de teste.</p><script>alert(1)</script>';
$http = static function($result, $args, $url) use (&$requests, &$mode, &$release, &$bad_zip, $base, $package) {
    $requests[] = ['url' => $url, 'args' => $args];
    if ($url === $base.'update.json') {
        if ($mode === 'offline') { return new WP_Error('test_offline', 'Offline'); }
        return ['headers' => [], 'response' => ['code' => 200], 'body' => $mode === 'json' ? 'invalid {' : wp_json_encode($release), 'cookies' => []];
    }
    if ($url === $release['download_url'] && !empty($args['stream'])) {
        file_put_contents($args['filename'], $bad_zip ? 'corrupted archive' : file_get_contents($package));
        return ['headers' => [], 'response' => ['code' => 200], 'body' => '', 'cookies' => []];
    }
    if (str_contains($url, 'api.wordpress.org/plugins/update-check/')) {
        return ['headers' => [], 'response' => ['code' => 200], 'body' => wp_json_encode(['plugins' => [], 'no_update' => [], 'translations' => []]), 'cookies' => []];
    }
    return new WP_Error('test_http_blocked', 'External HTTP is blocked.');
};
add_filter('pre_http_request', $http, 2000, 3);
add_filter('pre_wp_mail', '__return_true', 2000);
$die_filter = static fn() => static function($message, $title = '', $args = []) { throw new RuntimeException('blocked_wp_die'); };
try {
    $admin = get_user_by('login', getenv('TEST_ADMIN_USER') ?: 'admin');
    wp_set_current_user($admin->ID);
    delete_site_transient('visitas_update_manifest');
    $headers = get_plugin_data(VISITAS_FILE, false, false);
    $other = ['version' => '9.9.9'];
    verify(Updater::update($other, ['UpdateURI' => $headers['UpdateURI']], 'other/other.php') === $other, 'Other GitHub plugins are untouched');
    verify(Updater::update($other, ['UpdateURI' => 'https://example.test'], $file) === $other, 'Unrelated Update URI is untouched');
    $updates = (object) ['response' => ['other/other.php' => (object) $other], 'no_update' => [], 'checked' => [], 'last_checked' => 123];
    set_site_transient('update_plugins', $updates);
    $result = Updater::check_now();
    verify(!is_wp_error($result) && $result['version'] === $future, 'Manual check fetches latest release');
    $updates = get_site_transient('update_plugins');
    verify($updates->response[$file]->new_version === $future && $updates->response[$file]->package === $release['download_url'], 'New release becomes a native update offer');
    verify($updates->response[$file]->visitas_sha256 === $release['sha256'], 'Offer keeps package checksum');
    verify($updates->last_checked === 123 && $updates->response['other/other.php']->version === '9.9.9', 'Manual check preserves other plugins and their schedule');
    $count = count($requests);
    apply_filters('update_plugins_github.com', false, $headers, $file, []);
    $details = apply_filters('plugins_api', false, 'plugin_information', (object) ['slug' => 'visitas']);
    verify(count($requests) === $count, 'Checks and details reuse metadata cache');
    verify($details->version === $future && $details->download_link === $release['download_url'], 'Plugin details expose version and installable package');
    verify(!str_contains($details->sections['description'], '<script>'), 'Details sanitize release HTML');
    verify(Updater::details($other, 'plugin_information', (object) ['slug' => 'another']) === $other, 'Other plugin details are untouched');
    verify($requests[0]['args']['user-agent'] === 'Visitas/'.VISITAS_VERSION && empty($requests[0]['args']['body']), 'GitHub check sends no site, visitor or credential payload');

    $release['version'] = VISITAS_VERSION; $release['download_url'] = $base.'visitas-'.VISITAS_VERSION.'.zip';
    Updater::check_now(); $updates = get_site_transient('update_plugins');
    verify(!isset($updates->response[$file]) && isset($updates->no_update[$file]), 'Current version has no update banner');
    $release['version'] = $future; $release['download_url'] = $base.'visitas-'.$future.'.zip';
    Updater::check_now();
    $mode = 'offline';
    verify(is_wp_error(Updater::check_now()), 'Offline check reports a clear error');
    verify(isset(get_site_transient('update_plugins')->response[$file]), 'Failed manual check keeps the previous offer');
    $count = count($requests); Updater::update(false, $headers, $file);
    verify(count($requests) === $count, 'Failures have a short cache to avoid repeated requests');
    $mode = 'json'; verify(is_wp_error(Updater::check_now()), 'Malformed JSON is rejected');
    $mode = 'ok';
    $release['download_url'] = 'https://example.test/untrusted.zip';
    verify(is_wp_error(Updater::check_now()), 'Packages outside the repository are rejected');
    $release['download_url'] = $base.'visitas-'.$future.'.zip';
    $hash = $release['sha256']; $release['sha256'] = 'bad';
    verify(is_wp_error(Updater::check_now()), 'Invalid checksums are rejected');
    $release['sha256'] = $hash;

    add_role('qa_updater_nonadmin', 'Updater test', ['read' => true, 'update_plugins' => true]);
    $user_id = wp_insert_user(['user_login' => 'qa_updater_'.wp_generate_password(8, false), 'user_pass' => wp_generate_password(30), 'role' => 'qa_updater_nonadmin']);
    wp_set_current_user($user_id); $count = count($requests);
    verify(is_wp_error(Updater::check_now()) && count($requests) === $count, 'Nonadministrators cannot check even with update_plugins capability');
    verify(Updater::links([]) === [], 'Nonadministrators receive no update action');
    add_filter('wp_die_handler', $die_filter);
    try { Updater::manual_check(); throw new RuntimeException('Missing authorization guard'); }
    catch (RuntimeException $e) { verify($e->getMessage() === 'blocked_wp_die', 'Manual action rejects a nonadministrator'); }
    wp_set_current_user($admin->ID);
    $_REQUEST['_wpnonce'] = 'invalid';
    try { Updater::manual_check(); throw new RuntimeException('Missing nonce guard'); }
    catch (RuntimeException $e) { verify($e->getMessage() === 'blocked_wp_die', 'Manual action rejects an invalid nonce'); }
    remove_filter('wp_die_handler', $die_filter);
    verify(str_contains(Updater::links([])['visitas_updates'], '_wpnonce='), 'Administrator action has a nonce');

    delete_site_transient('visitas_update_manifest');
    set_site_transient('update_plugins', (object) ['last_checked' => 0]);
    wp_clean_plugins_cache(false); wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    verify(($updates->response[$file]->new_version ?? '') === $future, 'Native WordPress scheduled check discovers the release');
    verify($updates->response[$file]->requires === '6.5' && $updates->response[$file]->requires_php === '8.1', 'WordPress receives compatibility requirements');
    $count = count($requests); ob_start(); Updater::panel(); $panel = ob_get_clean();
    verify(str_contains($panel, 'Verificar atualizações') && count($requests) === $count, 'Admin panel renders cached status without an HTTP request');
    verify(Updater::download(false, 'https://example.test/other.zip', null, ['plugin' => 'other/other.php']) === false, 'Other plugin downloads are untouched');

    $bad_zip = true;
    $rejected = Updater::download(false, $release['download_url'], null, ['plugin' => $file]);
    verify(is_wp_error($rejected) && $rejected->get_error_code() === 'visitas_update_checksum', 'Corrupted archive is stopped before installation');
    $bad_zip = false;
    $downloaded = Updater::download(false, $release['download_url'], null, ['plugin' => $file]);
    verify(is_string($downloaded) && hash_file('sha256', $downloaded) === $hash, 'Valid package passes SHA-256 verification');
    wp_delete_file($downloaded);

    global $wpdb;
    $cards_before = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}visitas_cards ORDER BY id", ARRAY_A);
    $boards_before = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}visitas_boards ORDER BY id", ARRAY_A);
    $settings_before = Settings::get();
    $active_before = get_option('active_plugins');
    if (!defined('FS_METHOD')) { define('FS_METHOD', 'direct'); }
    // Same bulk_upgrade([$plugin]) flow used by the Plugins screen's AJAX update.
    $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
    ob_start(); $installed = $upgrader->bulk_upgrade([$file]); ob_end_clean();
    verify(is_array($installed[$file] ?? null), 'Native Plugin_Upgrader installs the verified ZIP');
    $new_headers = get_plugin_data(WP_PLUGIN_DIR.'/'.$file, false, false);
    verify($new_headers['Version'] === $future, 'Update replaces the plugin in the correct folder');
    verify(!is_dir(WP_PLUGIN_DIR.'/visitas/visitas'), 'Update does not create a nested plugin directory');
    verify(get_option('active_plugins') === $active_before, 'Plugin activation is preserved');
    verify(Settings::get() === $settings_before, 'Configuration is preserved');
    verify($wpdb->get_results("SELECT * FROM {$wpdb->prefix}visitas_cards ORDER BY id", ARRAY_A) === $cards_before, 'Visitor cards are preserved');
    verify($wpdb->get_results("SELECT * FROM {$wpdb->prefix}visitas_boards ORDER BY id", ARRAY_A) === $boards_before, 'Boards are preserved');
    echo "\n{$passed} updater assertions passed. No real external services were used.\n";
} finally {
    remove_filter('wp_die_handler', $die_filter);
    remove_filter('pre_http_request', $http, 2000);
    wp_set_current_user($admin->ID ?? 0);
    if ($user_id && !is_wp_error($user_id)) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($user_id); }
    remove_role('qa_updater_nonadmin');
    $_REQUEST = $original_request;
    update_option('visitas_settings', $original_settings);
    if ($original_cache === false) { delete_site_transient('visitas_update_manifest'); } else { set_site_transient('visitas_update_manifest', $original_cache, HOUR_IN_SECONDS); }
    if ($original_updates === false) { delete_site_transient('update_plugins'); } else { set_site_transient('update_plugins', $original_updates); }
}

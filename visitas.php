<?php
/**
 * Plugin Name: Visitas — Acolhimento e acompanhamento
 * Plugin URI: https://github.com/paulommaeda/visitas
 * Description: Portal independente de visitantes com quadros Kanban, modelos, permissões e notificações por e-mail e SMS Gateway.
 * Version: 1.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Paulo Maeda
 * License: GPL-2.0-or-later
 * Text Domain: visitas
 * Update URI: https://github.com/paulommaeda/visitas
 */

defined('ABSPATH') || exit;
define('VISITAS_VERSION', '1.0.2');
define('VISITAS_FILE', __FILE__);
define('VISITAS_DIR', plugin_dir_path(__FILE__));
define('VISITAS_URL', plugin_dir_url(__FILE__));

foreach (['settings', 'access', 'installer', 'store', 'notifications', 'api', 'portal', 'updater'] as $visitas_class) {
    require_once VISITAS_DIR . 'includes/class-' . $visitas_class . '.php';
}
register_activation_hook(__FILE__, ['Shalom\\Visitas\\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['Shalom\\Visitas\\Installer', 'deactivate']);
add_action('plugins_loaded', static function () {
    if (get_option('visitas_version') !== VISITAS_VERSION) {
        Shalom\Visitas\Installer::activate();
    }
    Shalom\Visitas\Access::boot();
    Shalom\Visitas\Notifications::boot();
    Shalom\Visitas\Portal::boot();
    Shalom\Visitas\Updater::boot();
    add_action('rest_api_init', ['Shalom\\Visitas\\Api', 'register']);
});

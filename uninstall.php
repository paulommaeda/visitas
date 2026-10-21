<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
delete_site_transient('visitas_update_manifest');
if (!defined('VISITAS_DELETE_DATA') || VISITAS_DELETE_DATA !== true) { return; }
global $wpdb;
wp_clear_scheduled_hook('visitas_queue'); wp_clear_scheduled_hook('visitas_due');
foreach (['notifications','activity','task_assignees','members','cards','lists','boards'] as $table) { $wpdb->query('DROP TABLE IF EXISTS `'.esc_sql($wpdb->prefix.'visitas_'.$table).'`'); }
foreach (wp_roles()->roles as $key=>$role) {
    if (str_starts_with($key,'visitas_')) { remove_role($key); }
    elseif ($key==='administrator') { $r=get_role($key); foreach ($role['capabilities'] as $cap=>$grant) { if (str_starts_with($cap,'visitas_')) { $r->remove_cap($cap); } } }
}
$id=(int)get_option('visitas_portal_page'); $post=$id?get_post($id):null;
if ($post && has_shortcode($post->post_content,'visitas_portal')) { wp_delete_post($id,true); }
foreach (['visitas_version','visitas_settings','visitas_portal_page'] as $option) { delete_option($option); }
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('visitas_phone','visitas_sms_optin','visitas_email_optout')");

<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Installer {
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'visitas_';
        $queries = [
            "CREATE TABLE {$p}boards (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                description text NOT NULL,
                color varchar(7) NOT NULL DEFAULT '#001BE1',
                visibility varchar(20) NOT NULL DEFAULT 'team',
                initial_list_id bigint(20) unsigned NOT NULL DEFAULT 0,
                schema_json longtext NOT NULL,
                templates_json longtext NOT NULL,
                workflow_json longtext DEFAULT NULL,
                created_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id)
            ) $cc;",
            "CREATE TABLE {$p}lists (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                board_id bigint(20) unsigned NOT NULL,
                name varchar(190) NOT NULL,
                color varchar(7) NOT NULL DEFAULT '#001BE1',
                position int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY board_position (board_id,position)
            ) $cc;",
            "CREATE TABLE {$p}cards (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                board_id bigint(20) unsigned NOT NULL,
                list_id bigint(20) unsigned NOT NULL,
                title varchar(190) NOT NULL,
                email varchar(190) NOT NULL DEFAULT '',
                phone varchar(32) NOT NULL DEFAULT '',
                birthday date DEFAULT NULL,
                visit_date date DEFAULT NULL,
                start_at datetime DEFAULT NULL,
                due_at datetime DEFAULT NULL,
                step_due_at datetime DEFAULT NULL,
                workflow_json longtext DEFAULT NULL,
                completed tinyint(1) NOT NULL DEFAULT 0,
                archived tinyint(1) NOT NULL DEFAULT 0,
                assigned_to bigint(20) unsigned NOT NULL DEFAULT 0,
                description longtext NOT NULL,
                source varchar(190) NOT NULL DEFAULT '',
                address text NOT NULL,
                consent_email tinyint(1) NOT NULL DEFAULT 0,
                consent_sms tinyint(1) NOT NULL DEFAULT 0,
                labels_json longtext NOT NULL,
                attributes_json longtext NOT NULL,
                checklists_json longtext NOT NULL,
                position int(11) NOT NULL DEFAULT 0,
                version int(11) NOT NULL DEFAULT 1,
                created_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY board_list (board_id,list_id),
                KEY assigned_to (assigned_to),
                KEY due_reminder (archived,completed,due_at),
                KEY dashboard_visit (archived,visit_date,board_id),
                KEY step_reminder (archived,completed,step_due_at)
            ) $cc;",
            "CREATE TABLE {$p}members (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                board_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                role varchar(20) NOT NULL DEFAULT 'editor',
                PRIMARY KEY  (id),
                UNIQUE KEY board_user (board_id,user_id)
            ) $cc;",
            "CREATE TABLE {$p}activity (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                card_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                kind varchar(30) NOT NULL,
                content text NOT NULL,
                occurred_on date DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY card_activity (card_id,id),
                KEY followup_date (kind,occurred_on,card_id)
            ) $cc;",
            "CREATE TABLE {$p}notifications (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                card_id bigint(20) unsigned NOT NULL DEFAULT 0,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event_key varchar(190) NOT NULL,
                channel varchar(20) NOT NULL,
                recipient varchar(190) NOT NULL,
                subject varchar(255) NOT NULL,
                body text NOT NULL,
                state varchar(30) NOT NULL DEFAULT 'queued',
                attempts int(11) NOT NULL DEFAULT 0,
                provider_id varchar(190) NOT NULL DEFAULT '',
                detail text NOT NULL,
                read_at datetime DEFAULT NULL,
                next_attempt datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_channel_recipient (event_key,channel,recipient),
                KEY queue_state (state,next_attempt),
                KEY user_read (user_id,read_at)
            ) $cc;",
        ];
        foreach ($queries as $sql) { dbDelta($sql); }
        Access::install();
        if (!get_option('visitas_settings')) { add_option('visitas_settings', Settings::defaults(), '', false); }
        $page_id = (int) get_option('visitas_portal_page');
        if (!$page_id || !get_post($page_id)) {
            $id = wp_insert_post(['post_title'=>'Acolher','post_name'=>'acolher','post_content'=>'[visitas_portal]','post_status'=>'publish','post_type'=>'page'], true);
            if (!is_wp_error($id)) { update_option('visitas_portal_page', $id); }
        }
        if (!$wpdb->get_var("SELECT id FROM {$p}boards LIMIT 1")) {
            Store::create_board(['name'=>'Acolhimento de visitantes', 'description'=>'Do primeiro encontro aos próximos passos. Um cuidado de cada vez.', 'seed'=>true]);
        }
        update_option('visitas_version', VISITAS_VERSION);
        Notifications::schedule();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('visitas_queue');
        wp_clear_scheduled_hook('visitas_due');
    }
}

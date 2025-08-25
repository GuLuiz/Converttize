<?php

    class YT_Player_Analytics {
        public function __construct() {
            add_action('wp_ajax_yt_save_view_data', [$this, 'save_view_data']);
            add_action('wp_ajax_nopriv_yt_save_view_data', [$this, 'save_view_data']);
        }

        public function save_view_data() {
            global $wpdb;

            $video_id = sanitize_text_field($_POST['video_id']);
            $watched_time = floatval($_POST['watched_time']);
            $exit_time = floatval($_POST['exit_time']);

            $table = $wpdb->prefix . 'yt_view_logs';

            $wpdb->insert($table, [
                'video_id' => $video_id,
                'watched_time' => $watched_time,
                'exit_time' => $exit_time,
                'created_at' => current_time('mysql')
            ]);

            wp_send_json_success();
        }

        public static function create_table() {
            global $wpdb;
            $table = $wpdb->prefix . 'yt_view_logs';
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                video_id VARCHAR(255),
                watched_time FLOAT,
                exit_time FLOAT,
                created_at DATETIME
            ) $charset;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
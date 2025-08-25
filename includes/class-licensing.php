<?php

    class YT_Player_Licensing {
        private $api_url = 'https://aqui-vai-entrar-minha-api.com/check-license';

        public function __construct() {
            register_activation_hook(__FILE__, [$this, 'validate_license']);
        }

        public function save_view_data() {
            global $wpdb;

            $video_id     = sanitize_text_field($_POST['video_id'] ?? '');
            $watched_time = floatval($_POST['watched_time'] ?? 0);
            $exit_time    = floatval($_POST['exit_time'] ?? 0);

            if (empty($video_id)) {
                wp_send_json_error(['message' => 'Missing video ID']);
            }

            $ip         = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $session_id = md5($video_id . $ip . $user_agent);

            $table = $wpdb->prefix . 'yt_view_logs';

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE session_id = %s",
                $session_id
            ));

            if ($exists) {
                wp_send_json_success(['message' => 'Already logged']);
            }

            $wpdb->insert($table, [
                'video_id'     => $video_id,
                'watched_time' => $watched_time,
                'exit_time'    => $exit_time,
                'session_id'   => $session_id,
                'ip_address'   => $ip,
                'user_agent'   => $user_agent,
                'created_at'   => current_time('mysql')
            ]);

            wp_send_json_success(['message' => 'View logged']);
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
                session_id VARCHAR(64),
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME,
                UNIQUE KEY session_id (session_id)
            ) $charset;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }

        public function validate_license() {
            $domain = $_SERVER['HTTP_HOST'];
            $license_key = get_option('yt_plugin_license_key');

            $response = wp_remote_post($this->api_url, [
                'body' => [
                    'license_key' => $license_key,
                    'domain' => $domain,
                ]
            ]);

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!$data || !$data['valid']) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die('Licença inválida. Plugin desativado.');
            }
        }
    }
<?php

    class YT_License_Manager {
        private $api_url;
        private $plugin_version;
        private $security_secret = 'CONV_SEC_2024_XYZ789'; // Este deve ser o MESMO do validate.php
        private $cache_duration = 60; // Duração do cache em segundos (60 segundos = 1 minuto)
        private $config;

        // NOVO: Adicionado para ter acesso à instância principal do plugin
        private $main_plugin_instance; 

        // MODIFICADO: Construtor agora aceita a instância principal do plugin
        // (A classe Converttize PRECISA passar $this para este construtor)
        public function __construct($main_plugin_instance = null) { // Adicionado $main_plugin_instance com default null para compatibilidade
            $this->main_plugin_instance = $main_plugin_instance; // Armazena a instância principal

            // Note: Make sure config.php path is correct or directly define security_secret here
            // If config.php is not applicable to WP plugin, ensure security_secret is hardcoded here.
            $this->config = include plugin_dir_path(__FILE__) . '../config.php'; 
            // If the security_secret is only in the backend's config.php, you MUST hardcode it here
            // $this->security_secret = 'CONV_SEC_2024_XYZ789'; 

            $this->api_url = $this->is_local_environment()
                ? $this->config['license']['api_url_local']
                : $this->config['license']['api_url_production'];
                
            $this->plugin_version = defined('YTP_VERSION') ? YTP_VERSION : '1.0.0';
            
            add_action('admin_menu', [$this, 'add_license_menu']);
            
            if (!wp_next_scheduled('converttize_check_license')) {
                wp_schedule_event(time(), 'hourly', 'converttize_check_license');
            }
            add_action('converttize_check_license', [$this, 'background_license_check']);
            
            add_action('wp_ajax_converttize_validate_license', [$this, 'ajax_validate_license']);
            add_action('wp_ajax_converttize_test_key', [$this, 'ajax_test_key']);
            add_action('wp_ajax_converttize_clear_cache_admin', [$this, 'ajax_clear_cache']);
        }
        
        private function is_local_environment() {
            $host = $_SERVER['HTTP_HOST'];
            $local_hosts = $this->config['environment']['local_hosts'];

            return in_array($host, $local_hosts);
        }

        
        public function get_license_status() {
            return $this->get_license_status_local();
        }
        
        private function get_license_status_local() {
            $license_key = get_option('converttize_license_key');
            
            if (!empty($license_key)) {
                if (!$this->validate_license_key_format($license_key)) {
                    $this->log_security_event('invalid_license_format', $license_key);
                    delete_option('converttize_license_key');
                    $this->clear_all_cache();
                    return 'inactive';
                }
                
                $cached_status = $this->get_cached_license_status();
                if ($cached_status !== null) {
                    return $cached_status;
                }
                
                return $this->validate_license_online($license_key);
            }
            
            /*
            // === Lógica para Trial se não houver license_key (COMENTADA) ===
            $cached_trial = $this->get_cached_license_status();
            if ($cached_trial !== null && in_array($cached_trial, ['trial', 'trial_expired'])) {
                return $cached_trial;
            }
            
            $trial_status = $this->check_trial_status();
            $this->set_cached_license_status($trial_status); // Cache do status do trial
            
            return $trial_status;
            */
            return 'inactive'; // Default se não há licença paga e trial está desativado
        }
        
        public function force_trial_check() {
            error_log('🎯 CONVERTTIZE: Forçando verificação de trial...');
            
            $this->clear_all_cache();
            
            $license_key = get_option('converttize_license_key');
            if (!empty($license_key)) {
                error_log('🔑 CONVERTTIZE: Licença paga encontrada, validando...');
                return $this->validate_license_online($license_key);
            }
            
            /*
            // === Código de verificação de trial (COMENTADO) ===
            error_log('🎯 CONVERTTIZE: Sem licença, verificando trial...');
            $trial_status = $this->check_trial_status();
            
            error_log('🎯 CONVERTTIZE: Status do trial: ' . $trial_status);
            
            return $trial_status;
            */
            return 'inactive'; // Se não há licença paga e trial está desativado.
        }
        
        private function check_trial_status() {
            /*
            // === Função check_trial_status (COMENTADA) ===
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            error_log('🎯 CONVERTTIZE: Verificando trial para domínio: ' . $domain);
            
            $ip_partial = substr($ip, 0, strrpos($ip, '.'));
            $fingerprint_data = $domain . '|' . $user_agent . '|' . $ip_partial;
            $hardware_fingerprint = hash('sha256', $fingerprint_data);
            
            error_log('🎯 CONVERTTIZE: Hardware fingerprint: ' . substr($hardware_fingerprint, 0, 16) . '...');
            
            $timestamp = time();
            // AQUI USARÍAMOS O $this->security_secret se o trial fizesse um hash de segurança.
            // Para o trial original, ele apenas enviava alguns dados para o validate.php
            $request_hash = hash('sha256', $domain . $timestamp . $this->security_secret); 
            $post_data = [
                'domain' => $domain,
                'plugin_version' => $this->plugin_version,
                'timestamp' => $timestamp,
                'request_hash' => $request_hash // Este hash é para a requisição, não a validação do trial em si
            ];
            
            error_log('🎯 CONVERTTIZE: Enviando dados para API: ' . json_encode($post_data));
            error_log('🎯 CONVERTTIZE: URL da API: ' . $this->api_url . 'validate.php');
            
            $response = wp_remote_post($this->api_url . 'validate.php', [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Converttize-Plugin/' . $this->plugin_version,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => $post_data
            ]);
            
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                error_log('❌ CONVERTTIZE: Erro na verificação de trial: ' . $error_msg);
                return 'inactive';
            }
            
            $body = wp_remote_retrieve_body($response);
            $http_code = wp_remote_retrieve_response_code($response);
            
            error_log('🎯 CONVERTTIZE: HTTP Code: ' . $http_code);
            error_log('🎯 CONVERTTIZE: Resposta completa: ' . $body);
            
            if ($http_code !== 200) {
                error_log('❌ CONVERTTIZE: HTTP Code inválido: ' . $http_code);
                return 'inactive';
            }
            
            $result = json_decode($body, true);
            
            if (!$result) {
                error_log('❌ CONVERTTIZE: JSON inválido: ' . $body);
                return 'inactive';
            }
            
            if (!$result['success']) {
                error_log('❌ CONVERTTIZE: API retornou erro: ' . ($result['message'] ?? 'sem mensagem'));
                return 'inactive';
            }
            
            $status = $result['data']['status'] ?? 'inactive';
            
            error_log('✅ CONVERTTIZE: Status recebido: ' . $status);
            
            if ($status === 'trial') {
                update_option('converttize_trial_expires_at', $result['data']['trial_expires_at'] ?? '');
                update_option('converttize_trial_days_remaining', $result['data']['trial_days_remaining'] ?? 0);
                
                error_log('✅ CONVERTTIZE: Trial ativo salvo - Expira: ' . ($result['data']['trial_expires_at'] ?? 'N/A'));
            }
            
            return $status;
            */
            return 'inactive'; // Retorno padrão se a função de trial está comentada
        }
        
        private function get_cached_license_status() {
            $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
            $cached = get_transient($cache_key);
            $cached_expires_at = get_transient('converttize_license_expires_at_cache'); // Cache da data de expiração
            
            if ($cached !== false) {
                $cache_time_key = $cache_key . '_time';
                $cache_time = get_transient($cache_time_key);
                
                if ($cache_time && (time() - $cache_time) < $this->cache_duration) {
                    // SE o cache ainda é válido E a licença é ativa, verificar também a expiração da licença localmente
                    if ($cached === 'active' && !empty($cached_expires_at) && time() > strtotime($cached_expires_at)) {
                        error_log('❌ CONVERTTIZE: Licença ativa no cache, mas expirada pela data. Forçando revalidação.');
                        $this->clear_all_cache(); // Força a revalidação online
                        return null; // Retorna nulo para indicar que o cache não é mais válido
                    }
                    return $cached;
                } else {
                    // Cache expirou, limpar tudo
                    delete_transient($cache_key);
                    delete_transient($cache_time_key);
                    delete_transient('converttize_license_expires_at_cache'); // Limpar cache da data de expiração
                }
            }
            
            return null;
        }
        
        private function set_cached_license_status($status, $expires_at = null) { // Adicionado $expires_at
            $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
            $cache_time_key = $cache_key . '_time';
            
            set_transient($cache_key, $status, $this->cache_duration);
            set_transient($cache_time_key, time(), $this->cache_duration);
            
            if ($expires_at) { // Armazenar a data de expiração no cache
                set_transient('converttize_license_expires_at_cache', $expires_at, $this->cache_duration);
            } else {
                delete_transient('converttize_license_expires_at_cache');
            }
        }
        
        private function clear_all_cache() {
            $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
            $cache_time_key = $cache_key . '_time';
            
            delete_transient($cache_key);
            delete_transient($cache_time_key);
            delete_option('converttize_license_cached_status');
            delete_option('converttize_license_last_check');
            delete_transient('converttize_last_api_call');
            
            /*
            // === Opções de Trial (COMENTADAS) ===
            delete_option('converttize_trial_expires_at');
            delete_option('converttize_trial_days_remaining');
            */

            // Limpar opções de expiração da licença paga
            delete_option('converttize_license_expires_at');
            delete_transient('converttize_license_expires_at_cache');
            
            // NOVO: Limpar a razão da licença também
            delete_option('converttize_license_reason');
        }
        
        private function validate_license_key_format($license_key) {
            return preg_match('/^[A-Z]+_[A-Z0-9_]{10,}$/', $license_key);
        }
        
        private function validate_license_online($license_key) {
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            
            error_log("=== CONVERTTIZE DEBUG ===");
            error_log("Validando chave: " . $license_key);
            error_log("Domínio: " . $domain);
            error_log("URL da API: " . $this->api_url . 'validate.php');
            
            $last_api_call = get_transient('converttize_last_api_call');
            if ($last_api_call && (time() - $last_api_call) < 30) {
                error_log("Rate limit ativo, usando cache");
                return get_option('converttize_license_cached_status', 'inactive');
            }
            set_transient('converttize_last_api_call', time(), 60);
            
            $timestamp = time();
            $request_hash = hash('sha256', $license_key . $domain . $timestamp . $this->security_secret);
            
            $post_data = [
                'license_key' => $license_key,
                'domain' => $domain,
                'plugin_version' => $this->plugin_version,
                'timestamp' => $timestamp,
                'request_hash' => $request_hash
            ];
            
            error_log("Dados enviados: " . json_encode($post_data));
            
            $response = wp_remote_post($this->api_url . 'validate.php', [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Converttize-Plugin/' . $this->plugin_version,
                    'X-Plugin-Version' => $this->plugin_version,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => $post_data
            ]);
            
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                error_log("❌ ERRO WP: " . $error_msg);
                $this->log_security_event('api_connection_error', $error_msg);
                return get_option('converttize_license_cached_status', 'inactive');
            }
            
            $body = wp_remote_retrieve_body($response);
            $http_code = wp_remote_retrieve_response_code($response);
            
            error_log("HTTP Code: " . $http_code);
            error_log("Resposta completa: " . $body);
            
            if ($http_code !== 200) {
                error_log("❌ HTTP Code inválido: " . $http_code);
                $this->log_security_event('api_http_error', $http_code);
                return get_option('converttize_license_cached_status', 'inactive');
            }
            
            $result = json_decode($body, true);
            
            if (!$result) {
                error_log("❌ JSON inválido: " . $body);
                $this->log_security_event('api_invalid_response', $body);
                return 'inactive';
            }
            
            error_log("JSON decodificado: " . json_encode($result));
            
            $status = 'inactive';
            $expires_at = null; 
            $reason = null; // NOVO: Variável para a razão da inatividade
            
            // O status agora é pego diretamente do nível raiz do JSON, se disponível.
            // Caso contrário, tenta de dentro de 'data'.
            $api_status_from_server = $result['status'] ?? ($result['data']['status'] ?? 'inactive');
            $status = $api_status_from_server; // Use o status principal da API
            $expires_at = $result['data']['expires_at'] ?? null;
            $reason = $result['data']['reason'] ?? null; // Capturar a razão
            
            error_log("✅ Status extraído: " . $status);
            if ($expires_at) {
                error_log("✅ Expira em: " . $expires_at);
            }
            if ($reason) { // Logar a razão
                error_log("✅ Razão: " . $reason);
            }
            
            if (isset($result['response_hash'])) {
                $response_timestamp = strtotime($result['timestamp']);
                $expected_hash = substr(hash('sha256', $status . $response_timestamp . $this->security_secret), 0, 16);
                
                error_log("Hash recebido: " . $result['response_hash']);
                error_log("Hash esperado: " . $expected_hash);
                
                if (!hash_equals($expected_hash, $result['response_hash'])) {
                    error_log("❌ Hash mismatch!");
                    $this->log_security_event('api_hash_mismatch', 'Response hash validation failed');
                    // Se o hash falhou, o status é inativo e a razão é de segurança.
                    $status = 'inactive'; 
                    $reason = 'security_hash_mismatch'; 
                } else {
                    error_log("✅ Hash validado com sucesso");
                }
            }
            
            // Passar expires_at para set_cached_license_status
            $this->set_cached_license_status($status, $expires_at);
            update_option('converttize_license_cached_status', $status);
            update_option('converttize_license_last_check', time());
            
            // Salvar a data de expiração como uma opção do WordPress
            if ($expires_at) {
                update_option('converttize_license_expires_at', $expires_at);
            } else {
                delete_option('converttize_license_expires_at');
            }

            // Salvar a razão da licença
            if ($reason) {
                update_option('converttize_license_reason', $reason);
            } else {
                delete_option('converttize_license_reason');
            }
            
            error_log("=== FIM DEBUG - Status final: " . $status . " ===");
            
            $this->log_security_event('license_validated', $status);
            
            return $status;
        }
        
        public function force_license_check() {
            // Este método será chamado para forçar uma verificação (e.g., pelo AJAX ou botão Verificar Agora).
            // Ele deve iniciar a validação online.
            $license_key = get_option('converttize_license_key');
            $this->clear_all_cache(); // Limpa o cache para forçar nova validação
            if (!empty($license_key)) {
                return $this->validate_license_online($license_key);
            }
            return 'inactive'; // Se não há licença configurada, assume-se inativo
        }
        
        public function ajax_test_key() {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'converttize_license_nonce')) {
                wp_send_json_error(['message' => 'Nonce inválido']);
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permissão negada']);
                return;
            }
            
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            if (empty($license_key)) {
                wp_send_json_error(['message' => 'Chave não fornecida']);
                return;
            }
            
            // Temporariamente salva a nova chave para testar
            $old_key = get_option('converttize_license_key');
            update_option('converttize_license_key', $license_key);
            $this->clear_all_cache(); // Limpa também a data de expiração antiga e a razão
            
            $status = $this->validate_license_online($license_key);
            $expires_at = get_option('converttize_license_expires_at'); // Captura a data de expiração recém-salva
            $reason = get_option('converttize_license_reason'); // Captura a razão
            
            // Restaura a chave original se a testada não for ativa, ou a mantém se for ativa
            if ($status === 'active') {
                // A chave testada é ativa, já está salva, não precisa restaurar
            } else {
                // Se a chave testada não for ativa, restaura a antiga ou a remove
                if ($old_key) {
                    update_option('converttize_license_key', $old_key);
                } else {
                    delete_option('converttize_license_key');
                }
                // Limpa também a data de expiração e a razão se a chave testada não for ativa
                delete_option('converttize_license_expires_at'); 
                delete_option('converttize_license_reason'); 
            }

            // CORREÇÃO: No JS, o retorno 'success' do AJAX é tratado.
            // Para 'ajax_test_key', queremos que 'success: true' no JSON de retorno signifique 'licença ativa'.
            // Qualquer outro status deve ser 'success: false' no JSON de retorno.
            if ($status === 'active') {
                wp_send_json_success([
                    'status' => $status, 
                    'message' => 'Chave ativa!',
                    'expires_at' => $expires_at ? date('d/m/Y', strtotime($expires_at)) : 'N/A',
                    'reason' => $reason
                ]);
            } else {
                wp_send_json_error([
                    'status' => $status, 
                    'message' => 'Chave inválida ou inativa', // Mensagem genérica para não-ativos
                    'reason' => $reason
                ]);
            }
        }
        
        public function ajax_clear_cache() {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'converttize_license_nonce')) {
                wp_send_json_error(['message' => 'Nonce inválido']);
                return;
            }
            
            $this->clear_all_cache(); // Esta função já foi atualizada para limpar expires_at e reason
            wp_send_json_success(['message' => 'Cache limpo']);
        }
        
        public function ajax_validate_license() {
            check_ajax_referer('converttize_license_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Unauthorized']);
                return;
            }
            
            $status = $this->force_license_check(); // Chama o force_license_check para revalidar online
            wp_send_json_success(['status' => $status]);
        }
        
        private function log_security_event($event, $data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $log_entry = [
                    'timestamp' => current_time('mysql'),
                    'event' => $event,
                    'data' => is_string($data) ? $data : json_encode($data),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ];
                error_log('CONVERTTIZE_SECURITY: ' . json_encode($log_entry));
            }
        }
        
        public function add_license_menu() {
        /*  add_options_page(
                'Converttize - Licença',
                'Converttize License',
                'manage_options',
                'converttize-license',
                [$this, 'license_page']
            ); */
        }
        
        public function license_page() {
            // Processa a verificação imediata (botão "Verificar Agora")
            if (isset($_POST['check_now']) && wp_verify_nonce($_POST['converttize_nonce'], 'converttize_license_action')) {
                $new_status = $this->force_license_check();
                echo '<div class="notice notice-info is-dismissible"><p>🔄 Status verificado: <strong>' . strtoupper(esc_html($new_status)) . '</strong></p></div>';
            }
            
            // Processa a ativação da licença (botão "Ativar Licença")
            if (isset($_POST['activate_license']) && wp_verify_nonce($_POST['converttize_nonce'], 'converttize_license_action')) {
                $license_key = sanitize_text_field($_POST['license_key']);
                $result = $this->activate_license($license_key); // Chama o método de ativação

                if ($result['success']) {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html($result['message']) . '</p></div>';
                } else {
                    // CORREÇÃO AQUI: Apenas exibe a mensagem retornada por activate_license,
                    // que já deve ser completa e conter a razão.
                    echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html($result['message']) . '</p></div>';
                }
            }
            
            // --- LÓGICA MODIFICADA PARA REMOVER LICENÇA ---
            if (isset($_POST['remove_license']) && wp_verify_nonce($_POST['converttize_nonce'], 'converttize_license_action')) {
                $license_key = get_option('converttize_license_key');
                $current_domain = parse_url(home_url(), PHP_URL_HOST); // Pega o domínio do site WordPress
                
                if (!empty($license_key) && !empty($current_domain)) {
                    // Prepara os dados para a chamada API ao servidor de licenças
                    $timestamp = time();
                    // O security_secret deve ser o MESMO valor definido em validate.php no servidor
                    $security_secret = $this->security_secret; // Acessa a propriedade privada da classe
                    // Calcula o request_hash exatamente como o servidor espera para desativação
                    $request_hash = hash('sha256', $license_key . $current_domain . $timestamp . $security_secret);
                    
                    $api_url = $this->api_url; // Acessa a propriedade privada da classe

                    // Faz a requisição POST para validate.php com a flag de desativação
                    $response = wp_remote_post($api_url . 'validate.php', [
                        'timeout' => 15, // Tempo limite da requisição
                        'headers' => [
                            'User-Agent' => 'Converttize-Plugin/' . $this->plugin_version,
                            'X-Plugin-Version' => $this->plugin_version,
                            'Content-Type' => 'application/x-www-form-urlencoded' // Formato dos dados
                        ],
                        'body' => [
                            'license_key' => $license_key,
                            'domain' => $current_domain,
                            'timestamp' => $timestamp,
                            'request_hash' => $request_hash,
                            'deactivate_domain' => true // Flag para indicar desativação
                        ]
                    ]);

                    if (is_wp_error($response)) {
                        $error_msg = $response->get_error_message();
                        echo '<div class="notice notice-error is-dismissible"><p>❌ Erro ao comunicar com o servidor de licenças para desativar: ' . esc_html($error_msg) . '</p></div>';
                        // IMPORTANTE: Mesmo que a chamada API falhe, remover a licença localmente para a experiência do usuário.
                        delete_option('converttize_license_key');
                        $this->clear_all_cache();
                    } else {
                        $body = wp_remote_retrieve_body($response);
                        $result = json_decode($body, true);

                        if ($result && $result['success']) {
                            echo '<div class="notice notice-success is-dismissible"><p>🗑️ Licença removida e slot de domínio liberado com sucesso.</p></div>';
                            delete_option('converttize_license_key'); // Remove chave local
                            $this->clear_all_cache(); // Limpa cache local
                        } else {
                            $message = $result['message'] ?? 'Erro desconhecido ao desativar domínio no servidor de licenças.';
                            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ ' . esc_html($message) . ' A licença foi removida localmente, mas pode não ter sido liberada no servidor.</p></div>';
                            // Ainda remove localmente, mesmo que o servidor tenha falhado, para a experiência do usuário
                            delete_option('converttize_license_key');
                            $this->clear_all_cache();
                        }
                    }
                } else {
                    echo '<div class="notice notice-warning is-dismissible"><p>Não há chave de licença ativa para remover ou domínio não detectado.</p></div>';
                    delete_option('converttize_license_key'); // Garante que a chave local seja removida
                    $this->clear_all_cache();
                }
            }
            // --- FIM DA LÓGICA MODIFICADA PARA REMOVER LICENÇA ---
            
            $current_license = get_option('converttize_license_key');
            $status = $this->get_license_status();
            $license_expires_at = get_option('converttize_license_expires_at'); 
            $license_reason = get_option('converttize_license_reason'); // Obter a razão da inatividade
            
            // Chama o render_admin_header aqui
            if ($this->main_plugin_instance && method_exists($this->main_plugin_instance, 'render_admin_header')) {
                $this->main_plugin_instance->render_admin_header('Gerenciamento de Licença');
            }
            ?>
            <div class="wrap">
                
                <div class="grid-2-cols-16">
                    <div class="card" style="max-width: 600px;">
                        <h2>Status da Licença</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Status Atual</th>
                                <td>
                                    <?php
                                    switch($status) {
                                        case 'active':
                                            echo '<span style="color: green; font-weight: bold;">✅ ATIVA</span><br>';
                                            if ($license_expires_at) { 
                                                echo '<small>Válida até: ' . date('d/m/Y', strtotime($license_expires_at)) . '</small><br>';
                                            }
                                            echo '<small>Player customizado completo disponível</small>';
                                            break;
                                        
                                        case 'trial':
                                            $days_remaining = get_option('converttize_trial_days_remaining', 0);
                                            $expires_at_trial = get_option('converttize_trial_expires_at', ''); 
                                            echo '<span style="color: #ff9800; font-weight: bold;">🎯 TRIAL ATIVO</span><br>';
                                            echo '<small>' . $days_remaining . ' dias restantes';
                                            if ($expires_at_trial) {
                                                echo ' (expira em ' . date('d/m/Y', strtotime($expires_at_trial)) . ')';
                                            }
                                            echo '</small>';
                                            break;
                                        
                                        case 'trial_expired':
                                            echo '<span style="color: red; font-weight: bold;">❌ TRIAL EXPIRADO</span><br>';
                                            echo '<small>Player básico do YouTube ativo - <a href="https://go.hotmart.com/F99854624X" >Adquirir Licença</a></small>';
                                            break;
                                        
                                        case 'inactive': 
                                            // Mensagens mais específicas para status 'inactive'
                                            $message_title = '⚠️ INATIVA';
                                            $message_detail = 'Player básico do YouTube ativo';
                                            $action_link = 'https://go.hotmart.com/F99854624X'; // URL padrão para compra/renovação

                                        switch ($license_reason) {
                                            case 'license_expired':
                                                $message_title = '❌ EXPIRADA (Data)';
                                                $message_detail .= '<br>Player básico do YouTube ativo';
                                                break;
                                            case 'payment_refunded':
                                                $message_title = '⚠️ REEMBOLSADA';
                                                $message_detail = 'Pagamento reembolsado. Player básico do YouTube ativo.';
                                                break;
                                            case 'subscription_cancelled':
                                                $message_title = '⚠️ CANCELADA';
                                                $message_detail = 'Assinatura cancelada. Player básico do YouTube ativo.';
                                                break;
                                            case 'payment_chargedback':
                                                $message_title = '⚠️ CHARGEBACK';
                                                $message_detail = 'Pagamento com chargeback. Player básico do YouTube ativo.';
                                                break;
                                            case 'license_suspended':
                                                $message_title = '⚠️ SUSPENSA';
                                                $message_detail = 'Licença suspensa. Player básico do YouTube ativo.';
                                                break;
                                            case 'domain_limit_exceeded':
                                                $message_title = '❌ LIMITE DE DOMÍNIOS EXCEDIDO';
                                                $message_detail = 'Você atingiu o limite de domínios ativos para sua licença.';
                                                $message_detail .= '<br>Player básico do YouTube ativo.';
                                                $action_link = 'https://suporte.seudominio.com/aumentar-dominios'; // Exemplo de link para aumentar domínios
                                                break;
                                            case 'license_not_found':
                                            case 'invalid_license_format':
                                                $message_title = '❌ CHAVE INVÁLIDA';
                                                $message_detail = 'A chave de licença não foi encontrada ou o formato é inválido.';
                                                $message_detail .= '<br>Player básico do YouTube ativo.';
                                                break;
                                            case 'security_hash_mismatch':
                                            case 'invalid_security_hash':
                                            case 'hotmart_status_unknown': // Caso fallback para outros status Hotmart
                                            case 'unknown_api_error': // Erro genérico na API
                                                $message_title = '❌ ERRO DE LICENÇA';
                                                $message_detail = 'Não foi possível validar sua licença devido a um erro técnico ou de segurança. Por favor, tente novamente ou entre em contato com o suporte.';
                                                $message_detail .= '<br>Player básico do YouTube ativo.';
                                                break;
                                            case 'pending': // Se o status for pendente (Hotmart ou outro)
                                                $message_title = '⏳ PENDENTE';
                                                $message_detail = 'O pagamento da sua licença está pendente. Player básico do YouTube ativo.';
                                                break;
                                            default:
                                                $message_title = '⚠️ SEM LICENÇA OU INATIVA';
                                                $message_detail = 'Player básico do YouTube ativo';
                                                break;
                                        }
                                            
                                        echo '<span style="color: ' . (strpos($message_title, '❌') !== false ? 'red' : (strpos($message_title, '⚠️') !== false || strpos($message_title, '⏳') !== false ? 'orange' : '#666')) . '; font-weight: bold;">' . esc_html($message_title) . '</span><br>';
                                        echo '<small>' . $message_detail;
                                        if (strpos($message_title, '❌') !== false || strpos($message_title, '⚠️') !== false || strpos($message_title, '⏳') !== false) {
                                            echo ' - <a href="' . esc_url($action_link) . '">Saiba Mais/Resolva</a>';
                                        }
                                        echo '</small>';
                                        break;
                                        
                                        case 'refunded': // Caso específico para 'refunded' se não for 'inactive' no Hotmart_Status
                                        case 'cancelled':
                                        case 'chargedback':
                                        case 'suspended':
                                        case 'pending':
                                            // Esses status agora são tratados no case 'inactive' (pela razão) se o validate.php estiver correto.
                                            // Mantenho estes casos aqui como um fallback se a lógica do validate.php for diferente no futuro,
                                            // mas o ideal é que eles caiam no 'inactive' e a razão seja exibida lá.
                                            echo '<span style="color: orange; font-weight: bold;">⚠️ ' . strtoupper(esc_html($status)) . '</span><br>';
                                            echo '<small>Player básico do YouTube ativo. (Razão: ' . esc_html($license_reason) . ')</small>';
                                            break;

                                        default: // Outros casos (se houver)
                                            echo '<span style="color: #666; font-weight: bold;">⚠️ SEM LICENÇA OU INATIVA</span><br>';
                                            echo '<small>Player básico do YouTube ativo</small>';
                                            break;
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Domínio</th>
                                <td><code><?php echo esc_html($_SERVER['HTTP_HOST']); ?></code></td>
                            </tr>
                            <tr>
                                <th scope="row">Última Verificação</th>
                                <td>
                                    <?php 
                                    $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
                                    $cache_time = get_transient($cache_key . '_time');
                                    echo $cache_time ? esc_html(date('d/m/Y H:i:s', $cache_time)) : 'Nunca';
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="card" style="max-width: 600px; margin-top: 20px;">
                        <h2>Gerenciar Licença</h2>
                        
                        <?php if (empty($current_license)): ?>
                            <!-- Formulário de Ativação -->
                            <form method="post">
                                <?php wp_nonce_field('converttize_license_action', 'converttize_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th scope="w-fit whitespace-nowrap row">
                                            <label for="license_key">Chave da Licença</label>
                                        </th>
                                        <td>
                                            <input type="text" 
                                                id="license_key" 
                                                name="license_key" 
                                                class="regular-text" 
                                                placeholder="Cole sua chave de licença aqui"
                                                required 
                                                pattern="[A-Z]+_[A-Z0-9_]{10,}"
                                                title="Formato: PREFIXO_TIMESTAMP_HASH (ex: STARTER_1750195826_C06151C9A1B1816E)" />
                                            <br>
                                            <button type="button" id="test-license-btn" class="button" style="margin-top: 10px;">
                                                🧪 Testar Chave
                                            </button>
                                            <p class="description">
                                                Insira a chave recebida por email após a compra.
                                                <?php /* // === Trial Check (COMENTADO) ===
                                                if ($status === 'trial'): ?>
                                                    <br><strong>Você está usando o trial gratuito de 7 dias.</strong>
                                                <?php elseif ($status === 'trial_expired'): ?>
                                                    <br><strong style="color: red;">Seu trial expirou. Adquira uma licença para continuar usando.</strong>
                                                <?php endif; */ ?>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button('🚀 Ativar Licença', 'primary', 'activate_license'); ?>
                            </form>
                            
                        <?php else: ?>
                            <!-- Licença Ativa -->
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Chave Ativa</th>
                                    <td>
                                        <code><?php echo esc_html(substr($current_license, 0, 8) . '...' . substr($current_license, -4)); ?></code>
                                        <br><small>Chave parcialmente oculta por segurança</small>
                                    </td>
                                </tr>
                                <?php if ($license_expires_at): // Exibir data de expiração aqui também ?>
                                <tr>
                                    <th scope="row">Expira em</th>
                                    <td>
                                        <strong><?php echo date('d/m/Y', strtotime($license_expires_at)); ?></strong>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                            
                            <p>
                                <form method="post" style="display: inline-block;">
                                    <?php wp_nonce_field('converttize_license_action', 'converttize_nonce'); ?>
                                    <?php submit_button('🔄 Verificar Agora', 'secondary', 'check_now', false); ?>
                                </form>
                                
                                <button type="button" id="clear-cache-btn" class="button" style="margin-left: 10px;">
                                    🧹 Limpar Cache
                                </button>
                                
                                <form method="post" style="display: inline-block; margin-left: 10px;">
                                    <?php wp_nonce_field('converttize_license_action', 'converttize_nonce'); ?>
                                    <?php submit_button('🗑️ Remover Licença', 'delete', 'remove_license', false, [
                                        'onclick' => 'return confirm("Tem certeza que deseja remover a licença?")'
                                    ]); ?>
                                </form>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php /* // === Trial Box (COMENTADO) ===
                if ($status === 'trial' || $status === 'trial_expired'): ?>
                <div class="card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #ff9800;">
                    <h2>🎯 Informações do Trial</h2>
                    <p>
                        <?php if ($status === 'trial'): ?>
                            <strong>Trial ativo!</strong> Você tem acesso completo ao player customizado por mais <?php echo get_option('converttize_trial_days_remaining', 0); ?> dias.
                        <?php else: ?>
                            <strong>Trial expirado!</strong> Para continuar usando o player customizado, adquira uma licença.
                        <?php endif; ?>
                    </p>
                    <p>
                        <a href="https://pay.hotmart.com/F99854624X"  class="button button-primary">
                            🛒 Adquirir Licença
                        </a>
                    </p>
                </div>
                <?php endif; */ ?>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                const nonce = '<?php echo wp_create_nonce('converttize_license_nonce'); ?>';
                
                $('#test-license-btn').click(function() {
                    const button = $(this);
                    const licenseKey = $('#license_key').val();
                    
                    if (!licenseKey) {
                        alert('Por favor, insira uma chave primeiro.');
                        return;
                    }
                    
                    button.prop('disabled', true).text('Testando...');
                    
                    $.post(ajaxurl, {
                        action: 'converttize_test_key',
                        license_key: licenseKey,
                        nonce: nonce
                    })
                    .done(function(response) {
                        // CORREÇÃO AQUI: Mais rigoroso para o que é considerado "sucesso" no feedback do teste
                        if (response.success && response.data && response.data.status === 'active') {
                            let message = '✅ Chave ativa e válida!';
                            if (response.data.expires_at) {
                                message += '\\nVálida até: ' + response.data.expires_at;
                            }
                            alert(message);
                        } else { // Qualquer outro status, inclusive success:true mas status != active
                            let warningMessage = '⚠️ Chave testada: ' + response.data.status.toUpperCase() + '.';
                            if (response.data.reason) {
                                warningMessage += '\\nRazão: ' + response.data.reason;
                            }
                            warningMessage += '\\nPor favor, clique em "Ativar Licença" para registrar o status completo.';
                            alert(warningMessage);
                        }
                    })
                    .fail(function(xhr, status, error) {
                        alert('❌ Erro na requisição AJAX: ' + status + ' ' + error + '\\nVerifique o console para mais detalhes.');
                        console.error("AJAX Error details:", xhr, status, error);
                    })
                    .always(function() {
                        button.prop('disabled', false).text('🧪 Testar Chave');
                    });
                });
                
                $('#clear-cache-btn').click(function() {
                    const button = $(this);
                    button.prop('disabled', true).text('Limpando...');
                    
                    $.post(ajaxurl, {
                        action: 'converttize_clear_cache_admin',
                        nonce: nonce
                    })
                    .done(function(response) {
                        if (response.success) {
                            alert('✅ Cache limpo!');
                            location.reload();
                        }
                    })
                    .always(function() {
                        button.prop('disabled', false).text('🧹 Limpar Cache');
                    });
                });
            });
            </script>
            <?php
        }
        
        public function activate_license($license_key) {
            if (!$this->validate_license_key_format($license_key)) {
                $this->log_security_event('invalid_activation_attempt', $license_key);
                return ['success' => false, 'message' => 'Formato de chave inválido'];
            }
            
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $timestamp = time();
            $request_hash = hash('sha256', $license_key . $domain . $timestamp . $this->security_secret);
            
            // O endpoint de ativação/validação é o validate.php, que agora também lida com desativação
            $response = wp_remote_post($this->api_url . 'validate.php', [ // <--- Endpoint único
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Converttize-Plugin/' . $this->plugin_version,
                    'X-Plugin-Version' => $this->plugin_version,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => [
                    'license_key' => $license_key,
                    'domain' => $domain,
                    'plugin_version' => $this->plugin_version,
                    'timestamp' => $timestamp,
                    'request_hash' => $request_hash,
                    'deactivate_domain' => false // Explicitamente falso para ativação
                ]
            ]);
            
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                $this->log_security_event('activation_connection_error', $error_msg);
                return ['success' => false, 'message' => 'Erro de conexão com servidor de licenças: ' . $error_msg, 'raw_response' => 'WP Error: ' . $error_msg];
            }
            
            $body = wp_remote_retrieve_body($response);
            $http_code = wp_remote_retrieve_response_code($response);
            
            error_log('CONVERTTIZE ACTIVATE DEBUG: HTTP Code: ' . $http_code); // Log para debug
            error_log('CONVERTTIZE ACTIVATE DEBUG: Raw Response Body: ' . $body); // Log para debug
            
            $result = json_decode($body, true);
            
            if (!$result) {
                $this->log_security_event('activation_invalid_response', $body);
                return ['success' => false, 'message' => 'Resposta inválida do servidor (não é JSON válido).', 'raw_response' => $body];
            }
            
            // Pega o status do nível raiz do JSON (se disponível), senão de dentro de 'data'.
            $api_status_from_server = $result['status'] ?? ($result['data']['status'] ?? 'inactive');
            $expires_at_from_activation = $result['data']['expires_at'] ?? null;
            $reason_from_activation = $result['data']['reason'] ?? null;
            $api_message_from_server = $result['message'] ?? ''; // Mensagem geral da API, se houver
            
            // LÓGICA CRÍTICA: A ativação é considerada um SUCESSO APENAS se o status retornado for 'active'.
            // Qualquer outro status (mesmo que a API tenha retornado 'success: true' no nível raiz do JSON)
            // é tratado como FALHA para ativação.
            if ($api_status_from_server === 'active') { // Usa o status principal
                update_option('converttize_license_key', $license_key);
                $this->set_cached_license_status('active', $expires_at_from_activation);
                update_option('converttize_license_cached_status', 'active');
                update_option('converttize_license_last_check', time());
                
                if ($expires_at_from_activation) {
                    update_option('converttize_license_expires_at', $expires_at_from_activation);
                }
                if ($reason_from_activation) {
                    update_option('converttize_license_reason', $reason_from_activation);
                } else {
                    delete_option('converttize_license_reason'); // Limpa se não houver razão específica
                }
                
                $this->log_security_event('license_activated', $license_key);
                
                return ['success' => true, 'message' => 'Licença ativada com sucesso!'];
            } else {
                // Se não é 'active', é uma falha na ativação do ponto de vista do plugin.
                $message_prefix = 'Falha na ativação: ';
                $final_message = $api_message_from_server; // Começa com a mensagem da API

                // Construir mensagem mais específica baseada no status/razão
                switch ($api_status_from_server) {
                    case 'refunded':
                        $final_message = 'Pagamento da licença foi reembolsado. Esta licença não pode ser ativada.';
                        break;
                    case 'cancelled':
                        $final_message = 'Assinatura da licença foi cancelada. Esta licença não pode ser ativada.';
                        break;
                    case 'chargedback':
                        $final_message = 'Pagamento da licença teve chargeback. Esta licença não pode ser ativada.';
                        break;
                    case 'license_expired':
                        $final_message = 'Esta licença está expirada e não pode ser ativada.';
                        break;
                    case 'domain_limit_exceeded':
                        $final_message = 'Você atingiu o limite de domínios permitidos para esta licença.';
                        break;
                    case 'license_not_found':
                    case 'invalid_license_format':
                        $final_message = 'A chave de licença fornecida é inválida ou não foi encontrada.';
                        break;
                    case 'security_hash_mismatch':
                    case 'invalid_security_hash':
                        $final_message = 'Erro de segurança na validação da licença. Por favor, tente novamente.';
                        break;
                    case 'pending':
                        $final_message = 'O pagamento da licença está pendente. Por favor, aguarde a aprovação.';
                        break;
                    case 'suspended':
                        $final_message = 'Esta licença foi suspensa e não pode ser ativada.';
                        break;
                    default:
                        // Se não há mensagem específica da API e nem status comum, use um fallback
                        if (empty($final_message)) {
                            $final_message = 'Licença inválida ou inativa.';
                        }
                        break;
                }
                
                // Adicionar a razão se disponível e não for redundante na mensagem final
                // Evita adicionar "(Razão: payment_refunded)" se a mensagem já diz "Pagamento reembolsado"
                if ($reason_from_activation && $reason_from_activation !== $api_status_from_server && strpos(strtolower($final_message), strtolower($reason_from_activation)) === false) {
                     $final_message .= ' (Razão: ' . esc_html($reason_from_activation) . ')';
                }

                $this->log_security_event('activation_failed', $api_status_from_server . ($reason_from_activation ? ' (' . $reason_from_activation . ')' : ''));
                
                return ['success' => false, 'message' => $message_prefix . $final_message, 'raw_response' => $body, 'reason' => $reason_from_activation ?? $api_status_from_server];
            }
        }
        
        public function background_license_check() {
            $license_key = get_option('converttize_license_key');
            if (!empty($license_key)) {
                $this->validate_license_online($license_key);
            } else {
                /*
                // === Check de Trial em Background (COMENTADO) ===
                $this->check_trial_status();
                */
            }
        }
    }
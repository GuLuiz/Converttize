<?php

defined('ABSPATH') || exit;

class YT_Custom_Player_Render {

    public function __construct() {
        add_shortcode('ytp_player', [$this, 'render_player']);
    }

    private function get_default_player_options() {
        // As opções padrão para o player são obtidas do YT_Player_Admin_Settings
        // para garantir consistência entre o admin e o frontend.
        global $ytp_admin_settings_instance;
        // NOVO: A instância já deve vir corretamente instanciada e global.
        // Se, por alguma razão, não for uma instância válida, retorna um array vazio
        // ou um array de defaults estático para evitar fatal errors.
        if (!($ytp_admin_settings_instance instanceof YT_Player_Admin_Settings)) {
            // Isso não deve acontecer se o plugin principal estiver carregando corretamente
            // Mas é uma salvaguarda.
            error_log('ERRO: $ytp_admin_settings_instance não é uma instância de YT_Player_Admin_Settings.');
            return []; // Retorna um array vazio ou um set mínimo de defaults
        }
        return $ytp_admin_settings_instance->get_default_options();
    }

    public function render_player($atts) {
        // START ALTERATION - Adicionado 'player_instance_id'
        $atts = shortcode_atts([
            'video_id' => '',
            'player_instance_id' => '', // ID único para esta instância do player
        ], $atts, 'ytp_player');
        // END ALTERATION

        if (empty($atts['video_id'])) {
            return '<p style="color:red;">⚠️ ' . __('Informe o atributo video_id no shortcode.', 'lume-player') . '</p>';
        }

        // Lógica para carregar opções: primeiro específicas do vídeo, depois globais
        $video_id = sanitize_text_field($atts['video_id']);
        
        $default_options = $this->get_default_player_options();
        
        // 2. Carregar opções globais
        $global_options = get_option('lume_player_options', []);
        
        // 3. Mesclar: default <- global <- video_specific (video_specific sobreescreve global, global sobreescreve default)
        // Usamos array_merge para sobrescrever valores existentes.
        $options = array_merge($default_options, $global_options);
        $video_specific_options_key = 'lume_player_options_' . $video_id; // Movido para após a definição de $options
        $video_options = get_option($video_specific_options_key);
        if ($video_options !== false && is_array($video_options)) {
            $options = array_merge($options, $video_options);
        }

        // NOVO: Aplicar regras de consistência dos controles com base no `progress_bar_type`
        switch ($options['progress_bar_type']) {
            case 'youtube':
                $options['hide_chrome'] = false; // Mostrar controles nativos do YouTube (inclui barra de progresso)
                $options['enable_play_pause_buttons'] = false; // Desabilitar botões customizados, usar os nativos
                $options['enable_progress_bar'] = true; // Garantir que a barra esteja ativa (nativa)
                $options['enable_progress_bar_seek'] = true; // Garantir seek nativo
                break;
            case 'plugin':
                $options['hide_chrome'] = true; // Esconder controles nativos
                // Manter enable_play_pause_buttons como configurado (pode ser customizado ou não)
                $options['enable_progress_bar'] = true; // Ativar barra de progresso customizada
                // Manter enable_progress_bar_seek como configurado
                break;
            case 'none':
                $options['hide_chrome'] = true; // Esconder controles nativos
                // Manter enable_play_pause_buttons como configurado
                $options['enable_progress_bar'] = false; // Desativar barra de progresso customizada
                $options['enable_progress_bar_seek'] = false; // Desativar seek customizado
                break;
            default:
                // Fallback para o comportamento padrão (plugin)
                $options['progress_bar_type'] = 'plugin';
                $options['hide_chrome'] = true;
                $options['enable_progress_bar'] = true;
        }

        wp_enqueue_style('ytp-style');
        wp_enqueue_script('ytp-script');

        $colors = [
            'primary_color'   => esc_attr($options['primary_color']),
            'secondary_color' => esc_attr($options['secondary_color']),
            'progress_color'  => esc_attr($options['progress_color']),
            'text_color'      => esc_attr($options['text_color']),
            'overlay_bg'      => esc_attr($options['overlay_bg']),
        ];

        $uid = 'ytp_' . wp_unique_id(); // ID para o iframe HTML
        // START ALTERATION - Definindo o ID da instância do player
        $player_instance_id = !empty($atts['player_instance_id']) ? sanitize_key($atts['player_instance_id']) : $uid;
        // END ALTERATION

        $primary_rgb_array = $this->hex2rgb($colors['primary_color']);
        $primary_rgb_string = $primary_rgb_array ? implode(', ', $primary_rgb_array) : '255, 149, 0';

        $dynamic_css = "
            #{$uid}_container {
                --ytp-primary-color: {$colors['primary_color']} !important;
                --ytp-secondary-color: {$colors['secondary_color']} !important;
                --ytp-progress-color: {$colors['progress_color']} !important;
                --ytp-text-color: {$colors['text_color']} !important;
                --ytp-overlay-bg: {$colors['overlay_bg']} !important;
                --ytp-primary-color-rgb: {$primary_rgb_string} !important;
            }

            #{$uid}_container .ytp-sound-overlay {
                background: {$colors['overlay_bg']} !important;
                color: {$colors['text_color']} !important;
                box-shadow: 0 0 15px {$colors['primary_color']} !important;
            }

            #{$uid}_container .ytp-video-ended-overlay {
                background: #000000 !important;
                color: {$colors['text_color']} !important;
            }

            #{$uid}_container .ytp-video-ended-overlay .ytp-ended-btn {
                background: {$colors['primary_color']} !important;
                color: {$colors['text_color']} !important;
            }

            #{$uid}_container .ytp-video-ended-overlay .ytp-ended-btn:hover {
                background: {$colors['secondary_color']} !important;
            }

            #{$uid}_container .ytp-progress-bar {
                background-color: {$colors['progress_color']} !important;
            }

            #{$uid}_container .ytp-btn {
                color: {$colors['text_color']} !important;
            }

            #{$uid}_container .ytp-btn:hover {
                background: rgba({$primary_rgb_string}, 0.85) !important;
            }

            #{$uid}_container .ytp-replay-popup button {
                background: {$colors['secondary_color']} !important;
                color: {$colors['text_color']} !important;
            }

            #{$uid}_container .ytp-replay-popup button:hover {
                background: {$colors['primary_color']} !important;
            }

            /* NOVO: Garante que o wrapper seja clicável e o iframe dentro dele também */
            .ytp-wrapper.ytp-native-controls-active {
                pointer-events: auto !important; 
            }
            .ytp-wrapper.ytp-native-controls-active .ytp-iframe {
                pointer-events: auto !important; 
            }
        ";

        wp_add_inline_style('ytp-style', $dynamic_css);

        ob_start(); // Start output buffering to capture HTML
        ?>
        <div class="ytp-wrapper <?php echo esc_attr($this->get_feature_classes($options)); ?> <?php echo (isset($options['progress_bar_type']) && $options['progress_bar_type'] === 'youtube') ? 'ytp-native-controls-active' : ''; ?>"
            id="<?php echo esc_attr($uid); ?>_container"
            data-video-id="<?php echo esc_attr($atts['video_id']); ?>"
            data-player-instance-id="<?php echo esc_attr($player_instance_id); ?>" <!-- IMPORTANTE: Passa o ID da instância -->
            data-colors='<?php echo esc_attr(json_encode($colors)); ?>'>
            
            <!-- Adicionado estilos inline para garantir que o container do iframe cubra toda a área -->
            <div id="<?php echo esc_attr($uid); ?>" class="ytp-iframe" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
            <!-- Para itens com delay externos, o player.js irá escanear o DOM por elementos com data-attributes -->
            <!-- e que tenham data-ytp-player-target="<?php echo esc_attr($player_instance_id); ?>" -->


            <?php if ($options['enable_sound_overlay']) : // START ALTERATION - Condicional para sound overlay ?>
            <div class="ytp-sound-overlay">
                <p class="ytp-sound-message"></p>
                <div class="ytp-sound-icon">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M16.5 12L19 14.5M19 9.5L16.5 12M9 9H5v6h4l5 5V4l-5 5z" stroke="currentColor" stroke-width="2" fill="none"/></svg>
                </div>
                <p class="ytp-sound-click"></p>
            </div>
            <?php endif; // END ALTERATION ?>

            <!-- NOVO BLOCO: Botão de desmutar com ícone de play quando o sound overlay está desativado -->
            <?php if (!$options['enable_sound_overlay']) : ?>
            <div class="ytp-muted-autoplay-overlay">
                <div class="ytp-ended-icon"> <!-- Reusa a classe do ended overlay para o SVG -->
                    <!-- SVG do ícone de play (mesmo do ended overlay) -->
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                </div>
            </div>
            <?php endif; ?>
            <!-- FIM NOVO BLOCO -->

            <?php if ($options['enable_ended_overlay']) : // START ALTERATION - Condicional para ended overlay ?>
            <!-- ✅ OVERLAY DE FIM DE VÍDEO COMPLETA -->
            <div class="ytp-video-ended-overlay">
                <p class="ytp-ended-message"></p>
                <div class="ytp-ended-icon">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                </div>
                <div class="ytp-ended-buttons">
                    <button class="ytp-ended-btn" data-action="replay"></button>
                </div>
            </div>
            <?php endif; // END ALTERATION ?>

            <?php if ($options['enable_play_pause_buttons']) : // START ALTERATION - Condicional para botões de play/pause ?>
            <div class="ytp-controls">
                <button class="ytp-btn ytp-play" title="">
                    <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </button>
                <button class="ytp-btn ytp-pause" title="" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                </button>
            </div>
            <?php endif; // END ALTERATION ?>

            <?php if (isset($options['progress_bar_type']) && $options['progress_bar_type'] === 'plugin') : // Condicional para barra de progresso do plugin ?>
            <div class="ytp-progress">
                <div class="ytp-progress-bar"></div>
            </div>
            <?php endif; // FIM ALTERATION ?>

        </div>

        <script>
            window['ytpData_<?php echo esc_js($uid); ?>'] = {
                video_id: "<?php echo esc_js($atts['video_id']); ?>",
                player_id: "<?php echo esc_js($uid); ?>",
                // START ALTERATION - Passa o ID da instância para o JS
                player_instance_id: "<?php echo esc_js($player_instance_id); ?>", 
                // END ALTERATION
                features: <?php echo wp_json_encode($options); ?>,
                colors: <?php echo wp_json_encode($colors); ?>
            };

            document.addEventListener('DOMContentLoaded', function() {
                const container = document.getElementById('<?php echo esc_js($uid); ?>_container');
                if (container) {
                    const colors = <?php echo wp_json_encode($colors); ?>;
                    Object.keys(colors).forEach(key => {
                        const cssVar = '--ytp-' + key.replace('_', '-');
                        container.style.setProperty(cssVar, colors[key]);
                    });
                    container.style.setProperty('--ytp-primary-color-rgb', '<?php echo esc_js($primary_rgb_string); ?>');
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }

    private function get_feature_classes($options) {
        $classes = [];
        if (!empty($options['fullscreen'])) $classes[] = 'ytp-fullscreen-enabled';
        if (!empty($options['speed'])) $classes[] = 'ytp-speed-enabled';
        if (!empty($options['quality'])) $classes[] = 'ytp-quality-enabled';
        if (!empty($options['repeat'])) $classes[] = 'ytp-repeat-enabled';
        // Esta classe é condicionalmente aplicada: apenas se hide_chrome for true
        // No render_player, garantimos que hide_chrome será false se progress_bar_type for 'youtube'
        if (!empty($options['hide_chrome'])) $classes[] = 'ytp-hide-native-chrome';
        
        return implode(' ', $classes);
    }

    private function hex2rgb($hex_color_string) {
        $hex = str_replace("", "", $hex_color_string);
        if (empty($hex)) return [255, 149, 0];

        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } elseif (strlen($hex) == 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        } else {
            return [255, 149, 0];
        }
        return [$r, $g, $b];
    }
}

// REMOVIDO: Este bloco de inicialização redundante que estava causando o erro
// Porque YT_Player_Admin_Settings já é instanciada e globalizada corretamente
// na classe YT_Custom_Player_Plugin (o arquivo principal do plugin).
/*
global $ytp_admin_settings_instance;
if (!($ytp_admin_settings_instance instanceof YT_Player_Admin_Settings)) {
    $ytp_admin_settings_instance = new YT_Player_Admin_Settings(); 
}
*/
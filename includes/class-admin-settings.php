<?php

class YT_Player_Admin_Settings {
    private $option_name = 'lume_player_options'; // Nome para as opções GLOBAIS
    private $page_slug = 'lume-player-settings';
    private $current_video_id = null; // Para armazenar o ID do vídeo se estivermos editando um específico
    private $is_video_specific_editing = false; // Flag para saber se é edição por vídeo
    private $main_plugin_instance; // Nova propriedade para armazenar a instância do plugin principal

    private $custom_color_palettes = [
        '#D0021B', '#F5A623', '#F8E71C', '#7ED321',
        '#4A90E2', '#9013FE', '#000000', '#FFFFFF',
        '#4A4A4A', '#9B9B9B', '#CCCCCC', 'rgba(0,0,0,0.5)'
    ];

    // MODIFICADO: Construtor agora aceita a instância do plugin principal
    public function __construct($main_plugin_instance) {
        $this->main_plugin_instance = $main_plugin_instance; // Armazena a instância do plugin principal
        add_action('admin_init', [$this, 'register_settings_sections_and_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_lume_player_save_options', [$this, 'ajax_save_options']);

        // Detectar se é uma edição específica de vídeo
        if (isset($_GET['video_id']) && !empty($_GET['video_id'])) {
            $this->current_video_id = sanitize_text_field($_GET['video_id']);
            $this->is_video_specific_editing = true;
        }
    }

    private function sanitize_rgba($rgba_string, $field_id) {
        $default_options = $this->get_default_options();
        $default_value = $default_options[$field_id] ?? 'rgba(0,0,0,0.75)'; // Default conforme seu código

        if (empty($rgba_string)) {
            return $default_value;
        }

        // Regex para capturar os componentes RGBA
        if (preg_match('/rgba?\((\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+))?\s*\)/i', $rgba_string, $matches)) {
            $r = intval($matches[2]);
            $g = intval($matches[3]);
            $b = intval($matches[4]);
            $a = isset($matches[5]) ? floatval($matches[5]) : 1; // Default a 1 se alpha ausente (para rgb())
            // Garante que os valores estejam dentro do range válido
            $r = max(0, min(255, $r));
            $g = max(0, min(255, $g));
            $b = max(0, min(255, $b));
            $a = max(0, min(1, $a));

            // Retorna no formato rgba, se houver transparência, ou rgb se for opaco
            if ($a < 1) {
                return "rgba({$r},{$g},{$b},{$a})";
            } else {
                return "rgb({$r},{$g},{$b})"; // Ou pode retornar hex para alpha=1, mas rgb é mais consistente
            }
        }

        // Se não for um formato RGBA/RGB, tenta como HEX (caso o wpColorPicker tenha retornado hex por algum motivo)
        if (strpos($rgba_string, '#') === 0) {
            $sanitized_hex = sanitize_hex_color($rgba_string);
            if ($sanitized_hex) {
                return $sanitized_hex;
            }
        }

        return $default_value; // Retorna o valor padrão se a entrada for completamente inválida
    }

    // Método público para obter as opções padrão do plugin
    public function get_default_options() {
        return [
            'fullscreen'        => false,
            'speed'             => false,
            'quality'           => false,
            'repeat'            => false,
            'hide_chrome'       => true,
            'unlock_after'      => 10,
            'primary_color'     => '#FF9500',
            'secondary_color'   => '#FF3300',
            'progress_color'    => '#FF9500',
            'text_color'        => '#FFFFFF',
            'overlay_bg'        => 'rgba(0,0,0,0.75)',
            'player_lang'       => 'en',
            'delayed_items'     => [], // Mantido, mas a lógica para delayed items externos é via JS/HTML
            'enable_sound_overlay'         => true,
            'enable_ended_overlay'         => true,
            'enable_play_pause_buttons'    => true,
            'progress_bar_type'            => 'plugin', // NOVO: Tipo da barra de progresso (plugin, youtube, none)
            'enable_progress_bar_seek'     => false,

            // NOVAS OPÇÕES: TEXTOS DO PLAYER PARA INTERNACIONALIZAÇÃO
            'sound_overlay_message'        => 'Seu vídeo já começou',
            'sound_overlay_click'          => 'Clique para ouvir',
            'ended_overlay_message'        => 'Vídeo finalizado',
            'ended_overlay_replay_button'  => 'Assistir novamente',
            'play_button_title'            => 'Play',
            'pause_button_title'           => 'Pause',
            'paused_message'               => 'Vídeo pausado.',
            'paused_continue_button'       => 'Continuar',
            'paused_restart_button'        => 'Começar do início',

            // CAMPOS DE BRANDING
            'plugin_header_logo_url'       => '', // URL da logo para o cabeçalho de branding
            'plugin_header_title'          => '', // Título/slogan para o cabeçalho de branding
            'plugin_header_link'           => '', // Link para o cabeçalho de branding
            'enable_plugin_header'         => false, // Se o cabeçalho de branding deve ser global no admin (desabilitado para esta requisição)
        ];
    }

    private function get_specific_default_color($color_id) {
        $default_options = $this->get_default_options();
        return $default_options[$color_id] ?? '#000000';
    }

    private function rgba_to_hex($rgba_string) {
        $rgba_string = trim(str_replace(' ', '', $rgba_string));
        
        if (preg_match('/rgba?\((\d{1,3}),(\d{1,3}),(\d{1,3})(?:,([\d.]+))?\)/i', $rgba_string, $matches)) {
            $r = intval($matches[1]);
            $g = intval($matches[2]);
            $b = intval($matches[3]);
            
            $r = max(0, min(255, $r));
            $g = max(0, min(255, $g));
            $b = max(0, min(255, $b));
            
            return sprintf("#%02x%02x%02x", $r, $g, $b);
        }
        
        return $rgba_string;
    }

    public function register_settings_sections_and_fields() {
        add_settings_section(
            'lume_player_general_settings_section',
            __('Configurações Gerais', 'lume-player'),
            function() { 
                echo '<p>' . __('Opções de comportamento e funcionalidade do player.', 'lume-player') . '</p>'; 
                if ($this->is_video_specific_editing) {
                     echo '<p><strong>' . __('Estas configurações se aplicam APENAS ao vídeo:', 'lume-player') . ' ' . esc_html($this->current_video_id) . '</strong></p>';
                } else {
                     echo '<p><strong>' . __('Estas são as configurações GLOBAIS do player. Elas serão usadas a menos que um vídeo tenha configurações específicas.', 'lume-player') . '</strong></p>';
                }
            },
            $this->page_slug
        );
        add_settings_section(
            'lume_player_colors_section',
            __('Personalização de Cores', 'lume-player'),
            function() { echo '<p>' . __('Defina as cores do player. Para "Fundo do Overlay", use o controle de transparência.', 'lume-player') . '</p>'; },
            $this->page_slug
        );
        $color_settings = [
            'primary_color'    => __('Cor Primária', 'lume-player'),
            'secondary_color'  => __('Cor Secundária', 'lume-player'),
            'progress_color'   => __('Cor da Barra de Progresso', 'lume-player'),
            'text_color'       => __('Cor do Texto e Ícones', 'lume-player'),
            'overlay_bg'       => __('Fundo do Overlay (com transparência)', 'lume-player')
        ];
        foreach ($color_settings as $id => $label) {
            add_settings_field($id, $label, [$this, 'render_color_field'], $this->page_slug, 'lume_player_colors_section', ['label_for' => $id, 'option_name' => 'dynamic_option_name']);
        }
        
        // Elementos da Interface do Player
        add_settings_section(
            'lume_player_ui_elements_section',
            __('Elementos da Interface do Player', 'lume-player'),
            function() {
                echo '<p>' . __('Controle a visibilidade de elementos específicos do player customizado.', 'lume-player') . '</p>';
                if ($this->is_video_specific_editing) {
                    echo '<p><strong>' . __('Estas configurações se aplicam APENAS ao vídeo:', 'lume-player') . ' ' . esc_html($this->current_video_id) . '</strong></p>';
                } else {
                    echo '<p><strong>' . __('Estas são as configurações GLOBAIS. Elas serão usadas a menos que um vídeo tenha configurações específicas.', 'lume-player') . '</strong></p>';
                }
            },
            $this->page_slug
        );

        add_settings_field(
            'enable_sound_overlay',
            __('Overlay "Clique para Ouvir"', 'lume-player'),
            [$this, 'render_checkbox_field'],
            $this->page_slug,
            'lume_player_ui_elements_section',
            ['label_for' => 'enable_sound_overlay', 'option_name' => 'dynamic_option_name']
        );
        add_settings_field(
            'enable_ended_overlay',
            __('Overlay "Vídeo Finalizado"', 'lume-player'),
            [$this, 'render_checkbox_field'],
            $this->page_slug,
            'lume_player_ui_elements_section',
            ['label_for' => 'enable_ended_overlay', 'option_name' => 'dynamic_option_name']
        );
        add_settings_field(
            'enable_play_pause_buttons',
            __('Botões de Play/Pause', 'lume-player'),
            [$this, 'render_checkbox_field'],
            $this->page_slug,
            'lume_player_ui_elements_section',
            ['label_for' => 'enable_play_pause_buttons', 'option_name' => 'dynamic_option_name']
        );
    // Campo para tipo de barra de progresso (agora centralizado)
    add_settings_field(
        'progress_bar_type',
        __('Barra de Progresso', 'lume-player'), // Título mais direto
        [$this, 'render_select_field'],
        $this->page_slug,
        'lume_player_ui_elements_section',
        ['label_for' => 'progress_bar_type', 'option_name' => 'dynamic_option_name',
         'options' => ['plugin' => __('Barra personalizada', 'lume-player'), 'youtube' => __('Padrão do YouTube', 'lume-player'), 'none' => __('Nenhuma', 'lume-player')],
         'description' => __('Selecione o tipo de barra de progresso. A opção "Nenhuma" desativará a barra completamente.', 'lume-player')] // Descrição para esclarecer
        );
        
        // NOVA SEÇÃO: PLAYER TEXTS
        add_settings_section(
            'lume_player_text_settings_section',
            __('Textos do Player', 'lume-player'),
            function() { 
                echo '<p>' . __('Personalize os textos e mensagens exibidos no player. Por padrão, eles já estão traduzíveis, mas aqui você pode sobrescrevê-los para o que desejar.', 'lume-player') . '</p>'; 
                if ($this->is_video_specific_editing) {
                    echo '<p><strong>' . __('Estas configurações se aplicam APENAS ao vídeo:', 'lume-player') . ' ' . esc_html($this->current_video_id) . '</strong></p>';
                } else {
                    echo '<p><strong>' . __('Estas são as configurações GLOBAIS. Elas serão usadas a menos que um vídeo tenha configurações específicas.', 'lume-player') . '</strong></p>';
                }
            },
            $this->page_slug
        );

        $text_settings = [
            'sound_overlay_message' => __('Mensagem Overlay de Som', 'lume-player'),
            'sound_overlay_click' => __('Texto Overlay de Som (Clique)', 'lume-player'),
            'ended_overlay_message' => __('Mensagem Overlay Finalizado', 'lume-player'),
            'ended_overlay_replay_button' => __('Botão Overlay Finalizado (Reassistir)', 'lume-player'),
            'play_button_title' => __('Título Botão Play', 'lume-player'),
            'pause_button_title' => __('Título Botão Pause', 'lume-player'),
            'paused_message' => __('Mensagem Vídeo Pausado', 'lume-player'),
            'paused_continue_button' => __('Botão Pausado (Continuar)', 'lume-player'),
            'paused_restart_button' => __('Botão Pausado (Reiniciar)', 'lume-player'),
        ];

        foreach ($text_settings as $id => $label) {
            add_settings_field(
                $id, 
                $label, 
                [$this, 'render_text_field'], 
                $this->page_slug, 
                'lume_player_text_settings_section', 
                ['label_for' => $id, 'option_name' => 'dynamic_option_name']
            );
        }
        

        add_settings_field(
            'plugin_header_logo_url',
            __('URL da Logo do Cabeçalho', 'lume-player'),
            [$this, 'render_text_field'],
            $this->page_slug,
            'lume_player_branding_header_section',
            ['label_for' => 'plugin_header_logo_url', 'option_name' => 'dynamic_option_name', 'placeholder' => __('Ex: https://seusite.com/logo.png', 'lume-player')]
        );

        add_settings_field(
            'plugin_header_title',
            __('Texto do Título/Slogan', 'lume-player'),
            [$this, 'render_text_field'],
            $this->page_slug,
            'lume_player_branding_header_section',
            ['label_for' => 'plugin_header_title', 'option_name' => 'dynamic_option_name', 'placeholder' => __('Ex: Desenvolvido por Minha Empresa', 'lume-player')]
        );

        add_settings_field(
            'plugin_header_link',
            __('Link do Cabeçalho', 'lume-player'),
            [$this, 'render_text_field'],
            $this->page_slug,
            'lume_player_branding_header_section',
            ['label_for' => 'plugin_header_link', 'option_name' => 'dynamic_option_name', 'placeholder' => __('Ex: https://seusite.com', 'lume-player')]
        );
        
        // Opção para ativar o cabeçalho de branding *globalmente* no admin (mantida, mas não para este caso de uso)
        add_settings_field(
            'enable_plugin_header',
            __('Ativar Cabeçalho de Branding em todas as páginas do ADMIN?', 'lume-player'),
            [$this, 'render_checkbox_field'],
            $this->page_slug,
            'lume_player_branding_header_section',
            ['label_for' => 'enable_plugin_header', 'option_name' => 'dynamic_option_name', 'description' => __('<strong>CUIDADO:</strong> Esta opção injetará o cabeçalho de branding no topo de CADA página do seu painel admin. Pode causar conflitos de design com o tema atual. Deixe DESMARCADO se quiser apenas nas páginas do Converttize!', 'lume-player')]
        );
        
        // Condicional para exibir a seção de Delayed Items
        if ($this->is_video_specific_editing) {
            add_settings_section(
                'lume_player_delayed_items_section',
                __('Itens com Delay (Atrações)', 'lume-player'),
                function() { 
                    echo '<p>' . __('Configure elementos que aparecerão em momentos específicos do vídeo.', 'lume-player') . '</p>'; 
                },
                $this->page_slug
            );
            add_settings_field(
                'delayed_items',
                __('Configurar Itens', 'lume-player'),
                [$this, 'render_delayed_items_field'],
                $this->page_slug,
                'lume_player_delayed_items_section',
                ['label_for' => 'delayed_items', 'option_name' => 'dynamic_option_name']
            );
        }
    }

    /**
     * Sanitiza as opções salvas.
     * Esta função será chamada diretamente por ajax_save_options.
     */
    public function sanitize_options($input) {
        $sanitized_input = [];
        $default_options = $this->get_default_options();

        foreach (['fullscreen', 'speed', 'quality', 'repeat', 'hide_chrome', 'enable_sound_overlay', 'enable_ended_overlay', 'enable_play_pause_buttons', 'enable_progress_bar_seek', 'enable_plugin_header'] as $key) { 
            $sanitized_input[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

    // Sanitiza o tipo da barra de progresso
    $valid_bar_types = ['plugin', 'youtube', 'none'];
    $sanitized_input['progress_bar_type'] = in_array($input['progress_bar_type'] ?? '', $valid_bar_types) ? $input['progress_bar_type'] : $default_options['progress_bar_type'];

        // SANITIZE NEW PLAYER TEXT FIELDS
        foreach ([
            'sound_overlay_message',
            'sound_overlay_click',
            'ended_overlay_message',
            'ended_overlay_replay_button',
            'play_button_title',
            'pause_button_title',
            'paused_message',
            'paused_continue_button',
            'paused_restart_button',
            // NOVOS CAMPOS DE BRANDING
            'plugin_header_logo_url',
            'plugin_header_title',
            'plugin_header_link',
        ] as $key) {
            $sanitized_input[$key] = isset($input[$key]) ? sanitize_text_field($input[$key]) : $default_options[$key];
        }

        $sanitized_input['unlock_after'] = isset($input['unlock_after']) ? absint($input['unlock_after']) : $default_options['unlock_after'];
        $sanitized_input['player_lang'] = isset($input['player_lang']) ? sanitize_text_field(strtolower(substr(trim($input['player_lang']), 0, 2))) : $default_options['player_lang'];
        foreach (['primary_color', 'secondary_color', 'progress_color', 'text_color'] as $field) {
            $value = isset($input[$field]) ? trim($input[$field]) : '';
            if (empty($value)) {
                $sanitized_input[$field] = $default_options[$field];
            } else {
                if (stripos($value, 'rgba') === 0 || stripos($value, 'rgb') === 0) {
                    $value = $this->rgba_to_hex($value);
                }
                $sanitized_color = sanitize_hex_color($value);
                $sanitized_input[$field] = $sanitized_color ?: $default_options[$field];
            }
        }
        
        $value_overlay_bg = isset($input['overlay_bg']) ? trim($input['overlay_bg']) : '';
        $sanitized_input['overlay_bg'] = $this->sanitize_rgba($value_overlay_bg, 'overlay_bg');
        
        // Manipulação simplificada e padronizada de delayed_items
        if (isset($input['delayed_items_json']) && is_string($input['delayed_items_json'])) {
            $delayed_items_array = json_decode(stripslashes($input['delayed_items_json']), true); // Decodifica a string JSON
            if (is_array($delayed_items_array)) {
                $sanitized_input['delayed_items'] = [];
                // Se houver mais de um item, apenas o primeiro será considerado válido
                if (!empty($delayed_items_array)) {
                    $item_input = $delayed_items_array[0]; // Pega apenas o primeiro item
                    if (is_array($item_input)) {
                        $s_item = [];
                        $s_item['id'] = isset($item_input['id']) && !empty($item_input['id']) ? sanitize_text_field($item_input['id']) : 'item_' . wp_generate_uuid4();
                        $s_item['selector'] = isset($item_input['selector']) ? sanitize_text_field(trim($item_input['selector'])) : '';
                        $s_item['time'] = isset($item_input['time']) ? absint($item_input['time']) : 0;

                        $s_item['type'] = 'activate_element_by_class'; 
                        $s_item['class_to_add'] = 'is-visible'; 
                        
                        if(!empty($s_item['selector'])){
                            $sanitized_input['delayed_items'][] = $s_item;
                        }
                    }
                }
            } else {
                $sanitized_input['delayed_items'] = $default_options['delayed_items']; // Retorna para o padrão se o JSON for inválido
            }
        } else {
             $sanitized_input['delayed_items'] = $default_options['delayed_items'];
        }
        return $sanitized_input;
    }

    private function extract_youtube_id(string $input): string {
        $input = trim($input);
        $pattern = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|embed|shorts|live)/|.*[?&](?:v=|video_id=))|youtu\.be/)([A-Za-z0-9_-]{11})%i';
        if (preg_match($pattern, $input, $matches)) return $matches[1];
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) return $input;
        return '';
    }

    // Adaptei as funções render para carregar o valor correto da opção
    public function render_checkbox_field($args) { 
        $options = $this->get_current_options_for_rendering(); // NOVO: pegar as opções certas
        $id = esc_attr($args['label_for']); 
        $checked = !empty($options[$id]);
        printf('<input type="checkbox" id="%1$s" name="options[%1$s]" value="1" %2$s />', $id, checked($checked, true, false)); 
        if (isset($args['description'])) { // Adicionando descrição para o checkbox
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_number_field($args) { 
        $options = $this->get_current_options_for_rendering(); // NOVO: pegar as opções certas
        $id = esc_attr($args['label_for']); 
        $defaults = $this->get_default_options(); 
        $value = $options[$id] ?? ($defaults[$id] ?? 0);
        printf('<input type="number" id="%1$s" name="options[%1$s]" value="%2$d" min="%3$d" max="%4$d" step="%5$d" class="small-text"/>', $id, intval($value), ($args['min'] ?? 0), ($args['max'] ?? 3600), ($args['step'] ?? 1)); 
    }

    public function render_text_field($args) { 
        $options = $this->get_current_options_for_rendering(); // NOVO: pegar as opções certas
        $id = esc_attr($args['label_for']);
        $defaults = $this->get_default_options(); 
        $value = $options[$id] ?? ($defaults[$id] ?? ''); 
        printf('<input type="text" id="%1$s" name="options[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s"/>', $id, esc_attr($value), esc_attr($args['placeholder'] ?? '')); 
    }

    public function render_color_field($args) {
        $options = $this->get_current_options_for_rendering(); // NOVO: pegar as opções certas
        $id = esc_attr($args['label_for']);
        $default_color_value = $this->get_specific_default_color($id);
        $value = $options[$id] ?? $default_color_value;
        $is_rgba_field = ($id === 'overlay_bg');

        printf(
            '<input type="text" class="color-picker" id="%s" name="options[%s]" value="%s" data-default-color="%s" data-alpha="%s" />',
            esc_attr($id),
            esc_attr($id), // Nome do campo 'options[id]'
            esc_attr($value),
            esc_attr($default_color_value),
            $is_rgba_field ? 'true' : 'false'
        );
        if ($is_rgba_field) {
            echo '<p class="description">' . __('Permite transparência (alpha).', 'lume-player') . '</p>';
        }
    }

    public function render_select_field($args) {
        $options = $this->get_current_options_for_rendering();
        $id = esc_attr($args['label_for']);
        $defaults = $this->get_default_options();
        $value = $options[$id] ?? ($defaults[$id] ?? '');
        $select_options = $args['options'] ?? [];

        printf('<select id="%1$s" name="options[%1$s]">', $id);
        foreach ($select_options as $option_value => $option_label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($option_value), selected($value, $option_value, false), esc_html($option_label));
        }
        echo '</select>';
        if (isset($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * Renderiza o campo para gerenciar os "Itens com Delay".
     * O campo oculto `lume-delayed-items-data` agora terá o JSON string.
     */
    public function render_delayed_items_field($args) {
        $options = $this->get_current_options_for_rendering(); // NOVO: pegar as opções certas
        $delayed_items = $options['delayed_items'] ?? [];
        
        // NOVO: Define o ID do vídeo atual para preencher o código HTML
        $video_id_for_html = $this->is_video_specific_editing ? $this->current_video_id : 'SEU_VIDEO_ID_AQUI';
        $player_div_id = 'ytp_player_gated_content_target' . ($this->is_video_specific_editing ? '_' . $this->current_video_id : ''); // ID do container do player. Adapta o ID se for edição por vídeo.
        $shortcode_html = '[ytp_player video_id="' . $video_id_for_html . '"]';
        // O HTML completo com o shortcode
        $html_example_code = htmlentities('<div style="position: relative; width: 100%; max-width: 800px; margin: 0 auto; min-height: 400px;">
    <div id="' . $player_div_id . '" style="width: 100%; height: 100%;">
        ' . $shortcode_html . '
    </div>
</div>');
        
        ?>
        <!-- INÍCIO NOVO TRECHO: Instruções de implementação simplificadas -->
        <div style="background-color: #e5efff; border: 1px solid #c2d9ff; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
            <h3><?php esc_html_e('Como Implementar o Conteúdo com Delay', 'lume-player'); ?></h3>
            <p><?php esc_html_e('Para que o conteúdo apareça no tempo definido, siga estes passos:', 'lume-player'); ?></p>

            <ol>
                <li><strong><?php esc_html_e('Prepare a Seção no Elementor (ou outro construtor):', 'lume-player'); ?></strong>
                    <p><?php esc_html_e('No Elementor, crie ou selecione a seção, coluna ou widget que você deseja exibir após o delay. Vá nas configurações avançadas e, no campo "Classes CSS", adicione a classe:', 'lume-player'); ?></p>
                    <p><code>gated-content-section</code></p>
                    <p class="description"><?php esc_html_e('O CSS para ocultar e mostrar esta seção já é tratado automaticamente pelo plugin. Você só precisa adicionar a classe.', 'lume-player'); ?></p>
                </li>
                <li><strong><?php esc_html_e('Configure no Plugin (abaixo):', 'lume-player'); ?></strong>
                    <p><?php esc_html_e('Aqui no painel do plugin, no campo "Seletor CSS" (abaixo), você deve usar o mesmo seletor CSS que você usou no Elementor:', 'lume-player'); ?></p>
                    <p><code>.gated-content-section</code></p>
                    <p><?php esc_html_e('E defina o tempo em segundos para que este conteúdo seja exibido.', 'lume-player'); ?></p>
                </li>
            </ol>
            <p>
                <strong><?php esc_html_e('Exemplo de HTML para o player (onde o shortcode deve ser colocado na sua página):', 'lume-player'); ?></strong><br>
                <small><?php esc_html_e('Este é o código que você pode inserir em um widget de HTML no Elementor ou editor de texto para exibir o player deste vídeo. O conteúdo com delay será um elemento SEPARADO, identificado pela classe CSS.', 'lume-player'); ?></small>
            </p>
            <pre style="background-color: #f0f0f0; border: 1px solid #ccc; padding: 10px; border-radius: 3px; font-family: monospace; font-size: 13px;"><code id="converttize-html-code" readonly style="white-space: pre-wrap; word-break: break-all;"><?php echo $html_example_code; ?></code></pre>
            <button type="button" class="button button-primary" style="margin-top: 10px;" onclick="copyConverttizeHtmlCode()">
                <?php esc_html_e('Copiar HTML do Player', 'lume-player'); ?>
            </button>
            <span id="converttize-html-copy-status" style="margin-left: 10px; color: green; display: none;"><?php esc_html_e('Copiado!', 'lume-player'); ?></span>
        </div>

        <script>
            // A função copyConverttizeHtmlCode já está definida em admin.js e acessível globalmente
            // A função copyConverttizeCssCode e seu status não são mais necessárias aqui
        </script>
        <!-- FIM NOVO TRECHO: Instruções de implementação simplificadas -->

        <div id="lume-delayed-items-wrapper">
            <!-- Este input oculto irá conter a string JSON de todos os itens com delay -->
            <input type="hidden" name="options[delayed_items_json]" id="lume-delayed-items-data" value="<?php echo esc_attr(wp_json_encode($delayed_items)); ?>" />
            <div id="lume-delayed-items-list">
                <!-- Itens serão renderizados aqui via JavaScript -->
            </div>
            <?php if (empty($delayed_items)) : // NOVO: Condição para exibir o botão ?>
            <button type="button" class="button button-secondary" id="lume-add-delayed-item">
                <?php esc_html_e('Adicionar Nova Atração', 'lume-player'); ?>
            </button>
            <?php endif; ?>
        </div>

        <?php // INÍCIO NOVO TRECHO: Template JS simplificado para itens com delay (APENAS Seletor e Tempo) ?>
        <script type="text/html" id="tmpl-lume-delayed-item">
            <div class="lume-delayed-item-row" data-item-id="{{data.id}}">
                <input type="hidden" value="{{data.id}}" class="lume-item-id" />
                <div class="grid-2-cols-16">
                    <p>
                        <label for="lume-item-selector-{{data.id}}"><?php esc_html_e('Seletor CSS (ex: .my-section ou #my-div)', 'lume-player'); ?></label>
                        <input type="text" id="lume-item-selector-{{data.id}}" value="{{data.selector}}" class="regular-text lume-item-selector" placeholder=".gated-content-section" />
                        <p class="description"><?php esc_html_e('O elemento HTML que será ativado (tornar visível).', 'lume-player'); ?></p>
                    </p>
                    <p>
                        <label for="lume-item-time-{{data.id}}"><?php esc_html_e('Tempo (segundos)', 'lume-player'); ?></label>
                        <input type="number" id="lume-item-time-{{data.id}}" value="{{data.time}}" min="0" step="1" class="small-text lume-item-time" />
                        <p class="description"><?php esc_html_e('Tempo do vídeo em segundos para o elemento ser ativado.', 'lume-player'); ?></p>
                    </p>
                </div>
                <button type="button" class="button button-link-delete lume-remove-delayed-item"><?php esc_html_e('Remover Item', 'lume-player'); ?></button>
                <hr style="margin: 15px 0;" />
            </div>
        </script>
        <?php // FIM NOVO TRECHO: Template JS simplificado ?>
        <?php
    }

    // NOVO MÉTODO: Obtém as opções corretas (global ou específica do vídeo)
    private function get_current_options_for_rendering() {
        $default_options = $this->get_default_options();
        $option_key = $this->is_video_specific_editing ? 'lume_player_options_' . $this->current_video_id : $this->option_name;
        $current_options = get_option($option_key, []);
        return array_merge($default_options, $current_options);
    }

    // class-admin-settings.txt

    public function enqueue_admin_assets($hook_suffix) {
        if (!isset($_GET['page']) || $_GET['page'] !== $this->page_slug) {
            return;
        }

        if (!defined('YTP_PLUGIN_PATH') || !class_exists('YT_Custom_Player_Plugin')) {
            return;
        }

        $plugin_version = defined('YTP_VERSION') ? YTP_VERSION : '1.0.0';
        $plugin_url = defined('YTP_PLUGIN_URL') ? trailingslashit(YTP_PLUGIN_URL) : trailingslashit(plugin_dir_url(dirname(__FILE__)));
        wp_enqueue_style(
            'wp-color-picker'
        );
        wp_enqueue_script(
            'wp-color-picker-alpha',
            $plugin_url . 'assets/js/wp-color-picker-alpha.js',
            ['wp-color-picker', 'jquery'],
            '3.0.1',
            true
        );

        wp_enqueue_script(
            'lume-player-admin-js',
            $plugin_url . 'assets/js/admin.js',
            ['jquery', 'wp-color-picker-alpha', 'wp-util'], // 'wp-util' para templates JS
            $plugin_version,
            true
        );

        wp_enqueue_style(
            'lume-player-admin-css',
            $plugin_url . 'assets/css/style.css',
            [],
            $plugin_version
        );

        $default_options = $this->get_default_options();
        $current_options = $this->get_current_options_for_rendering(); // Use o novo método para pegar as opções
        $localized_options = array_merge($default_options, $current_options);

        // Define o ID do vídeo atual para preencher o código HTML de exemplo
        $video_id_for_html_example = $this->is_video_specific_editing ? $this->current_video_id : 'SEU_VIDEO_ID_AQUI';
        $player_div_id_example = 'ytp_player_gated_content_target' . ($this->is_video_specific_editing ? '_' . $this->current_video_id : ''); // ID do container do player. Adapta o ID se for edição por vídeo.


        // Localiza o script para passar variáveis para o admin.js
        wp_localize_script('lume-player-admin-js', 'lumePlayerAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('lume_player_nonce'), // Nonce para as chamadas AJAX do admin.js
            'options'  => $localized_options,
            'palettes' => $this->custom_color_palettes,
            'is_video_specific_editing' => $this->is_video_specific_editing, // Novo: indica ao JS se é edição por vídeo
            'current_video_id' => $this->current_video_id, // Novo: ID do vídeo atual se aplicável
            'video_id_for_html_example' => $video_id_for_html_example, // Passa o ID do vídeo para o exemplo HTML
            'player_div_id_example' => $player_div_id_example, // Passa o ID do div do player para o exemplo HTML
            'i18n'     => [
                'saving'        => __('Salvando...', 'lume-player'),
                'saveSuccess'   => __('Configurações salvas com sucesso!', 'lume-player'),
                'saveError'     => __('Erro ao salvar configurações.', 'lume-player'),
                'ajaxError'     => __('Erro de conexão.', 'lume-player'),
                'saveChanges'   => __('Salvar Configurações', 'lume-player'),
                'addItem'       => __('Adicionar Item', 'lume-player'),
                'removeItem'    => __('Remover Item', 'lume-player'),
                'idLabel' => __('ID do Item', 'lume-player'),
                'selectorLabel' => __('Seletor CSS', 'lume-player'),
                'timeLabel' => __('Tempo (s)', 'lume-player'),
                'typeLabel' => __('Tipo', 'lume-player'),
                'htmlContentLabel' => __('Conteúdo HTML', 'lume-player'),
                'displayStyleLabel' => __('Estilo Display', 'lume-player'),
                'positionCssLabel' => __('CSS Posição', 'lume-player'),
                'blurAmountLabel' => __('Blur (px)', 'lume-player'),
                'unblurSelectorLabel' => __('Seletor Unblur', 'lume-player'),
            ]
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Acesso negado.', 'lume-player'));
        }

        // Título dinâmico da página
        $page_title = $this->is_video_specific_editing 
                      ? sprintf(__('Configurações do Player para Vídeo: %s', 'lume-player'), esc_html($this->current_video_id)) 
                      : __('Configurações Globais do Converttize', 'lume-player');
        
        // NOVO: Renderiza o cabeçalho admin usando o método da instância principal do plugin
        if ($this->main_plugin_instance) { // Verifica se a instância principal foi passada
            $this->main_plugin_instance->render_admin_header($page_title); 
        }
        ?>
        <div class="wrap lume-player-admin-wrap">
            <!-- REMOVIDO: Antigo H1 da página, agora gerado pelo render_admin_header -->
            
            <?php if ($this->is_video_specific_editing) : ?>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . YT_Custom_Player_Plugin::SETTINGS_PAGE_SLUG)); ?>" class="button button-secondary">
                    <?php esc_html_e('Voltar para Configurações Globais', 'lume-player'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . YT_Custom_Player_Plugin::MAIN_MENU_SLUG)); ?>" class="button button-secondary" style="margin-left: 5px;">
                    <?php esc_html_e('Voltar para Listas de Vídeos', 'lume-player'); ?>
                </a>
            </p>
            <?php endif; ?>

            <div id="lume-player-preview-area" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ccd0d4; background-color: #f9f9f9;">
                <h2><?php esc_html_e('Preview do Player (Cores e Layout)', 'lume-player'); ?></h2>
                <p><?php esc_html_e('Este preview reflete as cores selecionadas. O layout é uma representação simplificada.', 'lume-player'); ?></p>
                <div class="admin-preview-ytp-wrapper">
                    <div class="preview-element-overlay">
                        <p style="margin:0 0 5px 0; font-size: 1.2em;">Seu vídeo já começou</p>
                        <p style="margin:0 0 5px 0; font-size: 1.2em;">Clique para ouvir</p>
                    </div>
                    <div class="preview-controls-example">
                        <button class="preview-button">Play</button>
                        <button class="preview-button secondary">Outro Botão</button>
                    </div>
                    <div class="preview-progress-bar-container">
                        <div class="preview-progress-bar"></div>
                    </div>
                </div>
            </div>

            <form method="post" action="" id="lume-player-settings-form">
                <?php 
                    do_settings_sections($this->page_slug); 
                ?>
                <input type="hidden" name="action" value="lume_player_save_options" />
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('lume_player_nonce'); ?>" />
                <?php if ($this->is_video_specific_editing) : ?>
                    <input type="hidden" name="video_id" value="<?php echo esc_attr($this->current_video_id); ?>" />
                <?php endif; ?>

                <div id="save-status" style="display:none; margin-top:15px; margin-bottom:15px;"></div> 
                <?php
                    echo '<button type="submit" class="button button-primary" id="lume-save-settings-btn">' . esc_html__('Salvar Configurações', 'lume-player') . '</button>'; 
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Helper para normalizar arrays de opções, convertendo booleanos para 0/1 para comparação consistente.
     * Isso ajuda a evitar problemas de comparação estrita (===) com valores booleanos/inteiros/strings.
     * @param mixed $value O valor a ser normalizado.
     * @return mixed O valor normalizado.
     */
    private function normalize_options_for_comparison($value) {
        if (is_bool($value)) {
            // Converte true/false para string '1'/'0' para consistência
            return $value ? '1' : '0';
        } elseif (is_numeric($value)) {
            // Converte qualquer número (inteiro ou float) para sua representação de string inteira ('1', '0', '5', etc.)
            // Isso garante que 1.0 -> '1', 1 -> '1', 0.0 -> '0', 0 -> '0'
            return (string)(int)$value; 
        } elseif (is_array($value)) {
            $normalized_array = [];
            foreach ($value as $key => $sub_value) {
                // Para arrays associativos, garanta que as chaves sejam strings e ordene-as
                $normalized_array[(string)$key] = $this->normalize_options_for_comparison($sub_value); 
            }
            // Ordene arrays associativos por chave para codificação JSON consistente
            if (!empty($normalized_array) && array_keys($normalized_array) !== range(0, count($normalized_array) - 1)) {
                ksort($normalized_array);
            }
            return $normalized_array;
        } else {
            // Garante que todos os outros tipos sejam strings
            return (string)$value; 
        }
    }

    /**
     * Handler AJAX para salvar as opções do admin.
     */
    public function ajax_save_options() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'lume-player')]);
            return;
        }
        
        // Verifica o nonce de segurança usando o nome do nonce do admin.js
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lume_player_nonce' ) ) {
            wp_send_json_error(['message' => __('Falha na verificação de segurança (nonce).', 'lume-player')]);
            return;
        }

        // Pega as opções enviadas pelo JavaScript (contidas na chave 'options')
        $input_options = $_POST['options'] ?? [];
        if (!is_array($input_options)) {
            wp_send_json_error(['message' => __('Dados inválidos ou ausentes.', 'lume-player')]);
            return;
        }

        // Determina a chave da opção a ser salva
        $option_key = $this->option_name; // Default para opções globais
        $current_video_id_for_save = null;

        if (isset($_POST['video_id']) && !empty($_POST['video_id'])) {
            $current_video_id_for_save = sanitize_text_field($_POST['video_id']);
            $option_key = 'lume_player_options_' . $current_video_id_for_save; // Chave para opções por vídeo
        }

        // Sanitiza as opções usando o método existente
        $sanitized_input_options = $this->sanitize_options($input_options);
        
        // --- NOVA LÓGICA DE COMPARAÇÃO ANTES DE TENTAR SALVAR ---
        $current_db_options = get_option($option_key);
        // Garante que $current_db_options seja sempre um array para comparação.
        // Se get_option retornar false (opção não existe), trate como um array vazio.
        if ($current_db_options === false) {
            $current_db_options = [];
        }

        // Agora, antes de normalizar, mescle as opções atuais do DB com as opções padrão.
        // Isso garante que $current_db_options_for_comparison tenha a estrutura completa,
        // mesmo se for uma nova opção ou se estiver parcialmente salva.
        $default_options = $this->get_default_options();
        $current_db_options_for_comparison = array_merge($default_options, $current_db_options);

        $normalized_db_options = $this->normalize_options_for_comparison($current_db_options_for_comparison);
        $normalized_sanitized_input = $this->normalize_options_for_comparison($sanitized_input_options);
        // Compara as representações JSON normalizadas
        if (json_encode($normalized_db_options) === json_encode($normalized_sanitized_input)) {
            // Os valores são semanticamente idênticos, então nenhuma alteração real foi feita
            wp_send_json_success([
                'message' => __('Nenhuma alteração detectada.', 'lume-player'),
                'options' => $sanitized_input_options // Retorna as opções sanitizadas
            ]);
            return; // Encerra a execução
        }

        // Se chegamos aqui, há uma alteração semântica, então tentamos atualizar
        $updated = update_option($option_key, $sanitized_input_options);

        if ($updated) {
            // A opção foi realmente atualizada ou adicionada
            wp_send_json_success([
                'message' => __('Configurações salvas com sucesso!', 'lume-player'),
                'options' => $sanitized_input_options // Retorna as opções sanitizadas
            ]);
        } else {
            // update_option retornou false, mas já verificamos que havia uma mudança semântica.
            // Isso geralmente indica um problema real no banco de dados ou ambiente.
            error_log("Erro ao salvar as configurações para {$option_key}. Input sanitizado: " . json_encode($sanitized_input_options) . ". Valor atual no DB: ". json_encode($current_db_options));
            wp_send_json_error([
                'message' => __('Erro ao salvar as configurações no banco de dados. Tente novamente.', 'lume-player'),
                'options' => $current_db_options // Opcional: envia as opções atuais do DB em caso de erro
            ]);
        }
    }
}
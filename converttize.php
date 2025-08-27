<?php

/**
 * Plugin Name: Converttize
 * Description: Player minimalista com UX fluida, botões próprios e desbloqueio inteligente.
 * Version: 1.0.5
 * Author: IGL Solutions
 * Text Domain: converttize 
 */

defined('ABSPATH') || exit;

if (!defined('YTP_PLUGIN_URL'))
    define('YTP_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('YTP_PLUGIN_PATH'))
    define('YTP_PLUGIN_PATH', plugin_dir_path(__FILE__));
if (!defined('YTP_VERSION'))
    define('YTP_VERSION', '1.0.3');

// Função utilitária para extrair ID do YouTube
function converttize_extract_youtube_id($input) {
    $input = trim($input);
    // CORRIGIDO: Nova regex mais robusta para extrair IDs de várias URLs do YouTube.
    // Inclui youtube.com/watch, youtu.be, embed, shorts, etc.
    // O escaping de '\' é feito para PHP, que o converte para '\' na regex.
    $pattern = '%^(?:https?://)?(?:www\.)?(?:m\.)?(?:youtu\.be/|youtube\.com/(?:embed/|v/|watch\?v=|watch\?.+&v=|shorts/))([a-zA-Z0-9_-]{11})(?:(?:\?|&|#).*)?$%i';
    
    if (preg_match($pattern, $input, $matches)) {
        return $matches[1];
    }
    // Se já for apenas o ID (ex: "C7OQHIpDlvA")
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
        return $input;
    }
    return ''; // Retorna vazio se não for um ID ou URL válido
}

// HOOK DE ATIVAÇÃO PARA TRIAL
register_activation_hook(__FILE__, 'converttize_plugin_activation');

function converttize_plugin_activation() {
    // Força verificação de trial na ativação
    update_option('converttize_force_trial_check', true);
    
    // Log da ativação
    error_log('🎯 CONVERTTIZE: Plugin ativado - Trial será verificado');
}

// Inclusão de classes:
require_once YTP_PLUGIN_PATH . 'includes/class-license-manager.php';
require_once YTP_PLUGIN_PATH . 'includes/class-analytics.php'; // Inclui class-analytics.php primeiro
require_once YTP_PLUGIN_PATH . 'includes/class-licensing.php'; // Inclui class-licensing.php
require_once YTP_PLUGIN_PATH . 'includes/class-admin-settings.php';
require_once YTP_PLUGIN_PATH . 'includes/class-admin-analytics.php'; // Inclui class-admin-analytics.php
require_once YTP_PLUGIN_PATH . 'includes/class-player-render.php';


class Converttize {
    private static $assets_registered = false;
    private static $instance = null;
    private $options; // Armazenará as opções do plugin
    private $security_secret = 'CONV_SEC_2024_XYZ789';
    private $validation_server;
    private $cache_duration = 60; 

    // Propriedades para armazenar instâncias das classes
    private $license_manager_instance;
    private $admin_settings_instance; 
    private $admin_analytics_instance;

    // NOVO SLUG PRINCIPAL PARA O MENU E PARA A PÁGINA DE LISTAS
    const MAIN_MENU_SLUG = 'converttize-videos'; 

    const SETTINGS_PAGE_SLUG = 'lume-player-settings'; // Define a constante para o slug da página de configurações
    const ANALYTICS_PAGE_SLUG = 'lumeplayer-analytics'; // NOVO: Slug para Analytics
    const LICENSE_PAGE_SLUG = 'converttize-license';   // NOVO: Slug para Licença


    // MÉTODO PARA INJETAR CSS CRÍTICO NO <head>
    public function inject_critical_delay_css() { 
        ?>
        <style type="text/css">
            /* CSS CRÍTICO PARA ESCONDER SEÇÕES DE CONTEÚDO COM DELAY */
            /* Esta regra deve ser aplicada a TODOS os elementos que você quer controlar com delay */
            .gated-content-section {
                display: none !important; /* FORÇA esconder a seção inteira */
                opacity: 0;    /* Para o efeito de fade-in */
                height: 0;     /* Remove a altura da seção inicialmente */
                overflow: hidden; /* Garante que nada transborde */
                transition: opacity 1s ease-in-out, height 0.5s ease-in-out; /* Transições suaves */
                pointer-events: none; /* Impede cliques e interações */
            }
            /* Classe adicionada pelo JavaScript para mostrar a seção */
            .gated-content-section.is-visible {
                display: block !important; /* FORÇA a seção a aparecer como bloco */
                opacity: 1;
                height: auto; /* Restaura a altura */
                overflow: visible; /* Restaura o overflow */
                pointer-events: auto; /* Permite cliques e interações */
            }
        </style>
        <?php
    }
    
    public function __construct() {
        self::$instance = $this;

        // ENGATAR O MÉTODO DE INJEÇÃO DE CSS NO HOOK wp_head
        add_action('wp_head', [$this, 'inject_critical_delay_css']);

        // 🔧 CARREGAR URL DO CONFIG.PHP
        $config = include plugin_dir_path(__FILE__) . 'config.php';
        $this->validation_server = $this->is_local_environment() 
            ? $config['license']['api_url_local'] . 'validate.php'
            : $config['license']['api_url_production'] . 'validate.php';

        error_log('🔧 CONVERTTIZE: URL do servidor: ' . $this->validation_server);

        // Instancia Admin Settings e armazena.
        // É importante que 'ytp_admin_settings_instance' seja global
        // para que a classe YT_Custom_Player_Render possa acessá-la.
        global $ytp_admin_settings_instance;
        $this->admin_settings_instance = new YT_Player_Admin_Settings($this); 
        $ytp_admin_settings_instance = $this->admin_settings_instance;

        // Obtém as opções gerais do plugin, usando o método da instância de Admin Settings
        $this->options = get_option('lume_player_options', $this->admin_settings_instance->get_default_options());

        // Instancia Admin Analytics
        $this->admin_analytics_instance = new LumePlayer_Admin_Analytics($this);

        // Instancia License Manager e armazena.
        // Esta é a principal correção para o erro de 'invalid callback'.
        global $converttize_license_manager; // Mantém a global para compatibilidade com código que possa esperá-la
        $this->license_manager_instance = new YT_License_Manager($this);
        $converttize_license_manager = $this->license_manager_instance; // Atribui a instância à global

        //    VERIFICAR SE PRECISA FORÇAR TRIAL
        add_action('init', [$this, 'check_force_trial']);

        // Carrega e armazena instâncias de outras classes
        new YT_Custom_Player_Render(); 

        add_action('wp_enqueue_scripts', [$this, 'register_and_enqueue_assets']);
        add_action('admin_notices', [$this, 'license_notice']);

        // ✅ AJAX endpoints
        add_action('wp_ajax_converttize_check_status', [$this, 'ajax_check_license_status']);
        add_action('wp_ajax_nopriv_converttize_check_status', [$this, 'ajax_check_license_status']);

        // ✅ Limpar cache quando licença muda
        add_action('update_option_converttize_license_key', [$this, 'clear_license_cache']);
        add_action('delete_option_converttize_license_key', [$this, 'clear_license_cache']);

        // REGISTRAR O NOVO MENU E SUBMENUS
        add_action('admin_menu', [$this, 'add_plugin_admin_menus']);
    }

    public function render_branded_header_shortcode() {
        // CORREÇÃO: Usar a instância de admin_settings para obter as opções padrão
        $options = get_option('lume_player_options', $this->admin_settings_instance->get_default_options());
        
        $logo_url = esc_url($options['plugin_header_logo_url'] ?? '');
        $header_title = esc_html($options['plugin_header_title'] ?? '');
        $header_link = esc_url($options['plugin_header_link'] ?? '');

        ob_start();
        ?>
        <div class="converttize-branded-header">
            <a href="<?php echo $header_link; ?>" target="_blank">
                <?php if ($logo_url) : ?>
                    <img src="<?php echo $logo_url; ?>" alt="<?php echo $header_title; ?>" class="converttize-branding-logo">
                <?php endif; ?>
                <?php if ($header_title) : ?>
                    <span class="converttize-branding-title"><?php echo $header_title; ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
    // FIM NOVO TRECHO

    /**
     * Renderiza o cabeçalho personalizado das páginas admin do Converttize.
     * Agora utiliza as opções de branding do usuário.
     * @param string $page_title O título específico da página.
     */
    public function render_admin_header($page_title) {
        // Obtém as opções gerais (globais) do plugin.
        // Acesso direto a $this->options já carregado no construtor.
        $options = $this->options; 
        
        $logo_url = esc_url($options['plugin_header_logo_url'] ?? '');
        $header_title = esc_html($options['plugin_header_title'] ?? '');
        $header_link = esc_url($options['plugin_header_link'] ?? admin_url('admin.php?page=' . self::MAIN_MENU_SLUG)); // Link padrão para a página principal do plugin

        // Se o usuário não configurou seu branding, volta para o logo e nome do plugin Converttize
        if (empty($logo_url) && empty($header_title)) {
            $logo_url = YTP_PLUGIN_URL . 'assets/images/20x20 - branco.png'; // Logo padrão do Converttize
            $header_title = __('Converttize', 'converttize'); // Nome padrão do Converttize
            $header_link = admin_url('admin.php?page=' . self::MAIN_MENU_SLUG); // Link padrão do Converttize
        }
        
        $main_plugin_page_url = admin_url('admin.php?page=' . self::MAIN_MENU_SLUG);
        ?>
        <div class="converttize-admin-header">
            <a href="<?php echo $header_link ? $header_link : esc_url($main_plugin_page_url); ?>" class="converttize-logo-link" <?php echo $header_link ? 'target="_blank"' : ''; ?>>
                <?php if ($logo_url) : ?>
                    <img src="<?php echo $logo_url; ?>" alt="<?php echo $header_title; ?>" class="converttize-logo">
                <?php endif; ?>
                <?php if ($header_title) : ?>
                    <span class="converttize-brand-name"><?php echo $header_title; ?></span>
                <?php endif; ?>
            </a>
            <h1 class="converttize-page-title"><?php echo esc_html($page_title); ?></h1>
        </div>
        <?php
        // Este hook adiciona os estilos CSS para o cabeçalho.
        add_action('admin_head', [$this, 'enqueue_admin_header_styles']);
    }

    /**
     * Adiciona estilos CSS para o cabeçalho admin.
     * (Este método é chamado por render_admin_header)
     */
    public function enqueue_admin_header_styles() {
        ?>
        <style type="text/css">
            .converttize-admin-header {
                display: flex;
                align-items: center;
                margin-top: 20px;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 1px solid #ddd;
                position: relative; /* Para posicionar o título corretamente */
            }
            .converttize-logo-link {
                display: flex;
                align-items: center;
                text-decoration: none;
                color: #000; /* Cor padrão para links */
                margin-right: 20px; /* Espaço entre a logo e o título */
            }
            .converttize-logo {
                height: 32px; /* Ajuste a altura da sua logo aqui */
                width: auto;
                margin-right: 8px; /* Espaço entre a imagem e o nome da marca */
            }
            .converttize-brand-name {
                font-size: 1.5em; /* Tamanho do nome da marca */
                font-weight: bold;
                color: #333; /* Cor do nome da marca */
                line-height: 1; /* Alinhamento de texto */
            }
            .converttize-page-title {
                margin: 0;
                padding: 0;
                font-size: 1.8em; /* Tamanho do título da página */
                font-weight: 600;
                color: #23282d; /* Cor do título da página */
                position: absolute; /* Posiciona o título em relação ao header */
                left: calc(32px + 8px + 1.5em); /* Largura da logo + margem + largura aproximada do nome da marca */
                white-space: nowrap; /* Impede que o título quebre linha antes da hora */
            }
            /* Ajustes para telas menores, se necessário */
            @media (max-width: 782px) {
                .converttize-admin-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .converttize-page-title {
                    position: static;
                    margin-top: 10px;
                    font-size: 1.5em;
                }
                .converttize-logo-link {
                    margin-right: 0;
                }
            }
        </style>
        <?php
    }


    // MÉTODO PARA REGISTRAR TODOS OS MENUS
    public function add_plugin_admin_menus() {
        // Menu principal de nível superior, agora será a página de listas
        add_menu_page(
            __('Converttize', 'converttize'),
            __('Converttize', 'converttize'),
            'manage_options',
            self::MAIN_MENU_SLUG,
            [$this, 'render_video_lists_page'],
            plugin_dir_url(__FILE__) . 'assets/images/20x20 - branco.png',
            6
        );

        // NENHUM 'add_submenu_page' para 'Listas' aqui, pois é a página principal.

        // Submenu para "Analytics" 
        add_submenu_page(
            self::MAIN_MENU_SLUG,                      // PARENT_SLUG agora é o NOVO SLUG
            __('Analytics de Retenção', 'converttize'),
            __('Analytics', 'converttize'),
            'manage_options',
            self::ANALYTICS_PAGE_SLUG,                    // Mantém o slug antigo
            [$this->admin_analytics_instance, 'lumeplayer_analytics_page'] // Callback da instância
        );

        // Submenu para "Licença" 
        add_submenu_page(
            self::MAIN_MENU_SLUG,                      // PARENT_SLUG agora é o NOVO SLUG
            __('Gerenciamento de Licença', 'converttize'),
            __('Licença', 'converttize'),
            'manage_options',
            self::LICENSE_PAGE_SLUG,
            [$this->license_manager_instance, 'license_page'] // Callback da instância
        );

        // Submenu para "Editor" 
        add_submenu_page(
            self::MAIN_MENU_SLUG,                      // PARENT_SLUG agora é o NOVO SLUG
            __('Configurações do Player', 'converttize'),
            __('Estilo Padrão', 'converttize'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,                    // Mantém o slug antigo
            [$this->admin_settings_instance, 'render_settings_page'] // Callback da instância
        );
    }

    // MÉTODO: Callback para a página "Listas" (agora é a página principal)
    public function render_video_lists_page() {
        // NOVO: Chama o cabeçalho admin 
        $this->render_admin_header(__('Listas de Vídeos', 'converttize'));

        global $wpdb;
        $table = $wpdb->prefix . 'lumeplayer_analytics'; // Tabela de analytics

        // Busca IDs de vídeo únicos que possuem dados de analytics
        $videos_with_data = $wpdb->get_results("
            SELECT DISTINCT video_id
            FROM $table
            ORDER BY video_id ASC
        ");
        ?>
        <div class="wrap">
            <!-- REMOVIDO: Antigo H1 da página, agora gerado pelo render_admin_header -->
            <p><?php esc_html_e('Aqui você pode visualizar uma lista de todos os IDs de vídeo para os quais há dados de analytics registrados e acessar seus respectivos gráficos de retenção ou editar as configurações do player para o vídeo específico.', 'converttize'); ?></p>

            <!-- NOVO TRECHO: Formulário para Adicionar Novo Vídeo -->
            <div style="background-color: #f9f9f9; border: 1px solid #e0e0e0; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                <h3><?php esc_html_e('Adicionar Novo Vídeo Manualmente', 'converttize'); ?></h3>
                <form id="converttize-add-video-form" method="post" action="">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="video_title"><?php esc_html_e('Título do Vídeo', 'converttize'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="video_title" id="video_title" class="regular-text" value="" placeholder="<?php esc_attr_e('Ex: Meu Primeiro Vídeo', 'converttize'); ?>" required />
                                    <p class="description"><?php esc_html_e('Um título para identificar o vídeo na lista.', 'converttize'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="video_url"><?php esc_html_e('ID ou Link do YouTube', 'converttize'); ?></label>
                                </th>
                                <td>
                                    <!-- CORRIGIDO: placeholder com exemplos de URL de vídeo simplificados e corretos -->
                                    <input type="text" name="video_url" id="video_url" class="regular-text" value="" placeholder="<?php esc_attr_e('Ex: OdlSHPGg7Ag ou <div className="video-container mb-3"><iframe width="100%" height="315" src="https://www.youtube.com/embed/17548" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>', 'converttize'); ?>" required />
                                    <p class="description"><?php esc_html_e('Insira o ID do vídeo do YouTube (ex: C7OQHIpDlvA) ou o link completo do vídeo.', 'converttize'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <input type="hidden" name="action" value="converttize_add_new_video">
                        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('converttize_add_new_video_nonce'); ?>">
                        <button type="submit" name="submit" id="submit-add-video" class="button button-primary">
                            <?php esc_html_e('Adicionar Vídeo', 'converttize'); ?>
                        </button>
                    </p>
                    <div id="add-video-status" style="margin-top:10px; display:none;"></div>
                </form>
            </div>
            <!-- FIM NOVO TRECHO: Formulário para Adicionar Novo Vídeo -->

            <?php if (!empty($videos_with_data)) : ?>
            <table class="wp-list-table widefat fixed striped" id="converttize-video-list-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-video-id"><?php esc_html_e('ID do Vídeo', 'converttize'); ?></th>
                        <th scope="col" class="manage-column column-video-title"><?php esc_html_e('Título do Vídeo', 'converttize'); ?></th>
                        <th scope="col" class="manage-column column-actions"><?php esc_html_e('Ações', 'converttize'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($videos_with_data as $video) : ?>
                    <tr data-video-id="<?php echo esc_attr($video->video_id); ?>">
                        <td class="video-id-column"><code><?php echo esc_html($video->video_id); ?></code></td>
                        <td class="video-title-column">
                            <?php 
                                // MODIFICADO: Busca o título personalizado salvo, ou exibe 'Não disponível'
                                $custom_title = get_option('converttize_video_title_' . $video->video_id);
                                $display_title = $custom_title ? $custom_title : __('Título não disponível', 'converttize');
                                // Adicionada classe 'editable-title' e o span para o JS interagir
                                echo '<span class="editable-title">' . esc_html($display_title) . '</span>';
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::ANALYTICS_PAGE_SLUG . '&video_id=' . $video->video_id)); ?>" class="button button-primary">
                                <?php esc_html_e('Ver Analytics', 'converttize'); ?>
                            </a>
                            <?php // NOVO BOTÃO DE EDIÇÃO POR VÍDEO ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SETTINGS_PAGE_SLUG . '&video_id=' . $video->video_id)); ?>" class="button button-secondary" style="margin-left: 5px;">
                                <?php esc_html_e('Editar Configurações', 'converttize'); ?>
                            </a>
                            <!-- NOVO BOTÃO DE EXCLUSÃO - Lixeira Vermelha -->
                            <button type="button" class="button button-delete button-icon"
                                    data-video-id="<?php echo esc_attr($video->video_id); ?>"
                                    style="background-color: #dc3232; color: #fff; border-color: #dc3232; margin-left: 5px;"
                                    title="<?php esc_attr_e('Excluir Vídeo', 'converttize'); ?>"
                                    onclick="converttizeDeleteVideo('<?php echo esc_js($video->video_id); ?>', this)">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e('', 'converttize'); // Adicionado o texto "Excluir" ?>
                            </button>
                            <!-- FIM NOVO TRECHO -->
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('Nenhum dado de analytics de vídeo encontrado ainda. Comece adicionando um vídeo acima ou certificando-se de que seus vídeos estão sendo assistidos com o player Converttize para que os dados sejam coletados.', 'converttize'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php // Script JavaScript para a funcionalidade de exclusão (manter como está) ?>
        <script type="text/javascript">
            // Função para lidar com a exclusão de vídeo via AJAX
            function converttizeDeleteVideo(videoId, buttonElement) {
                // 1. Confirmação do Usuário
                if ( ! confirm('<?php echo esc_js(__('Tem certeza que deseja excluir o vídeo "' . 'ID: ' . '%s' . '" e todos os seus dados de analytics e configurações específicas?', 'converttize')); ?>'.replace('%s', videoId)) ) {
                    return; // Aborta se o usuário cancelar
                }

                // Desabilitar o botão e mostrar um feedback visual
                buttonElement.disabled = true;
                const originalButtonText = buttonElement.innerHTML;
                buttonElement.innerHTML = '<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Excluir...', 'converttize')); ?>';

                // Criar o nonce para a requisição AJAX
                const nonce = '<?php echo wp_create_nonce('converttize_delete_video_nonce'); ?>';

                // Preparar os dados para a requisição AJAX
                const data = new URLSearchParams();
                data.append('action', 'converttize_delete_video'); // Ação AJAX que o WordPress irá mapear
                data.append('video_id', videoId);
                data.append('nonce', nonce);

                // Enviar a requisição AJAX usando a API Fetch
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: data,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (result.success) {
                        alert(result.data.message); // Exibe a mensagem de sucesso
                        // Remover a linha da tabela (o elemento <tr> pai do botão)
                        const row = buttonElement.closest('tr');
                        if (row) {
                            row.remove();
                        }
                    } else {
                        alert('<?php echo esc_js(__('Erro ao excluir o vídeo:', 'converttize')); ?> ' + (result.data.message || ''));
                        // Restaurar o botão em caso de erro
                        buttonElement.disabled = false;
                        buttonElement.innerHTML = originalButtonText;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('<?php echo esc_js(__('Ocorreu um erro inesperado ao tentar excluir o vídeo. Por favor, verifique o console do navegador para mais detalhes.', 'converttize')); ?>');
                    // Restaurar o botão em caso de erro de rede ou inesperado
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = originalButtonText;
                });
            }
        </script>
        <!-- NOVO TRECHO: Script JavaScript para o formulário Adicionar Novo Vídeo -->
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#converttize-add-video-form').on('submit', function(e) {
                    e.preventDefault();

                    const form = $(this);
                    const submitButton = form.find('#submit-add-video');
                    const statusDiv = $('#add-video-status');
                    const originalButtonText = submitButton.html();

                    submitButton.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php esc_html_e('Adicionando...', 'converttize'); ?>');
                    statusDiv.removeClass('notice notice-success notice-error').hide().html('');

                    $.ajax({
                        url: ajaxurl, // Variável global do WordPress para a URL AJAX
                        type: 'POST',
                        data: form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                statusDiv.addClass('notice notice-success is-dismissible').html('<p>' + response.data.message + '</p>').show();
                                form[0].reset(); // Limpa o formulário
                                // Recarrega a tabela de vídeos (ou a página inteira para simplicidade)
                                // Para uma atualização sem recarregar a página, você precisaria de um AJAX para buscar a lista e renderizá-la novamente.
                                location.reload(); 
                            } else {
                                statusDiv.addClass('notice notice-error is-dismissible').html('<p>' + response.data.message + '</p>').show();
                            }
                        },
                        error: function() {
                            statusDiv.addClass('notice notice-error is-dismissible').html('<p><?php esc_html_e('Ocorreu um erro ao processar sua requisição.', 'converttize'); ?></p>').show();
                        },
                        complete: function() {
                            submitButton.prop('disabled', false).html(originalButtonText);
                            // Auto-desaparecer a mensagem de status
                            setTimeout(function() {
                                statusDiv.fadeOut(500);
                            }, 5000);
                        }
                    });
                });
            });
        </script>
        <!-- FIM NOVO TRECHO: Script JavaScript para o formulário Adicionar Novo Vídeo -->

        <!-- NOVO TRECHO: Script JavaScript para edição de título inline -->
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Delegar o evento de clique para o elemento pai para lidar com elementos adicionados dinamicamente
                $('#converttize-video-list-table').on('click', '.editable-title', function() {
                    const $span = $(this);
                    const currentTitle = $span.text().trim();
                    const $tr = $span.closest('tr');
                    const videoId = $tr.data('video-id');

                    // Criar o campo de input
                    const $input = $('<input type="text" class="editable-title-input" />')
                                   .val(currentTitle)
                                   .width($span.width() + 20) // Ajusta a largura
                                   .css('font-weight', 'normal'); // Remove negrito do input

                    // Substituir o span pelo input
                    $span.hide().after($input);
                    $input.focus();

                    let saving = false; // Flag para evitar múltiplas requisições

                    // Função para salvar o título
                    const saveTitle = function() {
                        if (saving) return; // Evita salvar múltiplas vezes
                        saving = true;

                        const newTitle = $input.val().trim();
                        if (newTitle === currentTitle) { // Nenhuma mudança, apenas reverte
                            $input.remove();
                            $span.show();
                            saving = false;
                            return;
                        }

                        // Mostrar spinner
                        const $spinner = $('<span class="dashicons dashicons-update spin"></span>');
                        $input.prop('disabled', true).after($spinner);

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'converttize_update_video_title',
                                video_id: videoId,
                                new_title: newTitle,
                                nonce: '<?php echo wp_create_nonce('converttize_update_video_title_nonce'); ?>' // Nonce para este AJAX
                            },
                            success: function(response) {
                                if (response.success) {
                                    $span.text(newTitle).show();
                                    // Opcional: Mostrar uma mensagem de sucesso temporária
                                    $input.remove();
                                    $spinner.remove();
                                    // Se a resposta incluir o título sanitizado, use-o
                                    // $span.text(response.data.new_title).show(); 
                                } else {
                                    alert('<?php echo esc_js(__('Erro ao atualizar título:', 'converttize')); ?> ' + (response.data.message || ''));
                                    $span.text(currentTitle).show(); // Reverte para o título original
                                    $input.remove();
                                    $spinner.remove();
                                }
                            },
                            error: function() {
                                alert('<?php echo esc_js(__('Erro de conexão ao atualizar o título.', 'converttize')); ?>');
                                $span.text(currentTitle).show(); // Reverte para o título original
                                $input.remove();
                                $spinner.remove();
                            },
                            complete: function() {
                                saving = false;
                            }
                        });
                    };

                    // Salvar ao perder o foco
                    $input.on('blur', saveTitle);

                    // Salvar ao pressionar Enter
                    $input.on('keypress', function(e) {
                        if (e.which === 13) { // Tecla Enter
                            e.preventDefault();
                            $input.blur(); // Dispara o evento blur para salvar
                        }
                    });
                });
            });
        </script>
        <!-- FIM NOVO TRECHO: Script JavaScript para edição de título inline -->

        <?php
    }

    // 🔧 MÉTODO PARA DETECTAR AMBIENTE (MANTENHA COMO ESTÁ)
    private function is_local_environment() {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // 🔧 CARREGAR CONFIG AQUI TAMBÉM
        $config = include plugin_dir_path(__FILE__) . 'config.php';
        $local_hosts = $config['environment']['local_hosts'];
        
        return in_array($host, $local_hosts);
    }

    // 🆕 NOVO MÉTODO: Verificar se precisa forçar trial (MANTENHA COMO ESTÁ)
    public function check_force_trial() {
        if (get_option('converttize_force_trial_check')) {
            delete_option('converttize_force_trial_check');
            
            error_log('   CONVERTTIZE: Forçando verificação de trial após ativação...');
            
            // Limpar qualquer cache existente
            $this->clear_license_cache();
            
            // Forçar verificação de trial
            global $converttize_license_manager;
            if ($converttize_license_manager) {
                $status = $converttize_license_manager->force_trial_check();
                error_log('   CONVERTTIZE: Trial forçado após ativação - Status: ' . $status);
            }
        }
    }

    // ✅ NOVO: Método para pegar instância (MANTENHA COMO ESTÁ)
    public static function get_instance() {
        return self::$instance;
    }

    // ✅ NOVO: Endpoint AJAX para verificação de status (MANTENHA COMO ESTÁ)
    public function ajax_check_license_status() {
        // Verificação de segurança
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'converttize_security')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        // Força nova validação (bypass cache)
        $this->clear_license_cache();
        $status = $this->validate_with_remote_server();
        $hash = $this->generate_security_hash($status);

        error_log("   AJAX Check Status - Status: $status, Hash: $hash");

        wp_send_json_success([
            'license_status' => $status,
            'security_hash' => $hash,
            'timestamp' => time()
        ]);
    }

    // ✅ NOVO: Método público para validação (para usar em analytics) (MANTENHA COMO ESTÁ)
    public function get_license_status() {
        return $this->validate_with_remote_server();
    }

    // ✅ NOVO: Limpar cache quando licença muda (MANTENHA COMO ESTÁ)
    public function clear_license_cache() {
        $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cache_time_key = $cache_key . '_time';
        
        delete_transient($cache_key);
        delete_transient($cache_time_key);
        
        error_log("   Cache de licença limpo");
    }

    // 🔐 Gerador de hash de segurança (MANTENHA COMO ESTÁ)
   private function generate_security_hash($status) {
    try {
        // ✅ Usar o status atual (não hardcoded 'active')
        $data = $status . $this->security_secret . date('Y-m-d');
        $hash = substr(hash('sha256', $data), 0, 16);
        
        error_log("🔐 Hash gerado para status '$status': $hash");
        
        return $hash;
    } catch (Exception $e) {
        error_log("❌ ERRO ao gerar hash: " . $e->getMessage());
        return 'fallback_hash_123';
    }
}

    // 🔐 Cache com verificação de idade (MANTENHA COMO ESTÁ)
    private function get_cached_license_status() {
        $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $cache_time_key = $cache_key . '_time';
            $cache_time = get_transient($cache_time_key);
            
            if ($cache_time && (time() - $cache_time) < $this->cache_duration) {
                error_log("✅ Usando cache: $cached");
                return $cached;
            } else {
                // Cache expirado, remove
                delete_transient($cache_key);
                delete_transient($cache_time_key);
                error_log("🔄 Cache expirado, removendo");
            }
        }
        
        return null;
    }

    private function set_cached_license_status($status) {
        $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cache_time_key = $cache_key . '_time';
        
        set_transient($cache_key, $status, $this->cache_duration);
        set_transient($cache_time_key, time(), $this->cache_duration);
        
        error_log("💾 Cache definido: $status");
    }

    //    Validação com servidor remoto (CORRIGIDA - MANTENHA COMO ESTÁ)
    private function validate_with_remote_server() {
        error_log(" === INICIANDO VALIDAÇÃO REMOTA ===");
        error_log("🔍 Servidor: " . $this->validation_server);

        // ✅ Se está em admin e acabou de alterar licença, força validação
        if (is_admin() && isset($_POST['converttize_license_key'])) {
            $this->clear_license_cache();
        }

        // Verifica cache primeiro
        $cached_status = $this->get_cached_license_status();
        if ($cached_status !== null) {
            return $cached_status;
        }

        // Pega dados locais
        $license_key = get_option('converttize_license_key', '');
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        error_log("🔍 License Key: " . (!empty($license_key) ? substr($license_key, 0, 10) . "..." : "VAZIO (TRIAL)"));
        error_log("🔍 Domain: $domain");
        
        // Gera hash de segurança para requisição
        $timestamp = time();
        
        // Dados para envio
        $post_data = [
            'domain' => $domain,
            'plugin_version' => YTP_VERSION,
            'timestamp' => $timestamp
        ];
        
        // Se tem licença, adiciona dados da licença
        if (!empty($license_key)) {
            $post_data['license_key'] = $license_key;
            $post_data['request_hash'] = hash('sha256', $license_key . $domain . $timestamp . $this->security_secret);
        } else {
            // Para trial, usa hash simples
            $post_data['request_hash'] = hash('sha256', $domain . $timestamp . $this->security_secret);
        }
        
        error_log("🔍 Fazendo requisição para: " . $this->validation_server);
        error_log("   Dados enviados: " . json_encode($post_data));
        
        // Faz requisição
        $response = wp_remote_post($this->validation_server, [
            'timeout' => 15,
            'body' => $post_data,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Plugin-Version' => YTP_VERSION,
                'User-Agent' => 'Converttize-Plugin/' . YTP_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log("❌ Erro na requisição: " . $error_msg);
            
            // Tenta validar o license key localmente se a API falhar
            // (Assumindo que essa lógica exista na classe License Manager ou similar)
            global $converttize_license_manager;
            if ($converttize_license_manager && method_exists($converttize_license_manager, 'get_license_status_local_fallback')) {
                $fallback_status = $converttize_license_manager->get_license_status_local_fallback();
            } else {
                $fallback_status = 'inactive'; // Default para inativo se não houver fallback
            }

            error_log("🔄 Usando fallback local: $fallback_status");
            
            // Cache menor para erro (30 segundos)
            $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
            set_transient($cache_key, $fallback_status, 30);
            
            return $fallback_status;
        }
        
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log("   HTTP Code: $http_code");
        error_log("🔍 Resposta do servidor: " . $body);
        
        if ($http_code !== 200 || !$data || !$data['success']) {
            error_log("❌ Resposta inválida do servidor");
            
            // Fallback para validação local
            global $converttize_license_manager;
            if ($converttize_license_manager && method_exists($converttize_license_manager, 'get_license_status_local_fallback')) {
                $fallback_status = $converttize_license_manager->get_license_status_local_fallback();
            } else {
                $fallback_status = 'inactive'; // Default para inativo se não houver fallback
            }
            
            error_log("🔄 Usando fallback local: $fallback_status");
            
            // Cache menor para erro (30 segundos)
            $cache_key = 'converttize_license_status_' . md5($_SERVER['HTTP_HOST'] ?? 'localhost');
            set_transient($cache_key, $fallback_status, 30);
            
            return $fallback_status;
        }
        
        $status = $data['data']['status'] ?? 'inactive';
        
        // Salva informações adicionais da licença e trial
        update_option('converttize_license_status', $status); // Atualiza o status principal no DB
        update_option('converttize_license_expires_at', $data['data']['expires_at'] ?? ''); // Para licenças pagas
        update_option('converttize_license_reason', $data['data']['reason'] ?? ''); // Razão da inatividade/expiração

        if ($status === 'trial') {
            update_option('converttize_trial_expires_at', $data['data']['trial_expires_at'] ?? '');
            update_option('converttize_trial_days_remaining', $data['data']['trial_days_remaining'] ?? 0);
        } else {
            // Limpa dados de trial se não estiver em trial
            delete_option('converttize_trial_expires_at');
            delete_option('converttize_trial_days_remaining');
        }
        
        error_log("✅ Status recebido do servidor: $status");
        
        // Valida hash de resposta se fornecido
        if (isset($data['response_hash'])) {
            $response_timestamp = strtotime($data['timestamp']);
            $expected_response_hash = substr(hash('sha256', $status . $response_timestamp . $this->security_secret), 0, 16);
            
            if (!hash_equals($expected_response_hash, $data['response_hash'])) {
                error_log("❌ Hash de resposta inválido");
                $this->set_cached_license_status('inactive'); // Marca como inativo por falha de segurança
                update_option('converttize_license_status', 'inactive');
                update_option('converttize_license_reason', 'security_hash_mismatch');
                return 'inactive';
            }
            
            error_log("✅ Hash de resposta validado");
        }
        
        // Cache do resultado
        $this->set_cached_license_status($status);
        return $status;
    }

    public function register_and_enqueue_assets() {
        if (self::$assets_registered) {
            return;
        }

        error_log("🚀 === REGISTRANDO ASSETS ===");

        //  Usar validação remota com fallback
        $license_status = $this->validate_with_remote_server();
        
        error_log("   Status final para assets: $license_status");
        
        //  Gera hash de segurança para validação no frontend
        $security_hash = $this->generate_security_hash($license_status);

        //    Versão com timestamp para evitar cache
        $version_hash = YTP_VERSION . '.' . substr(md5(time()), 0, 8);

        //  Registra e enfileira assets
        wp_register_style(
            'ytp-style',
            YTP_PLUGIN_URL . 'assets/css/style.css',
            [],
            $version_hash,
            'all'
        );

        wp_register_script(
            'ytp-iframe-api',
            'https://www.youtube.com/iframe_api',
            [],
            null,
            true
        );

        wp_register_script(
            'ytp-script',
            YTP_PLUGIN_URL . 'assets/js/player.js',
            ['ytp-iframe-api', 'jquery'],
            $version_hash, // CORRIGIDO: Era $version_version
            true
        );

        wp_enqueue_style('ytp-style');
        wp_enqueue_script('ytp-iframe-api');
        wp_enqueue_script('ytp-script');

        //  Configuração segura com validação
        $player_instance_id = 'default'; // Valor padrão para o ID da instância do player (pode ser sobrescrito pelo shortcode)

        $config = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'license_status' => $license_status,
            'security_hash' => $security_hash, // Este é o security_token usado em analytics
            'nonce' => wp_create_nonce('converttize_security'),
            'check_interval' => 30000, // ✅ Verificar a cada 30 segundos
            'license_messages' => [
                'inactive' => '🔐 Licença necessária para usar o Converttize',
                'degraded' => '⚠️ Licença suspensa - Player básico ativo',
                'active' => '✅ Player customizado ativo',
                'trial' => '   Trial ativo - Player customizado funcionando',
                'trial_expired' => '⏰ Trial expirado - Adquira uma licença'
            ],
            // Adicionado player_instance_id para a configuração global
            'player_instance_id' => $player_instance_id,
            'fullscreen' => $this->options['fullscreen'] ?? false,
            'speed' => $this->options['speed'] ?? false,
            'quality' => $this->options['quality'] ?? false,
            'repeat' => $this->options['repeat'] ?? false,
            'unlock_after' => 10,
            'hide_chrome' => false
        ];

        // Adicionar dados do trial se ativo
        if ($license_status === 'trial') {
            $config['trial_days_remaining'] = get_option('converttize_trial_days_remaining', 0);
            $config['trial_expires_at'] = get_option('converttize_trial_expires_at', '');
            $config['purchase_url'] = 'https://pay.hotmart.com/SEU_PRODUTO_AQUI'; //    CONFIGURE SUA URL
        }

        error_log("📊 Config enviado para JS: " . json_encode([
            'license_status' => $config['license_status'],
            'security_hash' => substr($config['security_hash'], 0, 8) . '...',
            'trial_days' => $config['trial_days_remaining'] ?? 'N/A'
        ]));

        // 🔐 Passa configuração para JavaScript
        wp_localize_script('ytp-script', 'ytpGlobalPluginConfig', $config);

        // 🔧 Script inline limpo
        $config_json = wp_json_encode($config);
        $inline_script = "
        (function() {
            if (typeof window.ytpGlobalPluginConfig === 'undefined') {
                window.ytpGlobalPluginConfig = $config_json;
            } else {
                const existing = window.ytpGlobalPluginConfig;
                const new_config = $config_json;
                
                if (!existing.license_status && new_config.license_status) {
                    window.ytpGlobalPluginConfig = new_config;
                } else if (!existing.security_hash && new_config.security_hash) {
                    existing.license_status = new_config.license_status;
                    existing.security_hash = new_config.security_hash;
                    existing.nonce = new_config.nonce;
                }
            }
        })();
        ";

        wp_add_inline_script('ytp-script', $inline_script, 'before');

        self::$assets_registered = true;
        
        error_log("✅ Assets registrados com sucesso");
    }

    public function license_notice() {
        $license_status = $this->validate_with_remote_server();
        
        if ($license_status === 'trial') {
            $days_remaining = get_option('converttize_trial_days_remaining', 0);
            ?>
            <div class="notice notice-info">
                <p>
                    <strong>🎯 Converttize Trial:</strong>
                    <?php echo $days_remaining; ?> dias restantes do seu trial gratuito.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=converttize-license')); ?>">Ver Detalhes</a>
                    
                    <?php if ($days_remaining <= 2): ?>
                    | <a href="https://pay.hotmart.com/SEU_PRODUTO_AQUI" target="_blank" style="color: #d63638; font-weight: bold;">   Adquirir Licença</a>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        } elseif ($license_status === 'trial_expired') {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>⏰ Converttize:</strong>
                    Seu trial expirou. Player básico ativo.
                    <a href="https://pay.hotmart.com/SEU_PRODUTO_AQUI" target="_blank" style="font-weight: bold;">🛒 Adquirir Licença</a>
                    | <a href="<?php echo esc_url(admin_url('admin.php?page=converttize-license')); ?>">Inserir Chave</a>
                </p>
            </div>
            <?php
        } /* elseif ($license_status !== 'active') {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>Converttize:</strong>
                    Licença necessária para funcionalidade completa (Status: <?php echo esc_html($license_status); ?>).
                    <a href="<?php echo esc_url(admin_url('admin.php?page=converttize-license')); ?>">Ativar Licença</a>
                    <!-- ✅ Botão para verificar agora -->
                    <button type="button" onclick="converttizeCheckNow()" style="margin-left: 10px;">
                        Verificar Agora
                    </button>
                </p>
            </div>
            <script>
            function converttizeCheckNow() {
                const button = event.target;
                button.disabled = true;
                button.textContent = 'Verificando...';
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'converttize_check_status',
                        nonce: '<?php echo wp_create_nonce('converttize_security'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Resposta:', data);
                    if (data.success && (data.data.license_status === 'active' || data.data.license_status === 'trial')) {
                        location.reload();
                    } else {
                        button.textContent = 'Status: ' + (data.data?.license_status || 'erro');
                        setTimeout(() => {
                            button.disabled = false;
                            button.textContent = 'Verificar Agora';
                        }, 3000);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    button.disabled = false;
                    button.textContent = 'Erro - Tente Novamente';
                });
            }
            </script>
            <?php
        } */
    }
}

// MANTENHA ESTAS CHAMADAS AJAX E HOOKS DE ATIVAÇÃO EXTERNOS COMO ESTÃO
// Endpoint AJAX seguro para analytics (CORRIGIDO)
add_action('wp_ajax_lumeplayer_save_analytics', 'lumeplayer_save_analytics');
add_action('wp_ajax_nopriv_lumeplayer_save_analytics', 'lumeplayer_save_analytics');
function lumeplayer_save_analytics() {
    // 🔍 DEBUG TEMPORÁRIO
    error_log("🔍 === DEBUG ANALYTICS ===");
    error_log("🔍 POST data keys: " . json_encode(array_keys($_POST))); // Não logar dados sensíveis
    error_log("🔍 Nonce válido: " . (wp_verify_nonce($_POST['nonce'] ?? '', 'converttize_security') ? 'SIM' : 'NÃO'));
    
    // 🔐 Verificação de nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'converttize_security')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    global $wpdb;

    $plugin_instance = Converttize::get_instance();
    if (!$plugin_instance) {
        wp_send_json_error(['message' => 'Plugin instance not found']);
        return;
    }
    
    $license_status = $plugin_instance->get_license_status();
    
    error_log("📊 Analytics - License Status: $license_status");
    
    // Permitir analytics para trial também
    if ($license_status !== 'active' && $license_status !== 'trial') {
        error_log("❌ Analytics bloqueado - Status: $license_status");
        wp_send_json_error(['message' => 'License not active: ' . $license_status]);
        return;
    }

    // 🔐 CORRIGIDO: Verificação de security_token baseada no status atual
    // O security_token vem do frontend (player.js) via globalPluginConfig.security_hash
    if (isset($_POST['security_token'])) {
        $security_secret = 'CONV_SEC_2024_XYZ789'; // Deve ser o mesmo secret do plugin principal
        $today = date('Y-m-d');
        
        // ✅ Usar o status atual para gerar o token esperado (mesma lógica do generate_security_hash no plugin principal)
        $expected_token = substr(hash('sha256', $license_status . $security_secret . $today), 0, 16);
        
        error_log("🔐 Token recebido: " . $_POST['security_token']);
        error_log(" Token esperado para '$license_status': $expected_token");
        
        if (!hash_equals($expected_token, $_POST['security_token'])) {
            error_log("❌ Token inválido para status: $license_status");
            wp_send_json_error(['message' => 'Invalid security token for status: ' . $license_status]);
            return;
        }
        
        error_log("✅ Security token validado para status: $license_status");
    } else {
        error_log("❌ Security token ausente para analytics");
        wp_send_json_error(['message' => 'Security token missing']);
        return;
    }

    if (!isset($_POST['video_id']) || !isset($_POST['watch_data'])) {
        wp_send_json_error(['message' => 'Dados incompletos']);
        return;
    }

    $video_id = sanitize_text_field($_POST['video_id']);
    $watch_data = json_decode(stripslashes($_POST['watch_data']), true);
    
    if (!is_array($watch_data)) {
        wp_send_json_error(['message' => 'Dados inválidos']);
        return;
    }

    $table = $wpdb->prefix . 'lumeplayer_analytics';

    $saved_count = 0;
    foreach ($watch_data as $second => $count) {
        $second = intval($second);
        $count = intval($count);

        if ($second < 0 || $count <= 0) {
            continue;
        }

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (video_id, time_point, views) VALUES (%s, %d, %d)
            ON DUPLICATE KEY UPDATE views = views + %d",
            $video_id,
            $second,
            $count,
            $count
        ));
        
        if ($result !== false) {
            $saved_count++;
        }
    }
    
    error_log("✅ Analytics salvos para vídeo: $video_id (Status: $license_status, Pontos salvos: $saved_count)");
    wp_send_json_success([
        'message' => 'Analytics saved',
        'points_saved' => $saved_count,
        'license_status' => $license_status
    ]);
}

// Hook de ativação para criar tabela (MANTENHA COMO ESTÁ)
register_activation_hook(__FILE__, 'lumeplayer_create_analytics_table');
function lumeplayer_create_analytics_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lumeplayer_analytics';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        video_id VARCHAR(255) NOT NULL,
        time_point INT NOT NULL,
        views INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY video_time (video_id, time_point),
        INDEX idx_video_id (video_id),
        INDEX idx_time_point (time_point)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// NOVO TRECHO: Hook AJAX para excluir um vídeo
add_action('wp_ajax_converttize_delete_video', 'converttize_delete_video_handler');

/**
 * Manipula a requisição AJAX para excluir dados de um vídeo.
 */
function converttize_delete_video_handler() {
    global $wpdb;

    // 1. Verificação de Segurança e Permissões
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => __('Permissão negada. Você não tem as permissões necessárias para realizar esta ação.', 'converttize')] );
        wp_die(); // Sempre use wp_die() no final dos manipuladores AJAX
    }

    // Verificação de nonce para proteger contra requisições falsificadas (CSRF)
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['nonce']) ), 'converttize_delete_video_nonce' ) ) {
        wp_send_json_error( ['message' => __('Falha na verificação de segurança (nonce inválido).', 'converttize')] );
        wp_die();
    }

    // 2. Obter e Sanitizar o ID do Vídeo
    $video_id = isset( $_POST['video_id'] ) ? sanitize_text_field( wp_unslash($_POST['video_id']) ) : '';

    if ( empty( $video_id ) ) {
        wp_send_json_error( ['message' => __('ID do vídeo não fornecido.', 'converttize')] );
        wp_die();
    }

    // 3. Deletar Dados do Banco de Dados
    $analytics_table = $wpdb->prefix . 'lumeplayer_analytics';
    $options_key_prefix = 'lume_player_options_'; // Prefixo para as opções específicas de vídeo

    // Inicia uma transação para garantir a integridade dos dados
    $wpdb->query('START TRANSACTION');

    try {
        // Deletar dados de analytics para o vídeo
        $deleted_analytics = $wpdb->delete(
            $analytics_table,
            ['video_id' => $video_id],
            ['%s']
        );

        // Deletar as opções de configuração específicas para o vídeo
        // delete_option retorna true se a opção foi deletada, false se não existia ou erro.
        // Para esta funcionalidade, não é um erro se a opção não existia.
        $deleted_options = delete_option( $options_key_prefix . $video_id );
        // NOVO: Deletar o título personalizado do vídeo
        delete_option('converttize_video_title_' . $video_id);


        // Verificar se houve erro nas operações que indicam falha real (ex: erro no delete do analytics)
        if ( $deleted_analytics === false ) { // $wpdb->delete() retorna false em caso de erro no SQL
            throw new Exception(__('Erro ao deletar dados de analytics do vídeo.', 'converttize'));
        }
        
        $wpdb->query('COMMIT'); // Confirma a transação se tudo correu bem

        error_log("✅ CONVERTTIZE: Vídeo '$video_id' e seus dados deletados com sucesso. Analytics: " . ($deleted_analytics ? 'Dados removidos' : 'Nenhum dado de analytics encontrado/removido') . ", Opções: " . ($deleted_options ? 'Removido' : 'Não encontrado/Não removido'));
        wp_send_json_success( ['message' => sprintf(__('Vídeo "%s" e seus dados foram excluídos com sucesso.', 'converttize'), $video_id)] );

    } catch ( Exception $e ) {
        $wpdb->query('ROLLBACK'); // Reverte a transação em caso de erro
        error_log("❌ CONVERTTIZE ERRO: Falha ao deletar vídeo '$video_id': " . $e->getMessage());
        wp_send_json_error( ['message' => $e->getMessage()] );
    }

    wp_die();
}
// FIM NOVO TRECHO

// NOVO TRECHO: Hook AJAX para Adicionar Novo Vídeo
add_action('wp_ajax_converttize_add_new_video', 'converttize_add_new_video_handler');

/**
 * Manipula a requisição AJAX para adicionar um novo vídeo manualmente.
 */
function converttize_add_new_video_handler() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permissão negada.', 'converttize')]);
        wp_die();
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'converttize_add_new_video_nonce')) {
        wp_send_json_error(['message' => __('Falha na verificação de segurança (nonce inválido).', 'converttize')] );
        wp_die();
    }

    $video_title_raw = isset($_POST['video_title']) ? wp_unslash($_POST['video_title']) : '';
    $video_url_raw = isset($_POST['video_url']) ? wp_unslash($_POST['video_url']) : '';

    $video_title = sanitize_text_field($video_title_raw);
    $video_id = converttize_extract_youtube_id($video_url_raw); // Usa a função utilitária

    if (empty($video_id)) {
        wp_send_json_error(['message' => __('ID ou link do vídeo YouTube inválido. Por favor, verifique.', 'converttize')]);
        wp_die();
    }
    if (empty($video_title)) {
        $video_title = __('Sem Título', 'converttize'); // Título padrão se o usuário não fornecer
    }

    $analytics_table = $wpdb->prefix . 'lumeplayer_analytics';

    // Armazena o título personalizado para o video_id
    update_option('converttize_video_title_' . $video_id, $video_title);

    // Insere uma entrada "dummy" na tabela de analytics para que o vídeo apareça na lista.
    // Usamos ON DUPLICATE KEY UPDATE para evitar erro se o vídeo já existir com time_point 0.
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$analytics_table} (video_id, time_point, views) VALUES (%s, %d, %d)
         ON DUPLICATE KEY UPDATE views = views + 0", // Garante que a linha exista/seja atualizada sem alterar 'views'
        $video_id,
        0, // time_point: 0
        0  // views: 0
    ));

    if ($result === false) {
        wp_send_json_error(['message' => __('Erro ao adicionar vídeo ao banco de dados. Tente novamente.', 'converttize')]);
    } else {
        wp_send_json_success(['message' => sprintf(__('Vídeo "%s" (ID: %s) adicionado com sucesso!', 'converttize'), $video_title, $video_id)]);
    }
    wp_die();
}
// FIM NOVO TRECHO

// NOVO TRECHO: Hook AJAX para atualizar título do vídeo
add_action('wp_ajax_converttize_update_video_title', 'converttize_update_video_title_handler');

/**
 * Manipula a requisição AJAX para atualizar o título do vídeo.
 */
function converttize_update_video_title_handler() {
    global $wpdb;

    // 1. Verificação de Segurança e Permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permissão negada.', 'converttize')]);
        return;
    }

    // Verificação de nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'converttize_update_video_title_nonce')) {
        wp_send_json_error(['message' => __('Falha na verificação de segurança (nonce inválido).', 'converttize')]);
        return;
    }

    // 2. Obter e Sanitizar os dados
    $video_id = isset($_POST['video_id']) ? sanitize_text_field(wp_unslash($_POST['video_id'])) : '';
    $new_title = isset($_POST['new_title']) ? sanitize_text_field(wp_unslash($_POST['new_title'])) : ''; 

    if (empty($video_id)) {
        wp_send_json_error(['message' => __('ID do vídeo não fornecido.', 'converttize')]);
        return;
    }

    // 3. Atualizar o título no banco de dados (opções)
    $updated = update_option('converttize_video_title_' . $video_id, $new_title);

    if ($updated) {
        wp_send_json_success([
            'message' => __('Título atualizado com sucesso!', 'converttize'),
            'new_title' => $new_title // Envia o título sanitizado de volta para o JS
        ]);
    } else {
        // Se update_option retornar false, pode significar que o valor não mudou ou houve um erro.
        // Se o valor não mudou, para o usuário é um sucesso, então verificamos se já era o mesmo.
        if (get_option('converttize_video_title_' . $video_id) === $new_title) {
            wp_send_json_success([
                'message' => __('Nenhuma alteração no título detectada.', 'converttize'),
                'new_title' => $new_title
            ]);
        } else {
            // Realmente falhou em atualizar por algum motivo
            wp_send_json_error(['message' => __('Erro ao salvar o título. Tente novamente.', 'converttize')]);
        }
    }

    wp_die();
}
// FIM NOVO TRECHO: Hook AJAX para atualizar título do vídeo

if ( ! class_exists( 'Puc_v4_Factory' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/plugin-update-checker.php';
}

// URL do seu script de atualização no servidor intermediário.
// SUBSTITUA ESTE URL PELA URL REAL DO SEU SCRIPT DE PROXY NO FUTURO!
// Ex: 'https://seusite.com/atualizacoes-converttize/index.php'
$update_server_url = $update_server_url = 'https://updates.converttize.com/index.php?slug=converttize-player';

// O terceiro parâmetro deve ser um SLUG único para o seu plugin.
// Use algo como o Text Domain do seu plugin para consistência.
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    $update_server_url,
    __FILE__, // Caminho para o arquivo principal do plugin
    'converttize-player' // SLUG único do plugin. Use o mesmo na URL acima!
);

// Instancia a classe principal do plugin
// É crucial que esta instância seja armazenada globalmente para ser acessível aos callbacks
global $converttize_main_plugin_instance;
$converttize_main_plugin_instance = new Converttize();

// A instância de YT_License_Manager já é criada dentro do construtor de Converttize.
// Não é mais necessário instanciá-la globalmente aqui.
// Removido:
// global $converttize_license_manager;
// $converttize_license_manager = new YT_License_Manager($converttize_main_plugin_instance);
<?php
/**
 * Classe LumePlayer_Admin_Analytics
 * 
 * Gerencia a página de analytics no painel administrativo do WordPress.
 */

// Evitar acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class LumePlayer_Admin_Analytics {
    private $main_plugin_instance; // Nova propriedade para armazenar a instância do plugin principal

    // MODIFICADO: Construtor agora aceita a instância do plugin principal
    public function __construct($main_plugin_instance) {
        $this->main_plugin_instance = $main_plugin_instance; // Armazena a instância do plugin principal
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']); // Hook para enfileirar scripts/styles (agora vazio para inline)
    }

    // Este método agora está vazio, pois o JS e CSS do Analytics serão inline na função lumeplayer_analytics_page()
    public function enqueue_scripts($hook) {
        // Nenhuma ação aqui, pois o JavaScript e CSS do Analytics serão incluídos inline
    }

    public function lumeplayer_analytics_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Você não tem permissão para acessar esta página.'));
        }
        
        // Obtém o ID do vídeo da URL, se houver
        $video_id = '';
        if (isset($_GET['video_id']) && !empty($_GET['video_id'])) {
            $video_id = sanitize_text_field($_GET['video_id']);
        }
        // Processar campo manual se selecionado
        if (isset($_GET['video_id_manual']) && !empty($_GET['video_id_manual'])) {
            $video_id = sanitize_text_field($_GET['video_id_manual']);
        }

        // Título dinâmico da página
        $page_title = $video_id 
                      ? sprintf(__('Analytics de Retenção para Vídeo: %s', 'converttize'), esc_html($video_id)) 
                      : __('Analytics de Retenção', 'converttize');

        // NOVO: Renderiza o cabeçalho admin usando o método da instância principal do plugin
        if ($this->main_plugin_instance) {
            $this->main_plugin_instance->render_admin_header($page_title); 
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'lumeplayer_analytics';

        // Busca IDs de vídeo únicos que possuem dados de analytics
        $videos_with_data = $wpdb->get_results("
            SELECT 
                video_id,
                COUNT(*) as total_points,
                MAX(views) as max_views,
                MAX(created_at) as last_view
            FROM $table 
            GROUP BY video_id 
            ORDER BY MAX(created_at) DESC
        ");

        if ($video_id && !empty($videos_with_data)) {
            $found = false;
            foreach ($videos_with_data as $video) {
                if ($video->video_id === $video_id) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $video_id = ''; // Se o ID na URL não existe nos dados, reverte para a visão geral
            }
        }
        ?>
        <div class="wrap">
            <!-- REMOVIDO: Antigo H1 da página, agora gerado pelo render_admin_header -->
            
            <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2>Visualizar Analytics de Retenção</h2>
                <p>Selecione um vídeo da lista ou insira um ID manualmente para ver o gráfico.</p>
                
                <form method="get" action="">
                    <input type="hidden" name="page" value="<?php echo esc_attr(Converttize::ANALYTICS_PAGE_SLUG); ?>">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="video_select">Selecionar Vídeo</label>
                            </th>
                            <td>
                                <select id="video_select" name="video_id" class="regular-text" onchange="toggleManualInput()">
                                    <option value="">-- Selecione um vídeo --</option>
                                    <?php foreach ($videos_with_data as $video): ?>
                                        <option value="<?php echo esc_attr($video->video_id); ?>" 
                                                <?php selected($video_id, $video->video_id); ?>>
                                            <?php echo esc_html($video->video_id); ?> 
                                            (<?php echo $video->total_points; ?> pontos, <?php echo $video->max_views; ?> views max)
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="manual">✏️ Inserir ID manualmente</option>
                                </select>
                                
                                <!-- CAMPO MANUAL (OCULTO) -->
                                <div id="manual_input" style="display: none; margin-top: 10px;">
                                    <input type="text" 
                                           id="video_id_manual" 
                                           name="video_id_manual" 
                                           class="regular-text" 
                                           placeholder="Ex: dQw4w9WgXcQ"
                                           value="<?php echo !in_array($video_id, array_column($videos_with_data, 'video_id')) ? esc_attr($video_id) : ''; ?>">
                                </div>
                                
                                <p class="description">
                                    Escolha um vídeo da lista ou selecione "Inserir ID manualmente" para digitar um novo.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Ver Gráfico', 'primary', 'submit', false); ?>
                </form>
            </div>

            <?php if (!empty($video_id)): ?>
                <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <?php
                    // Extrair ID do vídeo se for uma URL
                    $clean_video_id = $this->extract_youtube_id($video_id);
                    if ($clean_video_id) {
                        echo '<h2>Gráfico de Retenção para \'' . esc_html($clean_video_id) . '\'</h2>';
                        $this->render_video_retention_chart($clean_video_id);
                    } else {
                        echo '<p style="color: red;">❌ ID de vídeo inválido. Verifique se o ID está correto.</p>';
                    }
                    ?>
                </div>
            <?php else: ?>
                <div style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #0073aa;">
                    <p><strong>💡 Como usar:</strong></p>
                    <ol>
                        <li>Selecione um vídeo da lista acima</li>
                        <li>Clique em "Ver Gráfico"</li>
                        <li>Visualize a retenção de audiência com o vídeo de fundo</li>
                    </ol>
                    <p><strong>Exemplo:</strong> Selecione um vídeo da lista ou use "Inserir ID manualmente" para <code>dQw4w9WgXcQ</code></p>
                </div>
            <?php endif; ?>
        </div>

        <script>
        function toggleManualInput() {
            const select = document.getElementById('video_select');
            const manualDiv = document.getElementById('manual_input');
            const manualInput = document.getElementById('video_id_manual');
            
            if (select.value === 'manual') {
                manualDiv.style.display = 'block';
                manualInput.focus();
                // Limpa o select para não enviar valor
                select.name = 'video_select_temp';
                manualInput.name = 'video_id_manual';
            } else {
                manualDiv.style.display = 'none';
                select.name = 'video_id';
                manualInput.name = 'video_id_manual_temp';
            }
        }

        // VERIFICAR SE DEVE MOSTRAR CAMPO MANUAL AO CARREGAR
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.getElementById('video_select');
            const currentValue = '<?php echo esc_js($video_id); ?>';
            
            // Se o valor atual não está na lista, mostrar campo manual
            if (currentValue && !Array.from(select.options).some(option => option.value === currentValue)) {
                select.value = 'manual';
                toggleManualInput();
            }
        });
        </script>
        <?php
    }

    // Método público para renderizar o gráfico de retenção
    public function render_video_retention_chart($video_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'lumeplayer_analytics';
        
        // Buscar dados de retenção
        $retention_data = $wpdb->get_results($wpdb->prepare(
            "SELECT time_point, views FROM $table WHERE video_id = %s ORDER BY time_point ASC",
            $video_id
        ));
        
        if (empty($retention_data)) {
            echo '<div style="background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107;">';
            echo '<h4>⚠️ Nenhum dado encontrado</h4>';
            echo '<p>Para gerar o gráfico de retenção, primeiro você precisa:</p>';
            echo '<ol>';
            echo '<li>Usar o player em uma página: <code>[ytp_player video_id="' . esc_html($video_id) . '"]</code></li>';
            echo '<li>Assistir alguns segundos do vídeo</li>';
            echo '<li>Voltar aqui para ver o gráfico</li>';
            echo '</ol>';
            echo '<p><strong>💡 Dica:</strong> Cada segundo assistido gera um ponto no gráfico de retenção.</p>';
            echo '</div>';
            return;
        }
        
        // USAR MÉTODO EXISTENTE OU ASSUMIR QUE É ID DIRETO
        $youtube_id = method_exists($this, 'extract_youtube_id') ? $this->extract_youtube_id($video_id) : $video_id;
        
        // CALCULAR DADOS
        $max_views = max(array_column($retention_data, 'views'));
        $total_seconds_watched = 0;
        $chart_data = [];
        $chart_labels = [];
        $chart_retention = [];
        
        foreach ($retention_data as $point) {
            $total_seconds_watched += $point->views;
            $retention_percentage = ($point->views / $max_views) * 100;
            
            $mins = floor($point->time_point / 60);
            $secs = $point->time_point % 60;
            $time_label = $mins . ':' . str_pad($secs, 2, '0', STR_PAD_LEFT);
            
            $chart_labels[] = $time_label;
            $chart_retention[] = round($retention_percentage, 2);
            
            $chart_data[] = [
                'time' => (int)$point->time_point,
                'views' => (int)$point->views,
                'retention' => round($retention_percentage, 2),
                'time_label' => $time_label
            ];
        }
        
        $max_time = !empty($chart_data) ? max(array_column($chart_data, 'time')) : 0;
        $total_possible_seconds = $max_time * $max_views;
        $overall_retention = $total_possible_seconds > 0 ? ($total_seconds_watched / $total_possible_seconds) * 100 : 0;
        $average_watch_time = $max_views > 0 ? $total_seconds_watched / $max_views : 0;
        
        ?>
        <div class="video-retention-container">
            
            <!-- CONTROLES -->
            <div class="analytics-controls" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                <button id="toggleSync" class="button button-primary">🎬 Sincronização ON</button>
                <button id="toggleOverlayChart" class="button button-secondary">📊 Mostrar Gráfico no Vídeo</button>
                <button id="debugBtn" class="button">🐛 Debug</button>
                <span style="background: #e7f3ff; padding: 5px 10px; border-radius: 3px; margin-left: 10px;">
                    <strong>📊 Dados:</strong> <?php echo count($retention_data); ?> pontos | 
                    <strong>Retenção:</strong> <?php echo round($overall_retention, 1); ?>%
                </span>
            </div>

            <!-- DEBUG -->
            <div id="debugInfo" style="display: none; background: #f0f8ff; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #007cba;">
                <h4>🔍 Debug - Player com Gráfico Sobreposto</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <strong>📈 Dados:</strong>
                        <ul>
                            <li>Video ID: <?php echo esc_html($video_id); ?></li>
                            <li>YouTube ID: <?php echo esc_html($youtube_id); ?></li>
                            <li>Pontos: <?php echo count($retention_data); ?></li>
                            <li>Duração: <?php echo $max_time; ?>s</li>
                            <li>Retenção: <?php echo round($overall_retention, 2); ?>%</li>
                        </ul>
                    </div>
                    <div>
                        <strong>🎬 Status:</strong>
                        <div id="playerDebug" style="background: white; padding: 10px; border-radius: 3px; font-family: monospace; font-size: 12px;">
                            Player: <span id="playerStatus">Carregando...</span><br>
                            Estado: <span id="playerState">-</span><br>
                            Tempo: <span id="currentTimeDebug">00:00</span><br>
                            Último seek: <span id="lastSeek">-</span><br>
                            Gráfico overlay: <span id="overlayStatus">Oculto</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- PLAYER COM GRÁFICO SOBREPOSTO -->
            <div id="customPlayerWrapper" style="position: relative; width: 100%; max-width: 800px; margin: 0 auto; background: #000; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,0.3);">
                
                <!-- ÁREA DO VÍDEO -->
                <div id="videoArea" style="position: relative; width: 100%; height: 450px; background: #000;">
                    <div id="youtubePlayer"></div>
                    
                    <!-- GRÁFICO SOBREPOSTO TRANSPARENTE -->
                    <div id="overlayChart" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 3; pointer-events: none; display: none;">
                        <canvas id="overlayChartCanvas" style="width: 100%; height: 100%; pointer-events: auto; cursor: crosshair;"></canvas>
                    </div>
                    
                    <!-- CONTROLES CUSTOMIZADOS -->
                    <div id="playerOverlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 5; pointer-events: none;">
                        
                        <!-- CONTROLES INFERIORES -->
                        <div id="customControls" style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(0,0,0,0.9)); padding: 20px; pointer-events: auto;">
                            
                            <!-- CONTROLES -->
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <button id="playPauseBtn" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 8px; border-radius: 50%; transition: background 0.3s;">▶️</button>
                                    <div id="timeDisplay" style="color: white; font-family: 'Courier New', monospace; font-size: 14px; font-weight: bold;">
                                        <span id="currentTime">00:00</span> / <span id="totalTime">00:00</span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <button id="volumeBtn" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 5px;">🔊</button>
                                    <button id="fullscreenBtn" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 5px;">⛶</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- INDICADOR DE GRÁFICO ATIVO -->
                        <div id="chartActiveIndicator" style="position: absolute; top: 20px; right: 20px; background: rgba(52, 152, 219, 0.9); color: white; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: bold; z-index: 10; display: none; backdrop-filter: blur(5px);">
                            📊 GRÁFICO ATIVO
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ESTATÍSTICAS -->
            <div class="retention-stats" style="margin-top: 15px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 5px; max-width: 800px; margin-left: auto; margin-right: auto;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                    <div>
                        <div style="font-size: 12px; color: #666; text-transform: uppercase;">Duração Total</div>
                        <div style="font-size: 20px; font-weight: bold; color: #666;"><?php echo gmdate('i:s', $max_time); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #666; text-transform: uppercase;">Retenção Geral</div>
                        <div style="font-size: 20px; font-weight: bold; color: <?php echo $overall_retention >= 50 ? '#28a745' : '#e74c3c'; ?>;">
                            <?php echo round($overall_retention, 1); ?>%
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #666; text-transform: uppercase;">Tempo Médio</div>
                        <div style="font-size: 20px; font-weight: bold; color: #6f42c1;"><?php echo gmdate('i:s', $average_watch_time); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- GRÁFICO DETALHADO (SÓ VISUALIZAÇÃO) -->
            <div class="detailed-chart-container" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 5px; max-width: 800px; margin-left: auto; margin-right: auto;">
                <h4 style="margin: 0 0 15px 0;">📊 Análise Detalhada de Retenção</h4>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    <strong>📈 Gráfico completo</strong> para análise detalhada dos dados de retenção.
                    <strong>💡 Use o gráfico no vídeo acima</strong> para controlar a reprodução.
                </p>
                
                <div style="position: relative; height: 350px;">
                    <canvas id="detailedChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ✅ CARREGAR APIS (agora inline, como solicitado) -->
        <script src="https://www.youtube.com/iframe_api"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('🎬 Player com gráfico sobreposto iniciando...');
            
            // ✅ VARIÁVEIS GLOBAIS
            window.customPlayer = null;
            window.overlayChart = null;
            window.detailedChart = null;
            
            let syncEnabled = true;
            let overlayChartVisible = false;
            let debugVisible = false;
            let isPlaying = false;
            let currentTime = 0;
            let duration = 0;
            let seekTimeout = null;
            let updateInterval = null;
            
            // ✅ DADOS
            const chartData = <?php echo json_encode($chart_data); ?>;
            const chartLabels = <?php echo json_encode($chart_labels); ?>;
            const chartRetention = <?php echo json_encode($chart_retention); ?>;
            const youtubeId = '<?php echo esc_js($youtube_id); ?>';
            
            console.log('📊 Dados carregados:', { youtubeId, pontos: chartData.length });
            
            // ✅ FUNÇÃO PARA EXTRAIR YOUTUBE ID
            function extractYouTubeId(input) {
                if (/^[a-zA-Z0-9_-]{11}$/.test(input)) {
                    return input;
                }
                
                const patterns = [
                    /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/,
                    /youtube\.com\/v\/([a-zA-Z0-9_-]{11})/
                ];
                
                for (let pattern of patterns) {
                    const match = input.match(pattern);
                    if (match) return match[1];
                }
                
                return input;
            }
            
            const finalYouTubeId = extractYouTubeId(youtubeId);
            console.log('🎯 YouTube ID final:', finalYouTubeId);
            
            // ✅ CALLBACK DA YOUTUBE API
            window.onYouTubeIframeAPIReady = function() {
                console.log('📺 YouTube API carregada');
                initCustomPlayer();
            };
            
            // ✅ INICIALIZAR PLAYER
            function initCustomPlayer() {
                window.customPlayer = new YT.Player('youtubePlayer', {
                    height: '100%',
                    width: '100%',
                    videoId: finalYouTubeId,
                    playerVars: {
                        'autoplay': 0,
                        'controls': 0,
                        'disablekb': 1,
                        'fs': 0,
                        'iv_load_policy': 3,
                        'modestbranding': 1,
                        'playsinline': 1,
                        'rel': 0,
                        'showinfo': 0,
                        'cc_load_policy': 0,
                        'origin': window.location.origin
                    },
                    events: {
                        'onReady': onPlayerReady,
                        'onStateChange': onPlayerStateChange,
                        'onError': onPlayerError
                    }
                });
            }
            
            // ✅ PLAYER PRONTO - VERSÃO CORRIGIDA
            function onPlayerReady(event) {
                console.log('✅ Player customizado pronto');
                $('#playerStatus').text('✅ Conectado');
                
                // ✅ FUNÇÃO PARA TENTAR OBTER DURAÇÃO
                function tryGetDuration(attempts = 0) {
                    try {
                        if (window.customPlayer && typeof window.customPlayer.getDuration === 'function') {
                            const videoDuration = window.customPlayer.getDuration();
                            
                            if (videoDuration && videoDuration > 0) {
                                duration = videoDuration;
                                updateTimeDisplay();
                                console.log('📏 Duração obtida:', duration, 'segundos');
                                $('#playerStatus').text('✅ Conectado - ' + formatTime(duration));
                                return true;
                            }
                        }
                    } catch (error) {
                        console.warn('Tentativa', attempts + 1, 'falhou:', error.message);
                    }
                    
                    // ✅ TENTA NOVAMENTE ATÉ 5 VEZES
                    if (attempts < 5) {
                        setTimeout(() => tryGetDuration(attempts + 1), 1000);
                    } else {
                        console.warn('⚠️ Não foi possível obter duração após 5 tentativas');
                        $('#playerStatus').text('⚠️ Duração indisponível');
                    }
                    
                    return false;
                }
                
                // ✅ INICIA TENTATIVAS
                setTimeout(() => tryGetDuration(), 500);
                
                // ✅ SEMPRE INICIALIZA OS GRÁFICOS E LOOP (independente da duração)
                setTimeout(initCharts, 1000);
                startUpdateLoop();
            }
            
            // ✅ ERRO NO PLAYER
            function onPlayerError(event) {
                console.error('❌ Erro no player:', event.data);
                $('#playerStatus').text('❌ Erro: ' + event.data);
            }
            
            // ✅ MUDANÇA DE ESTADO
            function onPlayerStateChange(event) {
                const states = {
                    '-1': 'Não iniciado',
                    '0': 'Finalizado',
                    '1': 'Reproduzindo',
                    '2': 'Pausado',
                    '3': 'Buffering',
                    '5': 'Carregado'
                };
                
                isPlaying = (event.data === 1);
                $('#playerState').text(states[event.data] || 'Desconhecido');
                $('#playPauseBtn').text(isPlaying ? '⏸️' : '▶️');
            }
            
            // ✅ LOOP DE ATUALIZAÇÃO
            function startUpdateLoop() {
                updateInterval = setInterval(() => {
                    if (window.customPlayer && window.customPlayer.getCurrentTime) {
                        try {
                            currentTime = window.customPlayer.getCurrentTime();
                            updateTimeDisplay();
                        } catch (error) {
                            console.warn('Erro ao obter tempo atual:', error);
                        }
                    }
                }, 1000);
            }
            
            // ✅ ATUALIZAR DISPLAYS
            function updateTimeDisplay() {
                const current = formatTime(currentTime);
                const total = formatTime(duration);
                $('#currentTime').text(current);
                $('#totalTime').text(total);
                $('#currentTimeDebug').text(current);
            }
            
            function formatTime(seconds) {
                const mins = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return mins.toString().padStart(2, '0') + ':' + secs.toString().padStart(2, '0');
            }
            
            // ✅ NAVEGAR PARA TEMPO
            function seekToTime(timeInSeconds) {
                if (!syncEnabled || !window.customPlayer || Math.abs(timeInSeconds - currentTime) < 1) {
                    return;
                }
                
                if (seekTimeout) clearTimeout(seekTimeout);
                
                seekTimeout = setTimeout(() => {
                    try {
                        window.customPlayer.seekTo(timeInSeconds, true);
                        $('#lastSeek').text(formatTime(timeInSeconds));
                        console.log('🎯 Navegado para:', timeInSeconds + 's');
                    } catch (error) {
                        console.warn('Erro ao navegar:', error);
                    }
                }, 100);
            }
            
            // ✅ MOSTRAR INFO DO HOVER
            function showHoverInfo(point) {
                // Estas divs precisam existir no HTML para serem atualizadas
                // Ex: <span id="hoverTime"></span>, <span id="hoverRetention"></span> etc.
                // Não estão no HTML fornecido, então este trecho pode não funcionar diretamente.
                // #hoverInfo também precisaria ser uma div com display: none inicial.
                $('#hoverTime').text(point.time_label);
                $('#hoverRetention').text(point.retention.toFixed(1) + '%');
                $('#hoverViews').text(point.views + ' pessoas');
                $('#hoverInfo').fadeIn(200);
            }
            
            function hideHoverInfo() {
                $('#hoverInfo').fadeOut(200);
            }
            
            // ✅ CONFIGURAÇÃO DO GRÁFICO SOBREPOSTO (INTERATIVO)
            function getOverlayChartConfig() {
                return {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Retenção (%)',
                            data: chartRetention,
                            borderColor: 'rgba(52, 152, 219, 0.9)',
                            backgroundColor: 'rgba(52, 152, 219, 0.15)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgba(255, 255, 255, 0.9)',
                            pointBorderColor: 'rgba(52, 152, 219, 1)',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 10,
                            pointHoverBackgroundColor: '#f39c12',
                            pointHoverBorderColor: '#e67e22',
                            pointHoverBorderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: { display: false },
                            title: { display: false },
                            tooltip: {
                                enabled: true,
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#3498db',
                                borderWidth: 2,
                                cornerRadius: 8,
                                displayColors: false,
                                callbacks: {
                                    title: function(context) {
                                        const index = context[0].dataIndex;
                                        return '🎬 ' + chartData[index].time_label;
                                    },
                                    label: function(context) {
                                        const index = context.dataIndex;
                                        const point = chartData[index];
                                        return [
                                            'Retenção: ' + point.retention.toFixed(1) + '%',
                                            'Pessoas: ' + point.views,
                                            '🎯 Clique para navegar'
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                display: false
                            },
                            y: {
                                display: false,
                                min: 0,
                                max: 100
                            }
                        },
                        onHover: function(event, elements) {
                            if (elements.length > 0) {
                                const index = elements[0].index;
                                const point = chartData[index];
                                showHoverInfo(point);
                                if (syncEnabled) seekToTime(point.time);
                            } else {
                                hideHoverInfo();
                            }
                        },
                        onClick: function(event, elements) {
                            if (elements.length > 0) {
                                const index = elements[0].index;
                                const point = chartData[index];
                                seekToTime(point.time);
                            }
                        }
                    }
                };
            }
            
            // ✅ CONFIGURAÇÃO DO GRÁFICO DETALHADO (SÓ VISUALIZAÇÃO)
            function getDetailedChartConfig() {
                return {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Retenção (%)',
                            data: chartRetention,
                            borderColor: '#e74c3c',
                            backgroundColor: 'rgba(231, 76, 60, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#c0392b',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                display: true,
                                labels: { color: '#333' }
                            },
                            title: {
                                display: true,
                                text: '📊 Análise Completa de Retenção',
                                color: '#333',
                                font: { size: 16, weight: 'bold' }
                            },
                            tooltip: {
                                enabled: true,
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#e74c3c',
                                borderWidth: 2,
                                callbacks: {
                                    title: function(context) {
                                        const index = context[0].dataIndex;
                                        return '📊 ' + chartData[index].time_label;
                                    },
                                    label: function(context) {
                                        const index = context.dataIndex;
                                        const point = chartData[index];
                                        return [
                                            'Retenção: ' + point.retention.toFixed(1) + '%',
                                            'Pessoas: ' + point.views
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Tempo do Vídeo',
                                    color: '#666'
                                },
                                grid: { color: 'rgba(0,0,0,0.1)' },
                                ticks: { color: '#666' }
                            },
                            y: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Retenção (%)',
                                    color: '#666'
                                },
                                min: 0,
                                max: 100,
                                grid: { color: 'rgba(0,0,0,0.1)' },
                                ticks: { 
                                    color: '#666',
                                    callback: function(value) { return value + '%'; }
                                }
                            }
                        }
                        // SEM onHover e onClick - apenas visualização
                    }
                };
            }
            
            // ✅ INICIALIZAR GRÁFICOS
            function initCharts() {
                // Gráfico sobreposto (interativo)
                const overlayCtx = document.getElementById('overlayChartCanvas');
                if (overlayCtx) {
                    window.overlayChart = new Chart(overlayCtx, getOverlayChartConfig());
                    console.log('✅ Gráfico sobreposto criado');
                }
                
                // Gráfico detalhado (só visualização)
                const detailedCtx = document.getElementById('detailedChart');
                if (detailedCtx) {
                    window.detailedChart = new Chart(detailedCtx, getDetailedChartConfig());
                    console.log('✅ Gráfico detalhado criado');
                }
            }
            
            // ✅ EVENT LISTENERS
            $('#playPauseBtn').on('click', function() {
                if (window.customPlayer) {
                    if (isPlaying) {
                        window.customPlayer.pauseVideo();
                    } else {
                        window.customPlayer.playVideo();
                    }
                }
            });
            
            
            $('#volumeBtn').on('click', function() {
                if (window.customPlayer) {
                    try {
                        const isMuted = window.customPlayer.isMuted();
                        if (isMuted) {
                            window.customPlayer.unMute();
                            $(this).text('🔊');
                        } else {
                            window.customPlayer.mute();
                            $(this).text('🔇');
                        }
                    } catch (error) {
                        console.warn('Erro ao controlar volume:', error);
                    }
                }
            });
            
            $('#fullscreenBtn').on('click', function() {
                const elem = document.getElementById('customPlayerWrapper');
                if (elem.requestFullscreen) {
                    elem.requestFullscreen();
                } else if (elem.webkitRequestFullscreen) {
                    elem.webkitRequestFullscreen();
                } else if (elem.msRequestFullscreen) {
                    elem.msRequestFullscreen();
                }
            });
            
            $('#toggleSync').on('click', function() {
                syncEnabled = !syncEnabled;
                $(this).text(syncEnabled ? '🎬 Sincronização ON' : '⏸️ Sincronização OFF')
                       .toggleClass('button-primary', syncEnabled);
            });
            
             $('#toggleOverlayChart').on('click', function() {
                overlayChartVisible = !overlayChartVisible;
                
                // Alterna a visibilidade do contêiner principal do gráfico sobreposto
                $('#overlayChart').toggle(overlayChartVisible);
                $('#chartActiveIndicator').toggle(overlayChartVisible);
                
                // Atualiza o texto e a classe do botão
                $(this).text(overlayChartVisible ? '📊 Ocultar Gráfico do Vídeo' : '📊 Mostrar Gráfico no Vídeo')
                       .toggleClass('button-secondary', overlayChartVisible);
                
                // Atualiza o status de depuração
                $('#overlayStatus').text(overlayChartVisible ? 'Visível' : 'Oculto');

                // *** CRUCIAL: Ajusta os eventos de ponteiro com base na visibilidade do gráfico ***
                if (overlayChartVisible) {
                    // Quando o gráfico está visível, ele deve ser clicável
                    $('#overlayChart').css('pointer-events', 'auto');
                    // E os controles customizados do vídeo NÃO devem ser clicáveis
                    $('#customControls').css('pointer-events', 'none');
                } else {
                    // Quando o gráfico está oculto, ele não deve ser clicável
                    $('#overlayChart').css('pointer-events', 'none');
                    // E os controles customizados do vídeo devem ser clicáveis novamente
                    $('#customControls').css('pointer-events', 'auto');
                }
            });
            
            $('#debugBtn').on('click', function() {
                debugVisible = !debugVisible;
                $('#debugInfo').toggle(debugVisible);
                $(this).text(debugVisible ? '🐛 Ocultar Debug' : '🐛 Debug');
            });
            
            // ✅ INICIALIZAR SE API JÁ CARREGADA
            if (window.YT && window.YT.Player) {
                window.onYouTubeIframeAPIReady();
            }
        });
        </script>

        <style>
        .video-retention-container {
            max-width: 1000px;
            margin: 20px 0;
            
        }
        
        #customPlayerWrapper {
            transition: all 0.3s ease;
        }
        
        #customPlayerWrapper:hover {
            box-shadow: 0 12px 40px rgba(0,0,0,0.4);
        }
        
        #youtubePlayer {
            width: 100%;
            height: 100%;
        }
        
        /* GRÁFICO SOBREPOSTO TRANSPARENTE */
        #overlayChart {
            background: rgba(0,0,0,0.1);
            backdrop-filter: blur(1px);
        }
        
            #overlayChartCanvas {
            opacity: 0.9;
        }
        
        #customControls button:hover {
            background: rgba(255,255,255,0.2) !important;
            border-radius: 50%;
        }
        
        #progressContainer:hover #progressBar {
            box-shadow: 0 0 10px rgba(231, 76, 60, 0.5);
        }
        
        #hoverInfo {
            backdrop-filter: blur(15px);
        }
        
        #chartActiveIndicator {
            animation: pulse 2s infinite;
        }
        
        .analytics-controls .button {
            margin-right: 10px;
        }
        
        .retention-stats, .detailed-chart-container {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        #overlayChartCanvas {
            cursor: crosshair;
        }

        
        /* GRÁFICO DETALHADO SEM CURSOR ESPECIAL */
        #detailedChart {
            cursor: default;
        }
        
        /* ANIMAÇÕES */
        @keyframes pulse {
            0% { opacity: 0.7; }
            50% { opacity: 1; }
            100% { opacity: 0.7; }
        }
        
        /* RESPONSIVO */
        @media (max-width: 768px) {
            #customPlayerWrapper {
                height: 300px;
            }

            
            #hoverInfo {
                font-size: 12px;
                padding: 10px;
                min-width: 200px;
            }
            
            #customControls {
                padding: 15px;
            }
        }
        </style>
        <?php
    }

    // Função auxiliar para extrair ID do YouTube
    private function extract_youtube_id($input) {
        $input = trim($input);
        
        // Se já é um ID válido (11 caracteres)
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }
        
        // Extrair de URL
        $pattern = '/(?:youtube(?:-nocookie)?\.com\/(?:[^\/]+\/.+\/|(?:v|embed|shorts|live)\/|.*[?&](?:v=|video_id=))|youtu\.be\/)([A-Za-z0-9_-]{11})/i';
        if (preg_match($pattern, $input, $matches)) {
            return $matches[1];
        }

        
        return false;
    }

    // Função para renderizar gráfico simples (fallback)
    private function render_simple_retention_chart($video_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'lumeplayer_analytics';
        
        $retention_data = $wpdb->get_results($wpdb->prepare(
            "SELECT time_point, views FROM $table WHERE video_id = %s ORDER BY time_point ASC",
            $video_id
        ));
        
        if (empty($retention_data)) {
            echo '<p>❌ Nenhum dado de analytics encontrado para este vídeo.</p>';
            echo '<p><strong>Possíveis motivos:</strong></p>';
            echo '<ul>';
            echo '<li>O vídeo ainda não foi assistido com o player</li>';
            echo '<li>O ID do vídeo está incorreto</li>';
            echo '<li>Os dados de analytics não estão sendo salvos corretamente</li>';
            echo '</ul>';
            return;
        }
        
        echo '<p>✅ Encontrados ' . count($retention_data) . ' pontos de dados para este vídeo.</p>';
        
        // Gráfico simples em HTML/CSS
        $max_views = max(array_column($retention_data, 'views'));
        echo '<div style="border: 1px solid #ddd; padding: 20px; background: #f9f9f9;">';
        echo '<h3>Dados de Retenção:</h3>';
        echo '<div style="display: flex; align-items: end; gap: 2px; height: 200px; margin: 20px 0;">';
        
        foreach ($retention_data as $point) {
            $height = ($point->views / $max_views) * 180;
            $percentage = round(($point->views / $max_views) * 100, 1);
            echo '<div style="background: #e74c3c; width: 8px; height: ' . $height . 'px; margin-right: 1px;" title="Tempo: ' . $point->time_point . 's - Views: ' . $point->views . ' (' . $percentage . '%)"></div>';
        }
        
        echo '</div>';
        echo '<p><strong>Total de pontos:</strong> ' . count($retention_data) . '</p>';
        echo '<p><strong>Máximo de views:</strong> ' . $max_views . '</p>';
        echo '</div>';
    }
}
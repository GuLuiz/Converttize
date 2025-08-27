(function ($) {
    let players = {};
    let playersState = {};
    let viewData = {};
    let analyticsAlreadySent = {};
    let licenseStatus = 'inactive'; // Default to inactive until determined by PHP config
    let isInitialized = false;
    let securityToken = null;
    let globalConfig = null; // Will hold ytpGlobalPluginConfig from PHP

    let pageHasActiveUnlockController = null;

    // --- VARIÁVEIS E FUNÇÕES GLOBAIS PARA CONTROLE DE LISTENERS ---
    // Essas variáveis e funções são definidas uma vez e usadas por todas as instâncias do player.
    let handlePlayButtonClickRef = null;
    let handlePauseButtonClickRef = null;
    let handleContainerClickRef = null;

    const attachPlayerListeners = (player, container, features, playBtnCustom, pauseBtnCustom) => {
        // Atacha listeners para os botões customizados de Play/Pause
        if (features.enable_play_pause_buttons) {
            if (playBtnCustom && !handlePlayButtonClickRef) {
                handlePlayButtonClickRef = (event) => {
                    event.stopPropagation();
                    if (playersState[container.id.replace('_container', '')]) playersState[container.id.replace('_container', '')].wasPausedByVisibilityChange = false;
                    if (player.isMuted() || playersState[container.id.replace('_container', '')].initialAutoplayMuted) {
                        player.unMute();
                        playersState[container.id.replace('_container', '')].initialAutoplayMuted = false;
                    }
                    player.playVideo();
                    updatePlayPauseButtons(container, true, features);
                };
                playBtnCustom.addEventListener('click', handlePlayButtonClickRef);
            }
            if (pauseBtnCustom && !handlePauseButtonClickRef) {
                handlePauseButtonClickRef = (event) => {
                    event.stopPropagation();
                    if (playersState[container.id.replace('_container', '')]) playersState[container.id.replace('_container', '')].wasPausedByVisibilityChange = false;
                    player.pauseVideo();
                    updatePlayPauseButtons(container, false, features);
                };
                pauseBtnCustom.addEventListener('click', handlePauseButtonClickRef);
            }
        }
        // Atacha listener para o clique na área do container (quando botões customizados estão desativados e chrome oculto)
        else if (features.hide_chrome) {
            if (!handleContainerClickRef) {
                handleContainerClickRef = (event) => {
                    // Garante que o clique não é em nenhum overlay ativo ou botão customizado
                    const isOverlayClick = event.target.closest('.ytp-sound-overlay');
                    const isMutedAutoplayOverlayClick = event.target.closest('.ytp-muted-autoplay-overlay');
                    const isCustomButton = event.target.closest('.ytp-btn') ||
                                        event.target.closest('.ytp-replay-btn') ||
                                        event.target.closest('.ytp-startover-btn') ||
                                        event.target.closest('.ytp-ended-btn');

                    if (!isOverlayClick && !isMutedAutoplayOverlayClick && !isCustomButton) {
                        if (player.isMuted() || playersState[container.id.replace('_container', '')].initialAutoplayMuted) {
                            player.unMute();
                            playersState[container.id.replace('_container', '')].initialAutoplayMuted = false;
                            player.playVideo();
                        } else {
                            if (player.getPlayerState() === YT.PlayerState.PLAYING) {
                                player.pauseVideo();
                            } else {
                                player.playVideo();
                            }
                        }
                    }
                };
                container.addEventListener('click', handleContainerClickRef, true); // Usa fase de captura
            }
        }
    };

    const detachPlayerListeners = (container, playBtnCustom, pauseBtnCustom) => {
        if (playBtnCustom && handlePlayButtonClickRef) {
            playBtnCustom.removeEventListener('click', handlePlayButtonClickRef);
            handlePlayButtonClickRef = null;
        }
        if (pauseBtnCustom && handlePauseButtonClickRef) {
            pauseBtnCustom.removeEventListener('click', handlePauseButtonClickRef);
            handlePauseButtonClickRef = null; // CORRIGIDO: Este era o erro, estava handlePlayButtonClickRef = null
        }
        if (container && handleContainerClickRef) {
            container.removeEventListener('click', handleContainerClickRef, true);
            handleContainerClickRef = null;
        }
    };
    // --- FIM DAS VARIÁVEIS E FUNÇÕES GLOBAIS PARA CONTROLE DE LISTENERS ---


    // --- LÓGICA DE INICIALIZAÇÃO ROBUSTA E SEGURA ---
    let initializationAttempts = 0;
    const MAX_INITIALIZATION_ATTEMPTS = 50; // Max attempts to find player wrappers
    let initializationInterval = null;

    let youtubeApiReady = false; // Flag for onYouTubeIframeAPIReady event
    let domReady = false;        // Flag for $(document).ready()

    let youtubeApiPollInterval = null; // Store interval ID for YouTube API polling
    let youtubeApiPollAttempts = 0;
    const MAX_YOUTUBE_API_POLL_ATTEMPTS = 100; // Aumentado para 10 segundos (100 * 100ms)
    // Mensagem de erro para a falha de carregamento da API do YouTube
    const YOUTUBE_API_LOAD_ERROR_MESSAGE = "A API do YouTube não conseguiu carregar. Verifique sua conexão com a internet, bloqueadores de anúncios ou o acesso ao YouTube. Pode ser um bloqueio de rede.";


    // Função para verificar se YT object é disponível através de polling
    function pollForYoutubeApi() {
        if (youtubeApiReady || (typeof YT !== 'undefined' && typeof YT.Player !== 'undefined')) {
            clearInterval(youtubeApiPollInterval); // Stop polling

            if (!youtubeApiReady) { // If YT object detected but official event didn't fire
                console.warn('[CONVERTTIZE-INIT] YT object detectado via polling, onYouTubeIframeAPIReady NÃO disparou. Forçando chamada do callback oficial.');
                youtubeApiReady = true; // Set the flag
                // Manually call the official onYouTubeIframeAPIReady to ensure full setup
                if (typeof window.onYouTubeIframeAPIReady === 'function') {
                    window.onYouTubeIframeAPIReady();
                } else {
                    console.error('[CONVERTTIZE-INIT] window.onYouTubeIframeAPIReady is not a function, cannot force setup.');
                    initializeLicenseConfig(); // Fallback to just license config
                    attemptPlayerInitialization(); // And attempt initialization
                }
            } else {
                console.log('[CONVERTTIZE-INIT] YT object detectado via polling e onYouTubeIframeAPIReady já disparado. Prosseguindo.');
                // In this case, onYouTubeIframeAPIReady would have already called initializeLicenseConfig and attemptPlayerInitialization
            }
        } else {
            youtubeApiPollAttempts++;
            if (youtubeApiPollAttempts >= MAX_YOUTUBE_API_POLL_ATTEMPTS) {
                clearInterval(youtubeApiPollInterval); // Stop polling
                console.error('[CONVERTTIZE-INIT] YouTube API (YT object) NÃO disponível após MÚLTIPLAS tentativas de polling. Pode estar BLOQUEADO ou falhou ao carregar.');
                if (!isInitialized) {
                    displayGeneralInitializationError(YOUTUBE_API_LOAD_ERROR_MESSAGE);
                }
            }
        }
    }

    // Inicializa a configuração da licença a partir dos dados fornecidos pelo PHP
    function initializeLicenseConfig() {
        if (typeof window.ytpGlobalPluginConfig !== 'undefined' && globalConfig === null) {
            globalConfig = window.ytpGlobalPluginConfig;
            licenseStatus = globalConfig.license_status || 'inactive';
            securityToken = globalConfig.security_hash || null;

            // CORRIGIDO: validateIntegrity agora apenas verifica a existência dos dados
            if (!validateIntegrity()) {
                console.warn('[CONVERTTIZE-INIT] Configuração de integridade da licença incompleta. Revertendo para status padrão: inactive.');
                licenseStatus = 'inactive';
            }
            console.log(`[CONVERTTIZE-INIT] Configuração da licença carregada. Status: ${licenseStatus}`);
        } else if (globalConfig === null) {
            console.warn('[CONVERTTIZE-INIT] ytpGlobalPluginConfig não definida ao tentar inicializar config. Status de licença padrão: inactive.');
            licenseStatus = 'inactive';
        }
    }

    // Função chamada pelo script da API do YouTube uma vez que ele está carregado
    window.onYouTubeIframeAPIReady = function () {
        console.log('[CONVERTTIZE-INIT] Evento onYouTubeIframeAPIReady disparado.');
        youtubeApiReady = true; // Ensure flag is true if official event fires first
        clearInterval(youtubeApiPollInterval); // Stop polling
        initializeLicenseConfig(); // Ensure license config is loaded
        attemptPlayerInitialization(); // Attempt main player initialization
    };

    // Função central para tentar inicializar o player quando ambas as flags são true
    function attemptPlayerInitialization() {
        if (youtubeApiReady && domReady && !isInitialized) {
            console.log('[CONVERTTIZE-INIT] YouTube API e DOM prontos. Iniciando tentativa principal de inicialização dos players.');
            tryInitializePlayersSafely();
            if (!isInitialized) {
                if (!initializationInterval) {
                    console.log('[CONVERTTIZE-INIT] Player não inicializado na primeira tentativa, iniciando intervalo de retentativas para encontrar wrappers.');
                    initializationInterval = setInterval(tryInitializePlayersSafely, 100);
                }
            } else {
                console.log('[CONVERTTIZE-INIT] Players inicializados com sucesso, limpando intervalo de retentativas de wrappers.');
                clearInterval(initializationInterval);
            }
        } else if (isInitialized) {
            clearInterval(initializationInterval);
            console.log('[CONVERTTIZE-INIT] Player já inicializado, ignorando tentativa adicional.');
        }
    }

    // Tenta encontrar os wrappers dos players e inicializá-los
    function tryInitializePlayersSafely() {
        if (isInitialized) {
            clearInterval(initializationInterval);
            return;
        }

        const wrappers = document.querySelectorAll('.ytp-wrapper');

        if (wrappers.length > 0) {
            initializePlayers(wrappers);
            isInitialized = true;
            clearInterval(initializationInterval);
            console.log('[CONVERTTIZE-INIT] Players wrappers inicializados com sucesso.');
        } else {
            initializationAttempts++;
            if (initializationAttempts >= MAX_INITIALIZATION_ATTEMPTS) {
                clearInterval(initializationInterval);
                console.warn('[CONVERTTIZE-INIT] Máximo de tentativas de encontrar wrappers atingido. Nenhum player encontrado para inicializar.');
                displayGeneralInitializationError("Nenhum player foi encontrado para inicializar. Verifique a configuração do shortcode ou se o Elementor está atrasando a renderização.");
            }
        }
    }

    // Função que itera e renderiza cada player.
    function initializePlayers(wrappers) {
        wrappers.forEach(wrapper => {
            const uid = wrapper.id.replace('_container', '');
            const playerData = window['ytpData_' + uid];
            if (!playerData || !playerData.video_id) {
                console.warn(`[CONVERTTIZE-INIT] Dados insuficientes para player ${uid}. Pulando renderização.`);
                return;
            }
            console.log(`[CONVERTTIZE-INIT] Renderizando player ${uid} com status: ${licenseStatus}.`);
            renderPlayerByLicenseStatus(wrapper, uid, playerData);
        });
    }

    // Exibe uma mensagem de erro geral em todos os wrappers de player
    function displayGeneralInitializationError(message) {
        $('.ytp-wrapper').each(function() {
            const $wrapper = $(this);
            if ($wrapper.find('.converttize-init-error').length === 0) {
                const errorDiv = `
                    <div class="converttize-init-error" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; z-index: 1000;">
                        <h3 style="margin: 0 0 10px; font-size: 1.2em;">⚠️ Erro ao carregar o player</h3>
                        <p style="margin: 0 0 15px; font-size: 0.9em;">${message}</p>
                        <button onclick="window.location.reload();" style="padding: 10px 20px; background: #e74c3c; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px;">
                            Recarregar Página
                        </button>
                        <p style="font-size: 0.8em; margin-top: 10px; opacity: 0.7;">(${initializationAttempts} tentativas de carregamento)</p>
                    </div>
                `;
                $wrapper.append(errorDiv);
            }
        });
    }
    // --- FIM DA LÓGICA DE INICIALIZAÇÃO ROBUSTA E SEGURA ---


    // Função de Easing: Começa rápida e desacelera na parte final.
    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    // NOVA FUNÇÃO: Calcula o progresso percebido da barra de progresso.
    // Implementa arrancada forte nos primeiros 9 segundos/30% e desaceleração linear de velocidade.
    function getPerceivedProgress(currentTime, duration) {
        if (duration === 0) return 0; // Evita divisão por zero

        const REELS_THRESHOLD_SECONDS = 30;  // Novo: Limiar para considerar um vídeo "reel"
        const TRANSITION_TIME_SECONDS = 9;   // Ponto de transição para vídeos longos
        const TARGET_PERCENT_BAR = 0.30;     // Percentual da barra a ser preenchido na transição para vídeos longos

        // Cenário 1: Vídeos "Reels" (Duração Total <= 30 segundos)
        // A barra de progresso se move de forma linear, "normal".
        if (duration <= REELS_THRESHOLD_SECONDS) {
            return currentTime / duration;
        }

        // Cenário 2: Vídeos Longos (Duração Total > 30 segundos)
        // Aplica a metodologia de velocidade acelerada no início e desaceleração gradual.

        // Fase 1: Arrancada inicial (até 9 segundos de vídeo real)
        // A barra preenche 30% usando Math.sqrt para uma arrancada brutal.
        if (currentTime <= TRANSITION_TIME_SECONDS) {
            const t_normalized = currentTime / TRANSITION_TIME_SECONDS;
            return TARGET_PERCENT_BAR * Math.sqrt(t_normalized);
        }
        // Fase 2: Desaceleração mais suave (após 9 segundos de vídeo real até o final)
        else {
            const remainingDuration = duration - TRANSITION_TIME_SECONDS;
            const currentSegmentTime = currentTime - TRANSITION_TIME_SECONDS;

            const t_segment_normalized = currentSegmentTime / remainingDuration;

            const remainingBarPercent = 1 - TARGET_PERCENT_BAR;

            // Aplica easeOutCubic para esta fase. Começa mais rápida e desacelera
            // mais na parte final do segmento, que atende ao "desacelerar do meio pro final".
            const perceivedProgressInSegment = remainingBarPercent * easeOutCubic(t_segment_normalized);

            // Soma o percentual já preenchido da barra (30%) com o progresso na fase 2
            return TARGET_PERCENT_BAR + perceivedProgressInSegment;
        }
    }


    /**
     * Aplica as cores definidas pelo usuário aos elementos do player.
     * @param {string} playerId - O ID único do player.
     * @param {object} colors - Objeto com as propriedades de cor.
     */
    function applyColorsToPlayer(playerId, colors) {
        const container = document.getElementById(playerId + '_container');
        if (!container || !colors) return;

        Object.keys(colors).forEach(key => {
            const cssVar = '--ytp-' + key.replace('_', '-');
            container.style.setProperty(cssVar, colors[key]);
        });

        const primaryRgb = hexToRgb(colors.primary_color || '#ff9500');
        if (primaryRgb) {
            container.style.setProperty('--ytp-primary-color-rgb', `${primaryRgb.r}, ${primaryRgb.g}, ${primaryRgb.b}`);
        }

        const overlay = container.querySelector('.ytp-sound-overlay');

        const endedOverlay = container.querySelector('.ytp-video-ended-overlay');
        const playerData = window['ytpData_' + playerId]; // Get playerData to access features/texts

        if (overlay && playerData && playerData.features) {
            overlay.style.background = colors.overlay_bg || 'rgba(0,0,0,0.75)';
            overlay.style.color = colors.text_color || '#ffffff';
            overlay.style.boxShadow = `0 0 15px ${colors.primary_color || '#ff9500'}`;
            const soundMessageElement = overlay.querySelector('.ytp-sound-message');
            if (soundMessageElement) soundMessageElement.textContent = playerData.features.sound_overlay_message || 'Seu vídeo já começou';
            const soundClickElement = overlay.querySelector('.ytp-sound-click');
            if (soundClickElement) soundClickElement.textContent = playerData.features.sound_overlay_click || 'Clique para ouvir';
        }

        if (endedOverlay && playerData && playerData.features) {
            endedOverlay.style.background = '#000000 !important';
            endedOverlay.style.color = colors.text_color || '#ffffff';

            const endedMessageElement = endedOverlay.querySelector('.ytp-ended-message');
            if (endedMessageElement) endedMessageElement.textContent = playerData.features.ended_overlay_message || 'Vídeo finalizado';

            const endedButtons = endedOverlay.querySelectorAll('.ytp-ended-btn');
            endedButtons.forEach(btn => {
                btn.style.background = colors.primary_color || '#ff9500';
                btn.style.color = colors.text_color || '#ffffff';
                if (btn.getAttribute('data-action') === 'replay') {
                    btn.textContent = playerData.features.ended_overlay_replay_button || 'Assistir novamente';
                }
            });
        }

        const buttons = container.querySelectorAll('.ytp-btn');
        buttons.forEach(btn => {
            btn.style.color = colors.text_color || '#ffffff';
        });
    }

    function hexToRgb(hex) {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : null;
    }

    // CORRIGIDO: validateIntegrity() agora apenas verifica se o globalConfig e suas propriedades de segurança existem.
    // A validação criptográfica real do hash ocorre SOMENTE no servidor (no PHP).
    function validateIntegrity() {
        // Verifica se o objeto de configuração global e o security_hash e license_status vieram do servidor.
        // O player.js CONFIA que o PHP gerou o security_hash corretamente usando o segredo.
        return typeof globalConfig !== 'undefined' &&
               globalConfig !== null &&
               typeof globalConfig.security_hash === 'string' &&
               typeof globalConfig.license_status === 'string';
    }

    function handleTrialStatus(config) {
        if (config.license_status === 'trial') {
            const daysRemaining = config.trial_days_remaining || 0;
            return true;
        }

        if (config.license_status === 'trial_expired') {
            showTrialExpiredMessage(config.purchase_url);
            return false;
        }

        return config.license_status === 'active';
    }

    function showTrialWarning(daysRemaining, purchaseUrl) {
        if (document.getElementById('converttize-trial-warning')) return;

        const warning = document.createElement('div');
        warning.id = 'converttize-trial-warning';
        warning.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            z-index: 9999;
            font-family: Arial, sans-serif;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-width: 300px;
            animation: slideIn 0.5s ease-out;
        `;
        warning.innerHTML = `
            <div style="display: flex; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 20px; margin-right: 10px;">🎯</span>
                <strong>Converttize Trial</strong>
            </div>
            <div style="margin-bottom: 15px;">
                ${daysRemaining === 0 ? 'Expira hoje!' : `${daysRemaining} dias restantes`}
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="${purchaseUrl || '#'}"
                   style="background: rgba(255,255,255,0.2); color: white; padding: 8px 12px;
                          text-decoration: none; border-radius: 4px; font-size: 12px; flex: 1; text-align: center;"
                   >
                    🛒 Adquirir
                </a>
                <button onclick="this.parentElement.parentElement.remove()"
                        style="background: rgba(255,255,255,0.2); color: white; border: none;
                               padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                    ✕
                </button>
            </div>
        `;

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);

        document.body.appendChild(warning);

        setTimeout(() => {
            if (warning.parentNode) {
                warning.style.animation = 'slideIn 0.5s ease-out reverse';
                setTimeout(() => {
                    if (warning.parentNode) {
                        warning.parentNode.removeChild(warning);
                    }
                }, 500);
            }
        }, 15000);
    }

    function showTrialExpiredMessage(purchaseUrl) {
        const message = document.createElement('div');
        message.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #f44336;
            color: white;
            padding: 12px 16px;
            border-radius: 6px;
            z-index: 9999;
            font-family: Arial, sans-serif;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        `;
        message.innerHTML = `
            <div style="display: flex; align-items: center;">
                <span style="margin-right: 8px;">⏰</span>
                Trial expirado -
                <a href="${purchaseUrl || '#'}" style="color: white; margin-left: 5px;">
                    Adquirir licença
                </a>
            </div>
        `;

        document.body.appendChild(message);

        setTimeout(() => {
            if (message.parentNode) {
                message.parentNode.removeChild(message);
            }
        }, 8000);
    }

    function initLicenseConfig() {
        if (typeof window.ytpGlobalPluginConfig !== 'undefined') {
            globalConfig = window.ytpGlobalPluginConfig;
            licenseStatus = globalConfig.license_status || 'inactive';
            securityToken = globalConfig.security_hash || null;
        } else {
            licenseStatus = 'inactive';
        }
        return licenseStatus;
    }

    function renderPlayerByLicenseStatus(wrapper, uid, playerData) {
        const canUseCustomPlayer = handleTrialStatus(globalConfig || {});

        if (licenseStatus === 'active' || (canUseCustomPlayer && licenseStatus === 'trial')) {
            renderCustomPlayer(wrapper, uid, playerData);
        } else {
            renderBasicYouTubePlayer(wrapper, uid, playerData);
        }
    }

    function renderCustomPlayer(wrapper, uid, playerData) {
        let playerElement = document.getElementById(uid);

        if (!playerElement || playerElement.tagName !== 'IFRAME') {
            playerElement = wrapper.querySelector('.ytp-iframe');
            if (!playerElement) {
                playerElement = document.getElementById(uid);
            }
        }

        const player = new YT.Player(playerElement, {
            videoId: playerData.video_id,
            playerVars: {
                autoplay: 1,
                controls: playerData.features.hide_chrome ? 0 : 1,
                modestbranding: 1,
                rel: 0,
                disablekb: 1,
                fs: playerData.features.hide_chrome ? 0 : 1,
                cc_load_policy: 0,
                cc_lang_pref: '',
                playsinline: 1,
                hl: 'en',
                iv_load_policy: 3,
                showinfo: 0,
                enablejsapi: 1,
                origin: window.location.origin
            },
            events: {
                'onReady': event => {
                    players[uid] = event.target;
                    onPlayerReady(event, uid, playerData.features);
                },
                'onStateChange': event => onPlayerStateChange(event, uid),
                'onError': event => onPlayerError(event, uid) // AGORA PASSAMOS O UID PARA TRATAR O ERROR ESPECÍFICO
            }
        });
    }

    function renderBasicYouTubePlayer(wrapper, uid, playerData) {
        const statusText = licenseStatus === 'trial_expired' ?
            '⏰ Trial Expirado' : '⚠️ Licença Necessária';

        wrapper.innerHTML = `
            <div class="converttize-basic-player">
                <iframe
                    width="100%"
                    height="315"
                    src="https://www.youtube.com/embed/${playerData.video_id}?rel=0&modestbranding=1&autoplay=1"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen>
                </iframe>
                <div class="converttize-status-indicator">
                    ${statusText}
                </div>
            </div>
        `;
        addBasicPlayerStyles();
    }

    function addBasicPlayerStyles() {
        if (document.getElementById('converttize-basic-styles')) return;
        const style = document.createElement('style');
        style.id = 'converttize-basic-styles';
        style.textContent = `
            .converttize-basic-player {
                position: relative;
                background: #000;
                border-radius: 8px;
                overflow: hidden;
                width: 100%;
                height: 100%;
            }
            .converttize-basic-player iframe {
                width: 100%;
                height: 100%;
                min-height: 315px;
            }
            .converttize-status-indicator {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(255, 193, 7, 0.9);
                color: #000;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
                z-index: 10;
            }
        `;
        document.head.appendChild(style);
    }

    function sendAnalytics(analyticsData, exitTime) {
        if (licenseStatus !== 'active' && licenseStatus !== 'trial') {
            return;
        }

        if (!analyticsData || !globalConfig || !globalConfig.ajax_url) {
            return;
        }

        const videoKey = analyticsData.videoId;
        if (analyticsAlreadySent[videoKey]) {
            return;
        }

        analyticsAlreadySent[videoKey] = true;

        const watchData = {};
        if (analyticsData.watchedTime > 0) {
            const totalSeconds = Math.floor(analyticsData.watchedTime);
            for (let i = 0; i <= totalSeconds; i++) {
                watchData[i] = 1;
            }
        }

        const postData = {
            action: 'lumeplayer_save_analytics',
            video_id: analyticsData.videoId,
            watch_data: JSON.stringify(watchData),
            nonce: globalConfig.nonce,
            // security_token é o security_hash que veio do servidor, ele é o que assina os dados.
            security_token: securityToken
        };

        $.post(globalConfig.ajax_url, postData)
            .done(function (response) {
                if (response.success) {
                } else {
                    analyticsAlreadySent[videoKey] = false;
                }
            })
            .fail(function (xhr, status, error) {
                analyticsAlreadySent[videoKey] = false;
            });
    }

    function onPlayerReady(event, playerId, features) {
        const player = event.target;
        console.log(`[CONVERTTIZE-DEBUG] onPlayerReady disparado para player ${playerId}. Estado inicial: ${player.getPlayerState()}`);
        const container = document.getElementById(playerId + '_container');
        const overlay = container.querySelector('.ytp-sound-overlay');
        const progressBar = container.querySelector('.ytp-progress-bar');
        const playBtnCustom = container.querySelector('.ytp-play');
        const pauseBtnCustom = container.querySelector('.ytp-pause');
        const unmuteButtonOverlay = container.querySelector('.ytp-muted-autoplay-overlay');
        const ytpControls = container.querySelector('.ytp-controls'); // Get reference to the controls div


        // NEW: Create the click catcher overlay
        const ytpClickCatcher = document.createElement('div');
        ytpClickCatcher.className = 'ytp-click-catcher-overlay';
        container.appendChild(ytpClickCatcher);


        const playerData = window['ytpData_' + playerId];
        if (playerData && playerData.colors) {
            applyColorsToPlayer(playerId, playerData.colors);
        }

        playersState[playerId] = {
            player: player,
            duration: 0,
            intervalId: null,
            popupVisible: false,
            hidePopupTimeoutId: null,
            videoStarted: true,
            delayedItemsState: [],
            unlockHandlerCalled: false,
            wasPausedByVisibilityChange: false,
            initialAutoplayMuted: false
        };

        viewData[playerId] = {
            videoId: features.video_id || (window['ytpData_' + playerId] ? window['ytpData_' + playerId].video_id : null),
            watchedTime: 0,
            lastTime: 0,
            interval: null,
            lastTimeForDelta: 0
        };

        const thisPlayerWantsToUnlock = (typeof features.unlock_after !== 'undefined' && features.unlock_after > 0) ||
            (features.delayed_items && features.delayed_items.some(item => item.time > 0));

        if (thisPlayerWantsToUnlock) {
            if (pageHasActiveUnlockController === null) {
                pageHasActiveUnlockController = playerId;
                console.log(`[CONVERTTIZE] Player ${playerId} designado como controlador de desbloqueio para esta página.`);
            } else if (pageHasActiveUnlockController !== playerId) {
                console.warn(`[CONVERTTIZE] Desbloqueio de conteúdo desativado para o Player ${playerId}. Apenas um player por página pode controlar o desbloqueio. Player ${pageHasActiveUnlockController} já está ativo.`);
            }
        }

        try {
            player.unloadModule("captions");
            player.setOption("captions", "track", {});
        }
        catch (e) {
            console.warn(`[CONVERTTIZE] Erro ao tentar manipular legendas: ${e.message}. Verifique a API do YouTube.`);
        }


        const iframe = container.querySelector('.ytp-iframe iframe');
        // Oculta completamente o iframe e o torna não clicável quando um overlay for exibido
        // Será re-ativado pelo código do overlay ao ser clicado.
        if (iframe) {
            iframe.style.visibility = 'visible'; // Default visibility
            iframe.style.pointerEvents = 'auto'; // Default to auto
        }


        // --- INÍCIO DA LÓGICA DE CONTROLE DE INTERAÇÕES ---
        // Desanexa os listeners inicialmente para prevenir interação enquanto o overlay está presente
        // Passa as referências dos elementos que podem ter listeners.
        detachPlayerListeners(container, playBtnCustom, pauseBtnCustom);


        // No bloco que exibe o Sound Overlay:
        if (overlay && features.enable_sound_overlay) { // Sound Overlay está ativo e habilitado
            player.mute();
            overlay.style.display = 'flex'; // Show visible overlay
            ytpClickCatcher.style.display = 'block'; // Show invisible click catcher

            // Oculta controles customizados E torna o iframe não clicável
            if (ytpControls) {
                ytpControls.style.display = 'none'; // Hide the play/pause buttons
            }
            if (iframe) {
                iframe.style.pointerEvents = 'none'; // Make iframe unclickable
            }

            console.log(`[CONVERTTIZE-DEBUG] Overlay visível. Estado atual (onPlayerReady): ${player.getPlayerState()}`);

            if (player.getPlayerState() === -1) {
                console.warn('[CONVERTTIZE-DEBUG] Player em estado UNSTARTED. Tentando comando playVideo() atrasado.');
                setTimeout(() => {
                    player.playVideo();
                    console.log(`[CONVERTTIZE-DEBUG] Player estado após playVideo() atrasado: ${player.getPlayerState()}`);
                }, 10);
            }

            // Click listener on the invisible click catcher
            ytpClickCatcher.addEventListener('click', (event) => {
                event.stopPropagation();
                console.log(`[CONVERTTIZE-OVERLAY] Clique no overlay para player ${playerId} detectado.`);

                container.querySelector('.converttize-runtime-error')?.remove();
                // iframe.style.visibility já está 'visible' por padrão, apenas reativa pointer-events.
                if (iframe) {
                    iframe.style.pointerEvents = 'auto'; // Make iframe clickable again
                }

                player.seekTo(0);
                player.unMute();

                let checkAttempts = 0;
                const maxCheckAttempts = 20; // Check for up to 2 seconds (20 * 100ms)
                const checkInterval = setInterval(() => {
                    const state = player.getPlayerState();
                    console.log(`[CONVERTTIZE-OVERLAY] Check attempt 1: Player state is ${state}`);

                    if (state === YT.PlayerState.PLAYING) {
                        clearInterval(checkInterval);
                        overlay.style.display = 'none'; // Hide visible overlay
                        ytpClickCatcher.style.display = 'none'; // Hide invisible click catcher
                        // Mostra controles customizados
                        if (ytpControls) {
                            ytpControls.style.display = 'flex'; // Show the play/pause buttons
                        }
                        updatePlayPauseButtons(container, true, features);
                        console.log(`[CONVERTTIZE-OVERLAY] Vídeo ${playerId} iniciado com sucesso.`);
                        // Re-habilita interações do player após o overlay ser ocultado, passando as referências
                        attachPlayerListeners(player, container, features, playBtnCustom, pauseBtnCustom);
                    } else if (checkAttempts >= maxCheckAttempts) {
                        clearInterval(checkInterval);
                        console.warn(`[CONVERTTIZE-OVERLAY] Falha ao iniciar vídeo ${playerId}. Estado final: ${state}.`);
                        displayPlayerRuntimeError(playerId, "Falha ao iniciar o vídeo.", `O player não respondeu ao clique. Estado final: ${state}. Tente recarregar. (Erro: API_CLICK_FAIL_0)`, "API_CLICK_FAIL_0");
                    }
                    checkAttempts++;
                }, 100);
            }, { once: true }); // Use { once: true } to remove listener after first click
        }
        // Bloco que exibe o Overlay de Autoplay Mutado (se o Sound Overlay estiver desativado)
        else if (unmuteButtonOverlay && !features.enable_sound_overlay) { // Overlay de Autoplay Mutado ativo (sound overlay desativado)
            player.mute();
            playersState[playerId].initialAutoplayMuted = true;
            // Oculta controles customizados E torna o iframe não clicável
            if (ytpControls) {
                ytpControls.style.display = 'none'; // Hide the play/pause buttons
            }
            if (iframe) {
                iframe.style.pointerEvents = 'none'; // Make iframe unclickable
            }

            console.log(`[CONVERTTIZE-DEBUG] Tentando playVideo() mutado para player ${playerId}. Estado ANTES da chamada: ${player.getPlayerState()}`);
            player.playVideo();
            console.log(`[CONVERTTIZE-DEBUG] player.playVideo() chamado para player ${playerId}`);
            updatePlayPauseButtons(container, false, features);

            unmuteButtonOverlay.style.display = 'flex'; // Show visible overlay
            ytpClickCatcher.style.display = 'block'; // Show invisible click catcher

            if (!ytpClickCatcher.hasAttribute('data-listener-added')) { // Attach listener to click catcher
                ytpClickCatcher.addEventListener('click', (event) => {
                    event.stopPropagation();
                    player.seekTo(0);
                    player.unMute();
                    player.playVideo();
                    playersState[playerId].initialAutoplayMuted = false;
                    unmuteButtonOverlay.style.display = 'none'; // Hide visible overlay
                    ytpClickCatcher.style.display = 'none'; // Hide invisible click catcher
                    // Mostra controles customizados
                    if (ytpControls) {
                        ytpControls.style.display = 'flex'; // Show the play/pause buttons
                    }
                    // iframe.style.visibility já está 'visible' por padrão, apenas reativa pointer-events.
                    if (iframe) {
                        iframe.style.pointerEvents = 'auto'; // Make iframe clickable again
                    }
                    updatePlayPauseButtons(container, true, features);
                    // Re-habilita interações do player após o overlay ser ocultado, passando as referências
                    attachPlayerListeners(player, container, features, playBtnCustom, pauseBtnCustom);
                }, { once: true }); // Use { once: true }
                ytpClickCatcher.setAttribute('data-listener-added', 'true');
            }
        }
        // Bloco para quando nenhum overlay é necessário (player ativo desde o início)
        else {
            // Habilita interações do player imediatamente, passando as referências
            attachPlayerListeners(player, container, features, playBtnCustom, pauseBtnCustom);
            // Ensure controls are visible by default if no overlay is needed
            if (ytpControls) {
                ytpControls.style.display = 'flex'; // Make sure they are visible
            }
            // iframe.style.visibility já está 'visible' por padrão, apenas reativa pointer-events.
            if (iframe) {
                iframe.style.pointerEvents = 'auto'; // Make iframe clickable again
            }
            ytpClickCatcher.style.display = 'none'; // Ensure click catcher is hidden
        }
        // --- FIM DA LÓGICA DE CONTROLE DE INTERAÇÕES ---


        if (playBtnCustom) playBtnCustom.title = features.play_button_title || 'Play';
        if (pauseBtnCustom) pauseBtnCustom.title = features.pause_button_title || 'Pause';


        if (progressBar && features.enable_progress_bar) {
            progressBar.style.display = 'block';

            if (features.enable_progress_bar_seek) {
                progressBar.closest('.ytp-progress').addEventListener('click', function(e) {
                    const rect = this.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    const percentage = clickX / rect.width;
                    const seekTime = percentage * player.getDuration();
                    player.seekTo(seekTime, true);
                });
            }
        }

        container.addEventListener('keydown', event => {
            if (event.code === 'Space' || event.key === 'k' || event.key === 'K' ||
                event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        container.addEventListener('dblclick', event => {
            event.preventDefault();
            event.stopPropagation();
        }, true);

        if (pageHasActiveUnlockController === playerId) {
            initializeDelayedItems(playerId, container, features);
        }

        playersState[playerId].intervalId = setInterval(() => {
            if (!playersState[playerId] || !playersState[playerId].player ||
                typeof playersState[playerId].player.getCurrentTime !== 'function') {
                return;
            }

            const currentTime = player.getCurrentTime();
            const duration = player.getDuration();

            if (progressBar && features.enable_progress_bar) {
                // AQUI É A MUDANÇA PRINCIPAL PARA A BARRA DE PROGRESSO PERCEPTUAL!
                const perceivedProgressRatio = getPerceivedProgress(currentTime, duration);
                const displayPercent = perceivedProgressRatio * 100;

                progressBar.style.width = `${displayPercent.toFixed(3)}%`;
            }

            if (pageHasActiveUnlockController === playerId) {
                checkDelayedItems(playerId, currentTime, container);

                if (typeof features.unlock_after !== 'undefined' && typeof checkUnlockButton === 'function') {
                    checkUnlockButton(playerId, currentTime, features.unlock_after,
                                    features.unlock_selector, features.unlock_display_style);
                }
            }
        }, 100);
    }

    // NOVO MÉTODO: Trata erros específicos do player do YouTube
    function onPlayerError(event, playerId) {
        console.error(`[CONVERTTIZE-RUNTIME-ERROR] Erro no player ${playerId}:`, event.data);
        let userMessage = "O vídeo não pôde ser carregado.";
        let detailedError = "Um erro inesperado ocorreu.";

        switch(event.data) {
            case 2:
                userMessage = "O ID do vídeo é inválido.";
                detailedError = "Verifique se o ID do vídeo (ou a URL) está correto.";
                break;
            case 5:
                userMessage = "Erro no player HTML5.";
                detailedError = "Pode ser um problema temporário com o navegador ou o YouTube. Tente recarregar a página.";
                break;
            case 100:
                userMessage = "Vídeo não encontrado.";
                detailedError = "O vídeo pode ter sido removido, é privado ou não existe.";
                break;
            case 101:
            case 150:
                userMessage = "Vídeo indisponível para incorporação.";
                detailedError = "O proprietário do vídeo não permite a reprodução em outros sites.";
                break;
            default:
                userMessage = "Erro desconhecido ao carregar o vídeo.";
                detailedError = `Código: ${event.data}. Tente recarregar ou contate o suporte.`;
                break;
        }
        displayPlayerRuntimeError(playerId, userMessage, detailedError, event.data);
    }

    // NOVO MÉTODO: Exibe o overlay de erro em tempo de execução para um player específico
    function displayPlayerRuntimeError(playerId, message, detailedMessage, errorCode) {
        const $wrapper = $(`#${playerId}_container`);
        if (!$wrapper.length) {
            console.warn(`[CONVERTTIZE-ERROR] Wrapper para o player ${playerId} não encontrado para exibir erro.`);
            return;
        }

        if ($wrapper.find('.converttize-runtime-error').length > 0) {
            return;
        }

        const errorDiv = `
            <div class="converttize-runtime-error" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; z-index: 1000; padding: 20px; box-sizing: border-box;">
                <h3 style="margin: 0 0 10px; font-size: 1.2em;">${message}</h3>
                <p style="margin: 0 0 15px; font-size: 0.9em;">${detailedMessage}</p>
                <button onclick="window.location.reload();" style="padding: 10px 20px; background: #e74c3c; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1em;">
                    Recarregar Página
                </button>
                <p style="font-size: 0.7em; margin-top: 10px; opacity: 0.7;">Código: ${errorCode}</p>
            </div>
        `;
        $wrapper.append(errorDiv);

        $wrapper.find('.ytp-iframe').css('visibility', 'hidden');
    }


    function onPlayerStateChange(event, playerId) {
        const player = players[playerId];
        const container = document.getElementById(playerId + '_container');
          console.log(`[CONVERTTIZE-DEBUG] Player ${playerId} - Mudança de estado para: ${event.data}`);
        const state = playersState[playerId];
        const currentViewData = viewData[playerId];
        const playerData = window['ytpData_' + playerId];
        const features = playerData ? playerData.features : {};


        if (!state || !player) return;

        if (event.data === YT.PlayerState.PLAYING) {
            updatePlayPauseButtons(container, true, features);

            if (state.popupVisible) {
                hideReplayPopup(container, playerId, null);
                if (features.enable_ended_overlay) hideVideoEndedOverlay(container, playerId);
            }

            if (features.enable_ended_overlay) {
                const endedOverlay = container.querySelector('.ytp-video-ended-overlay');
                if (endedOverlay) {
                    endedOverlay.style.display = 'none';
                    endedOverlay.classList.remove('visible');
                }
            }

            if (currentViewData && !currentViewData.interval) {
                currentViewData.lastTime = player.getCurrentTime();
                currentViewData.lastTimeForDelta = currentViewData.lastTime;

                currentViewData.interval = setInterval(() => {
                    if (player.getPlayerState() === YT.PlayerState.PLAYING) {
                        const currentTime = player.getCurrentTime();
                        const delta = currentTime - currentViewData.lastTime;

                        if (delta > 0 && delta < 5) {
                            currentViewData.watchedTime += delta;
                        }
                        currentViewData.lastTime = currentTime;
                        currentViewData.lastTimeForDelta = currentTime;
                    }
                }, 500);
            }

        } else if (event.data === YT.PlayerState.PAUSED) {
            updatePlayPauseButtons(container, false, features);

            if (currentViewData && currentViewData.interval) {
                const currentTime = player.getCurrentTime();
                const delta = currentTime - currentViewData.lastTime;
                if (delta > 0 && delta < 5) {
                    currentViewData.watchedTime += delta;
                }
                currentViewData.lastTime = currentTime;
                currentViewData.lastTimeForDelta = currentTime;

                clearInterval(currentViewData.interval);
                currentViewData.interval = null;
            }

            if (state.videoStarted && !state.popupVisible && !state.wasPausedByVisibilityChange) {
                showReplayPopup(container, playerId);
            }

        } else if (event.data === YT.PlayerState.ENDED) {
            console.log(`[CONVERTTIZE DEBUG] Player ${playerId} reached ENDED state.`);
            updatePlayPauseButtons(container, false, features);

            const soundOverlay = container.querySelector('.ytp-sound-overlay');
            if (soundOverlay) {
                soundOverlay.style.display = 'none';
            }

            if (currentViewData) {
                const currentTime = player.getCurrentTime();
                const duration = player.getDuration();

                if (duration > 0 && currentTime >= (duration - 1)) {
                    const totalWatchTime = Math.max(currentViewData.watchedTime, duration);
                    currentViewData.watchedTime = totalWatchTime;
                } else {
                    const delta = currentTime - currentViewData.lastTime;
                    if (delta > 0 && delta < 5) {
                        currentViewData.watchedTime += delta;
                    }
                }

                if (currentViewData.interval) {
                    clearInterval(currentViewData.interval);
                    currentViewData.interval = null;
                }
            }

            console.log(`[CONVERTTIZE DEBUG] features.enable_ended_overlay: ${features.enable_ended_overlay}`);
            console.log(`[CONVERTTIZE DEBUG] container.querySelector('.ytp-video-ended-overlay'): ${container.querySelector('.ytp-video-ended-overlay') ? 'found' : 'not found'}`);
            console.log(`[CONVERTTIZE DEBUG] state.videoStarted: ${state.videoStarted}`);

            if (features.enable_ended_overlay && container.querySelector('.ytp-video-ended-overlay') && state.videoStarted) {
                console.log(`[CONVERTTIZE DEBUG] All conditions met for showVideoEndedOverlay for player ${playerId}`);
                showVideoEndedOverlay(container, playerId);
            } else {
                console.log(`[CONVERTTIZE DEBUG] showVideoEndedOverlay not called for player ${playerId} due to unmet conditions.`);
            }

            sendAnalytics(currentViewData, player.getCurrentTime());
        }
    }

    function hideVideoEndedOverlay(container, playerId) {
        console.log(`[CONVERTTIZE DEBUG] Hiding video ended overlay for player ${playerId}`);
        const endedOverlay = container.querySelector('.ytp-video-ended-overlay');
        if (endedOverlay) {
            endedOverlay.style.display = 'none';
            endedOverlay.classList.remove('visible');
        }

        const state = playersState[playerId];
        if (state) {
            state.popupVisible = false;
        }
    }

    function showVideoEndedOverlay(container, playerId) {
        console.log(`[CONVERTTIZE DEBUG] Showing video ended overlay for player ${playerId}`);
        const state = playersState[playerId];
        if (!state) {
            return;
        }

        let endedOverlay = container.querySelector('.ytp-video-ended-overlay');
        if (!endedOverlay) {
            return;
        }

        const soundOverlay = container.querySelector('.ytp-sound-overlay');
        if (soundOverlay) {
            soundOverlay.style.display = 'none';
        }

        const replayPopup = container.querySelector('.ytp-replay-popup');
        if (replayPopup) {
            replayPopup.style.display = 'none';
        }

        const playerData = window['ytpData_' + playerId];
        if (playerData && playerData.colors && playerData.features) {
            endedOverlay.style.background = '#000000 !important';
            endedOverlay.style.color = playerData.colors.text_color || '#ffffff';

            const endedMessageElement = endedOverlay.querySelector('.ytp-ended-message');
            if (endedMessageElement) endedMessageElement.textContent = playerData.features.ended_overlay_message || 'Vídeo finalizado';

            const endedButtons = endedOverlay.querySelectorAll('.ytp-ended-btn');
            endedButtons.forEach(btn => {
                btn.style.background = playerData.colors.primary_color || '#ff9500';
                btn.style.color = playerData.colors.text_color || '#ffffff';
                if (btn.getAttribute('data-action') === 'replay') {
                    btn.textContent = playerData.features.ended_overlay_replay_button || 'Assistir novamente';
                }
            });
        }

        if (!endedOverlay.hasAttribute('data-listener-added')) {
            endedOverlay.addEventListener('click', (e) => {
                const button = e.target.closest('button[data-action]');
                if (!button || !playersState[playerId]) return;

                e.preventDefault();
                e.stopPropagation();

                const action = button.getAttribute('data-action');

                if (action === 'replay') {
                    hideVideoEndedOverlay(container, playerId);
                    playersState[playerId].player.seekTo(0);
                    playersState[playerId].player.playVideo();
                }
            });

            endedOverlay.setAttribute('data-listener-added', 'true');
        }

        endedOverlay.style.display = 'flex';
        endedOverlay.style.position = 'absolute';
        endedOverlay.style.top = '0';
        endedOverlay.style.left = '0';
        endedOverlay.style.width = '100%';
        endedOverlay.style.height = '100%';
        endedOverlay.style.background = '#000000';
        endedOverlay.style.zIndex = '9999';
        endedOverlay.style.justifyContent = 'center';
        endedOverlay.style.alignItems = 'center';
        endedOverlay.style.flexDirection = 'column';

        endedOverlay.offsetHeight;
        setTimeout(() => { endedOverlay.classList.add('visible'); }, 100);
        state.popupVisible = true;
        console.log(`[CONVERTTIZE DEBUG] Overlay should now be visible for player ${playerId}.`);
    }

    /**
     * Atualiza a visibilidade dos botões de Play/Pause customizados.
     * @param {HTMLElement} container - O container do player.
     * @param {boolean} isPlaying - True se o player está tocando.
     * @param {object} features - As configurações de features do player.
     */
    function updatePlayPauseButtons(container, isPlaying, features) {
        const playBtn = container.querySelector('.ytp-play');
        const pauseBtn = container.querySelector('.ytp-pause');
        const state = playersState[container.id.replace('_container', '')];

        if (!features.enable_play_pause_buttons || !playBtn || !pauseBtn || !state || !state.videoStarted) {
            if (playBtn) playBtn.style.display = 'none';
            if (pauseBtn) pauseBtn.style.display = 'none';
            return;
        }

        if (state.initialAutoplayMuted) {
            playBtn.style.display = 'inline-block';
            pauseBtn.style.display = 'none';
        } else {
            playBtn.style.display = isPlaying ? 'none' : 'inline-block';
            pauseBtn.style.display = isPlaying ? 'inline-block' : 'none';
        }
    }

    // --- MODIFICADO: initializeDelayedItems ---
    function initializeDelayedItems(playerId, container, features) {
        const configItems = features && features.delayed_items && Array.isArray(features.delayed_items)
            ? features.delayed_items
            : [];

        playersState[playerId].delayedItemsState = configItems.map(item => ({
            config: item,
            shown: false,
            videoBlurredByThisItem: false
        }));

        playersState[playerId].delayedItemsState.forEach(itemState => {
            if (!itemState.config || !itemState.config.selector) return;

            const elements = document.querySelectorAll(itemState.config.selector);
            elements.forEach(el => {
                el.style.display = 'none';
                el.style.opacity = '0';
                el.style.visibility = 'hidden';
                el.style.height = '0px';
                el.style.overflow = 'hidden';
                el.style.pointerEvents = 'none';

                if (itemState.config.class_to_add) {
                    el.classList.remove(itemState.config.class_to_add);
                }

                el.dataset.converttizeDelayedItemProcessed = 'false';
            });
        });
    }

    // --- MODIFICADO: checkDelayedItems ---
    function checkDelayedItems(playerId, currentTime, container) {
        if (!playersState[playerId] || !playersState[playerId].delayedItemsState || !Array.isArray(playersState[playerId].delayedItemsState)) return;

        const videoIframe = container.querySelector('.ytp-iframe iframe');
        const player = playersState[playerId].player;

        const isPlayingWithSound = (player && player.getPlayerState() === YT.PlayerState.PLAYING && !player.isMuted());

        playersState[playerId].delayedItemsState.forEach(itemState => {
            if (!itemState.config || typeof itemState.config.time === 'undefined' || typeof itemState.config.selector === 'undefined') {
                return;
            }

            if (itemState.shown) {
                return;
            }

            if (isPlayingWithSound && currentTime >= parseFloat(itemState.config.time)) {
                const elementsToProcess = document.querySelectorAll(itemState.config.selector);

                if (elementsToProcess.length === 0) {
                    itemState.shown = true;
                    return;
                }

                elementsToProcess.forEach(el => {
                    if (el.dataset.converttizeDelayedItemProcessed === 'true') {
                        return;
                    }
                    el.dataset.converttizeDelayedItemProcessed = 'true';

                    if (itemState.config.type === 'activate_element_by_class') {
                        el.style.removeProperty('display');
                        el.style.removeProperty('opacity');
                        el.style.removeProperty('visibility');
                        el.style.removeProperty('height');
                        el.style.removeProperty('overflow');
                        el.style.pointerEvents = 'none';

                        if (itemState.config.class_to_add) {
                            el.classList.add(itemState.config.class_to_add);
                        }
                    }
                    else {
                        if (itemState.config.html_content) {
                            el.innerHTML = itemState.config.html_content;
                        }

                        el.style.display = itemState.config.display_style || 'block';
                        el.style.opacity = '1';
                        el.style.visibility = 'visible';
                        el.style.height = 'auto';
                        el.style.overflow = 'visible';
                        el.style.pointerEvents = 'auto';

                        el.classList.add('is-visible');

                        switch (itemState.config.type) {
                            case 'normal':
                                break;
                            case 'unblur_self_on_appear':
                                el.style.filter = 'none';
                                break;
                            case 'blur_video_on_appear':
                                if (videoIframe && itemState.config.blur_amount) {
                                    const isVideoAlreadyBlurredByOther = playersState[playerId].delayedItemsState.some(
                                        s => s.videoBlurredByThisItem && s.config.id !== itemState.config.id
                                    );
                                    if (!isVideoAlreadyBlurredByOther) {
                                        videoIframe.style.transition = 'filter 0.3s ease-out';
                                        videoIframe.style.filter = `blur(${itemState.config.blur_amount}px)`;
                                        itemState.videoBlurredByThisItem = true;

                                        if (itemState.config.unblur_selector) {
                                            const unblurTrigger = el.querySelector(itemState.config.unblur_selector);
                                            if (unblurTrigger) {
                                                const newUnblurTrigger = unblurTrigger.cloneNode(true);
                                                unblurTrigger.parentNode.replaceChild(newUnblurTrigger, unblurTrigger);

                                                newUnblurTrigger.addEventListener('click', () => {
                                                    if (itemState.videoBlurredByThisItem) {
                                                        videoIframe.style.filter = 'none';
                                                        itemState.videoBlurredByThisItem = false;
                                                    }
                                                    const gatedContentSections = document.querySelectorAll('.gated-content-section');
                                                    if (gatedContentSections.length > 0) {
                                                        gatedContentSections.forEach(section => {
                                                            section.classList.add('is-visible');
                                                            section.style.opacity = '1';
                                                            section.style.visibility = 'visible';
                                                            section.style.display = 'block';
                                                            section.style.height = 'auto';
                                                            section.style.overflow = 'visible';
                                                            section.style.pointerEvents = 'auto';
                                                        });
                                                    }
                                                    el.style.display = 'none';
                                                }, { once: true });
                                            }
                                        }
                                    }
                                }
                                break;
                        }
                    }
                });

                itemState.shown = true;
            }
        });
    }

    function showReplayPopup(container, playerId) {
        const state = playersState[playerId];
        if (!state) return;

        const playerData = window['ytpData_' + playerId];

        if (state.hidePopupTimeoutId) {
            clearTimeout(state.hidePopupTimeoutId);
            state.hidePopupTimeoutId = null
            const tempPopup = container.querySelector('.ytp-replay-popup');
            if (tempPopup) {
                tempPopup.style.transition = 'none';
                tempPopup.classList.remove('fade-out');
                tempPopup.style.opacity = '1';
                tempPopup.style.display = 'flex';
            }
        }

        let popup = container.querySelector('.ytp-replay-popup');
        if (!popup) {
            popup = document.createElement('div');
            popup.className = 'ytp-replay-popup';
            if (playerData && playerData.features) {
                popup.innerHTML = `
                    <div style="ytp-replay-content">
                        <div style="ytp-replay-text">
                            <p>${playerData.features.paused_message || 'Vídeo pausado.'}</p>
                            <div style="ytp-replay-buttons">
                                <button style="ytp-replay-btn" data-action="continue">${playerData.features.paused_continue_button || 'Continuar'}</button>
                                <button style="ytp-startover-btn" data-action="restart">${playerData.features.paused_restart_button || 'Começar do início'}</button>
                            </div>
                        </div>
                    </div>
                `;
            }
            container.appendChild(popup);

            popup.addEventListener('click', (e) => {
                const button = e.target.closest('button[data-action]');
                if (!button || !playersState[playerId]) return;

                e.preventDefault();
                e.stopPropagation();

                const action = button.getAttribute('data-action');

                if (playersState[playerId]) playersState[playerId].wasPausedByVisibilityChange = false;

                hideReplayPopup(container, playerId, () => {
                    if (!playersState[playerId]) return;
                    if (action === 'continue') playersState[playerId].player.playVideo();
                    else if (action === 'restart') { playersState[playerId].player.seekTo(0); playersState[playerId].player.playVideo(); }
                });
            });
        }

        popup.style.transition = 'none';
        popup.classList.remove('fade-out');
        popup.style.opacity = '1';
        popup.style.display = 'flex';
        state.popupVisible = true;
    }

    function hideReplayPopup(container, playerId, onHiddenCallback) {
        const state = playersState[playerId];
        const popup = container.querySelector('.ytp-replay-popup');

        if (popup) {
            popup.style.display = 'none';
            popup.style.opacity = '0';
            popup.classList.remove('fade-out');
        }

        if (state) {
            state.popupVisible = false;
            if (state.hidePopupTimeoutId) {
                clearTimeout(state.hidePopupTimeoutId);
                state.hidePopupTimeoutId = null;
            }
        }

        if (typeof onHiddenCallback === 'function') {
            onHiddenCallback();
        }
    }

    function checkUnlockButton(playerId, currentTime, unlockTime, unlockSelector, unlockDisplayStyle) {
        const state = playersState[playerId];
        if (!state || state.unlockHandlerCalled || typeof unlockTime !== 'number') return;

        if (currentTime >= unlockTime) {
            const container = document.getElementById(playerId + '_container');
            const btn = container.querySelector(unlockSelector || '.ytp-unlock-button-placeholder');
            if (btn) {
                btn.style.display = unlockDisplayStyle || 'block';
                state.unlockHandlerCalled = true;
            } else {
                state.unlockHandlerCalled = true;
            }
        }
    }

    window.addEventListener('beforeunload', function () {
        for (const playerId in viewData) {
            if (playersState[playerId] && playersState[playerId].player && typeof playersState[playerId].player.getCurrentTime === 'function') {
                const player = playersState[playerId].player;
                const currentViewData = viewData[playerId];
                const playerCurrentTime = player.getCurrentTime();
                const videoKey = currentViewData.videoId;

                if (!analyticsAlreadySent[videoKey] && (licenseStatus === 'active' || licenseStatus === 'trial')) {
                    if (player.getPlayerState() === YT.PlayerState.PLAYING && currentViewData.lastTimeForDelta) {
                        const delta = playerCurrentTime - currentViewData.lastTimeForDelta;
                        if (delta > 0 && delta < 5) {
                            currentViewData.watchedTime += delta;
                        }
                    }
                    sendAnalytics(currentViewData, playerCurrentTime);
                }
            }
        }

        for (const playerId in playersState) {
            if (playersState[playerId].intervalId) {
                clearInterval(playersState[playerId].intervalId);
                playersState[playerId].intervalId = null;
            }
            if (playersState[playerId].hidePopupTimeoutId) {
                clearTimeout(playersState[playerId].hidePopupTimeoutId);
                playersState[playerId].hidePopupTimeoutId = null;
            }
            if (viewData[playerId] && viewData[playerId].interval) {
                clearInterval(viewData[playerId].interval);
                viewData[playerId].interval = null;
            }
            delete playersState[playerId];
            delete players[playerId];
            delete viewData[playerId];
        }
    });

    function handleVisibilityChange() {
        for (const playerId in players) {
            if (players.hasOwnProperty(playerId) && playersState[playerId] && playersState[playerId].player) {
                const player = playersState[playerId].player;
                try {
                    if (document.hidden) {
                        if (player.getPlayerState() === YT.PlayerState.PLAYING) {
                            player.pauseVideo();
                            playersState[playerId].wasPausedByVisibilityChange = true;
                        }
                    } else {
                        if (playersState[playerId].wasPausedByVisibilityChange && !playersState[playerId].popupVisible) {
                            player.playVideo();
                        }
                        playersState[playerId].wasPausedByVisibilityChange = false;
                    }
                } catch (e) {}
           }
        }
    }
    if (typeof document.addEventListener !== "undefined" && typeof document.hidden !== "undefined") {
        document.addEventListener("visibilitychange", handleVisibilityChange, false);
    }

    document.addEventListener('lumePlayerOptionsChanged', function (e) {
        const options = e.detail;

        document.querySelectorAll('.ytp-wrapper').forEach(wrapper => {
            const playerId = wrapper.id.replace('_container', '');

            const playerData = window['ytpData_' + playerId];
            if (playerData && playerData.features) {
                const playBtn = wrapper.querySelector('.ytp-play');
                const pauseBtn = wrapper.querySelector('.ytp-pause');
                if (playBtn) playBtn.title = options.play_button_title || 'Play';
                if (pauseBtn) pauseBtn.title = options.pause_button_title || 'Pause';

                const soundOverlayMessage = wrapper.querySelector('.ytp-sound-message');
                const soundOverlayClick = wrapper.querySelector('.ytp-sound-click');
                if (soundOverlayMessage) soundOverlayMessage.textContent = options.sound_overlay_message || 'Seu vídeo já começou';
                if (soundOverlayClick) soundOverlayClick.textContent = options.sound_overlay_click || 'Clique para ouvir';

                const endedOverlayMessage = wrapper.querySelector('.ytp-ended-message');
                const endedReplayBtn = wrapper.querySelector('.ytp-ended-btn[data-action="replay"]');
                if (endedOverlayMessage) endedOverlayMessage.textContent = options.ended_overlay_message || 'Vídeo finalizado';
                if (endedReplayBtn) endedReplayBtn.textContent = options.ended_overlay_replay_button || 'Assistir novamente';
            }

            applyColorsToPlayer(playerId, {
                primary_color: options.primary_color,
                secondary_color: options.secondary_color,
                progress_color: options.progress_color,
                text_color: options.text_color,
                overlay_bg: options.overlay_bg
            });
        });
    });

    $(document).ready(function () {
        console.log('[CONVERTTIZE-INIT] DOM Ready disparado.');
        domReady = true;
        youtubeApiPollInterval = setInterval(pollForYoutubeApi, 100);
        attemptPlayerInitialization();
    });

})(jQuery);
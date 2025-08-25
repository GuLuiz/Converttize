// admin.js (Para o Painel Administrativo do WordPress)

(function ($) {
    $(document).ready(function () {
        // Objeto de configuração global passado via wp_localize_script (do PHP)
        const adminConfig = window.lumePlayerAdmin;

        // Elementos comuns
        const $delayedItemsList = $('#lume-delayed-items-list');
        const $delayedItemsDataInput = $('#lume-delayed-items-data');
        const delayedItemTemplate = wp.template('lume-delayed-item'); // Template do Underscore.js
        
        const saveButton = $('#lume-save-settings-btn'); // Botão de salvamento
        const saveStatusDiv = $('#save-status'); // Div de status de salvamento
        const $addDelayedItemButton = $('#lume-add-delayed-item'); // NOVO: Botão de adicionar item de delay

        let itemIndex = 0; // Usado para IDs únicos de itens de delay, se não vierem do banco

        // --- INÍCIO DA FUNÇÃO DE COPIAR HTML ---
        // É importante que essa função esteja no escopo global (window) para ser acessível pelo onclick no HTML.
        window.copyConverttizeHtmlCode = function() {
            const codeElement = document.getElementById('converttize-html-code');
            const statusSpan = document.getElementById('converttize-html-copy-status');
            
            if (codeElement) {
                const textToCopy = codeElement.innerText; // Pega o texto visível dentro da tag <code>
                
                // Tenta usar a Clipboard API (método moderno e preferível)
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(() => {
                        if (statusSpan) {
                            statusSpan.style.display = 'inline-block';
                            setTimeout(() => {
                                statusSpan.style.display = 'none';
                            }, 2000); // Esconde a mensagem "Copiado!" após 2 segundos
                        }
                    }).catch(err => {
                        console.error('Falha ao copiar usando Clipboard API:', err);
                        // Fallback para document.execCommand se a API falhar
                        fallbackCopyTextToClipboard(textToCopy, statusSpan);
                    });
                } else {
                    // Fallback para navegadores mais antigos que não suportam a Clipboard API
                    fallbackCopyTextToClipboard(textToCopy, statusSpan);
                }
            }
        };

        // Função de fallback para copiar texto para a área de transferência (para navegadores mais antigos)
        function fallbackCopyTextToClipboard(text, statusSpan) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            
            // Posiciona a área de texto fora da tela para que não seja visível para o usuário
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            textArea.style.top = "-999999px";
            
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select(); // Seleciona o texto dentro da área de texto

            try {
                // Tenta executar o comando de cópia
                const successful = document.execCommand('copy');
                if (successful) {
                    if (statusSpan) {
                        statusSpan.style.display = 'inline-block';
                        setTimeout(() => {
                            statusSpan.style.display = 'none';
                        }, 2000);
                    }
                } else {
                    console.error('Falha ao copiar usando document.execCommand');
                    alert('Erro ao copiar o código. Por favor, copie manualmente: ' + text); // Mensagem de fallback para o usuário
                }
            } catch (err) {
                console.error('Erro ao executar o comando de cópia: ', err);
                alert('Erro ao copiar o código. Por favor, copie manualmente: ' + text); // Mensagem de fallback para o usuário
            }

            // Remove a área de texto auxiliar do DOM
            document.body.removeChild(textArea);
        }
        // --- FIM DA FUNÇÃO DE COPIAR HTML ---


        // --- Funções de Inicialização e Helper ---

        // Inicializa os seletores de cor (wpColorPicker)
        function initializeColorPickers() {
            $('.color-picker').each(function() {
                const $this = $(this);
                const colorId = $this.attr('id');
                // Pega a cor inicial das opções carregadas pelo PHP
                const initialColor = adminConfig.options[colorId] || $this.data('default-color');
                $this.val(initialColor); // Define o valor inicial do input para o color picker

                $this.wpColorPicker({
                    palettes: adminConfig.palettes,
                    change: function(event, ui) {
                        // Ao mudar a cor, atualiza a prévia dinamicamente
                        const newOptionsForPreview = $.extend(true, {}, getFormOptions()); // Pega todas as opções atuais
                        // Certifica-se de que a cor é uma string, pois ui.color pode ser um objeto para RGBA
                        newOptionsForPreview[colorId] = ui.color.toString(); 
                        updatePreviewColors(newOptionsForPreview);
                    },
                    clear: function(event) {
                        // Ao limpar a cor, volta para o default e atualiza a prévia
                        const defaultColor = $(event.target).data('default-color');
                        const newOptionsForPreview = $.extend(true, {}, getFormOptions()); // Pega todas as opções atuais
                        newOptionsForPreview[colorId] = defaultColor; // Define a cor default
                        updatePreviewColors(newOptionsForPreview);
                    }
                });
            });
        }

        // Converte HEX para RGB (utilidade para o preview)
        function hexToRgb(hex) {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : null;
        }

        // Atualiza o preview do player com as opções de cor dadas
        function updatePreviewColors(optionsToPreview) {
            const previewWrapper = $('.admin-preview-ytp-wrapper');
            if (!previewWrapper.length) return;
            
            // Usar os valores das opções de preview, caindo para defaults se não existirem
            const primaryColor = optionsToPreview.primary_color || adminConfig.options.primary_color || '#ff9500';
            const secondaryColor = optionsToPreview.secondary_color || adminConfig.options.secondary_color || '#ff3300';
            const progressColor = optionsToPreview.progress_color || adminConfig.options.progress_color || '#ff9500';
            const textColor = optionsToPreview.text_color || adminConfig.options.text_color || '#ffffff';
            const overlayBg = optionsToPreview.overlay_bg || adminConfig.options.overlay_bg || 'rgba(0,0,0,0.75)';
            
            previewWrapper.css('--ytp-primary-color', primaryColor);
            previewWrapper.css('--ytp-secondary-color', secondaryColor);
            previewWrapper.css('--ytp-progress-color', progressColor);
            previewWrapper.css('--ytp-text-color', textColor);
            previewWrapper.css('--ytp-overlay-bg', overlayBg);
            
            const rgb = hexToRgb(primaryColor);
            if (rgb) {
                previewWrapper.css('--ytp-primary-color-rgb', `${rgb.r}, ${rgb.g}, ${rgb.b}`);
            }

            // Atualiza elementos específicos do preview
            $('.preview-element-overlay').css({ 'background-color': overlayBg, 'color': textColor });
            $('.preview-controls-example .preview-button').css({ 'background-color': primaryColor, 'color': textColor });
            $('.preview-controls-example .preview-button.secondary').css({ 'background-color': secondaryColor, 'color': textColor });
            $('.preview-progress-bar').css('background-color', progressColor);
        }

        // --- Lógica para Coletar Dados do Formulário ---

        // Coleta todos os dados do formulário, incluindo itens de delay
        function getFormOptions() {
            let options = {};
            // Itera sobre todos os inputs com name="options[alguma_chave]"
            $('#lume-player-settings-form :input[name^="options["]').each(function() {
                const $this = $(this);
                const nameAttr = $this.attr('name');
                // Extrai a chave (ex: 'fullscreen', 'primary_color')
                const key = nameAttr.substring(8, nameAttr.length - 1); 

                if (key === 'delayed_items_json') {
                    // O campo hidden 'delayed_items_json' é tratado separadamente,
                    // seu valor é o JSON de todos os itens de delay.
                    try {
                        options.delayed_items_json = $this.val(); // Armazena a string JSON
                    } catch (e) {
                        console.error('Erro ao processar JSON de itens com delay:', e);
                        options.delayed_items_json = '[]';
                    }
                } else if ($this.is(':checkbox')) {
                    options[key] = $this.is(':checked');
                } else {
                    options[key] = $this.val();
                }
            });
            return options;
        }

        // --- Lógica para Itens com Delay (Atrações) ---

        // Carrega e renderiza itens de delay existentes (na inicialização da página)
        function loadExistingDelayedItems() {
            // adminConfig.options.delayed_items é a array de objetos de itens com delay
            const existingItems = adminConfig.options.delayed_items || [];
            if (existingItems.length > 0) {
                // Encontra o maior ID numérico para continuar a sequência, se os IDs forem numéricos
                const maxId = Math.max(...existingItems.map(item => parseInt(item.id.replace('item_', '')) || 0));
                if (!isNaN(maxId)) {
                    itemIndex = maxId + 1;
                }
            }
            $delayedItemsList.empty(); // Limpa a lista antes de renderizar
            existingItems.forEach(item => {
                renderDelayedItem(item);
            });
            // NÂO CHAMA updateDelayedItemsData AQUI, pois já é chamado no final do ready para pegar o estado inicial
        }

        // Renderiza um único item de delay no DOM
        function renderDelayedItem(itemData = {}) {
            // Garante que o item tenha um ID único
            if (!itemData.id) {
                itemData.id = 'item_' + itemIndex++;
            }
            // Define valores padrão se não existirem
            itemData.selector = itemData.selector || '';
            itemData.time = itemData.time || 0;
            itemData.type = itemData.type || 'normal';
            itemData.html_content = itemData.html_content || '';
            itemData.display_style = itemData.display_style || 'block';
            itemData.position_css = itemData.position_css || '';
            itemData.blur_amount = itemData.blur_amount || 0;
            itemData.unblur_selector = itemData.unblur_selector || '';

            // Adiciona dados de exemplo HTML específicos para o template
            itemData.video_id_for_html_example = adminConfig.video_id_for_html_example;
            itemData.player_div_id_example = adminConfig.player_div_id_example;

            // Usa o template de underscore.js para criar o HTML
            const html = delayedItemTemplate(itemData);
            $delayedItemsList.append(html);

            // Configura os event listeners para o item recém-adicionado
            const newItemRow = $delayedItemsList.find(`[data-item-id="${itemData.id}"]`);
            setupDelayedItemListeners(newItemRow);
        }

        // Configura os event listeners para um item de delay (remover, tipo, input changes)
        function setupDelayedItemListeners(row) {
            // Botão "Remover Item"
            row.find('.lume-remove-delayed-item').on('click', function() {
                if (confirm(adminConfig.i18n.removeItem)) {
                    $(this).closest('.lume-delayed-item-row').remove();
                    updateDelayedItemsData(); // Atualiza os dados após remover
                }
            });

            // Atualiza a visibilidade das opções baseadas no tipo de ação
            row.find('.lume-item-type').on('change', function() {
                const selectedType = $(this).val();
                row.find('.lume-item-type-options .type-option').hide(); // Esconde todas as opções
                row.find(`.lume-item-type-options .type-${selectedType}`).show(); // Mostra apenas as da opção selecionada
                updateDelayedItemsData(); // Atualiza os dados após mudança
            }).trigger('change'); // Dispara no carregamento para aplicar a visibilidade inicial

            // Atualiza os dados ao digitar/mudar em qualquer input do item
            row.find('input, textarea, select').on('change keyup paste', function() {
                updateDelayedItemsData(); // Atualiza os dados após mudança
            });
        }

        // Coleta todos os dados dos itens com delay e os salva como uma string JSON em um campo oculto
        function updateDelayedItemsData() {
            let allItems = [];
            $delayedItemsList.find('.lume-delayed-item-row').each(function() {
                const $row = $(this);
                const item = {
                    id: $row.find('.lume-item-id').val(),
                    selector: $row.find('.lume-item-selector').val(),
                    time: parseInt($row.find('.lume-item-time').val()) || 0,
                    type: $row.find('.lume-item-type').val(),
                    html_content: $row.find('.lume-item-html-content').val(),
                    display_style: $row.find('.lume-item-display-style').val(),
                    position_css: $row.find('.lume-item-position-css').val(),
                    blur_amount: parseInt($row.find('.lume-item-blur-amount').val()) || 0,
                    unblur_selector: $row.find('.lume-item-unblur-selector').val()
                };
                allItems.push(item);
            });
            $delayedItemsDataInput.val(JSON.stringify(allItems));

            // NOVO: Controla a visibilidade do botão "Adicionar Nova Atração"
            if ($addDelayedItemButton.length && allItems.length >= 1) { // Verifica se o botão existe e se há 1 ou mais itens
                $addDelayedItemButton.hide();
            } else if ($addDelayedItemButton.length) { // Se o botão existe e não há itens
                $addDelayedItemButton.show();
            }
        }

        // --- Event Listeners Principais ---

        // Adiciona um novo item de delay
        $('#lume-add-delayed-item').on('click', function () {
            renderDelayedItem(); 
            updateDelayedItemsData(); // Atualiza os dados e a visibilidade do botão
        });

        // Evento de envio do formulário (agora via AJAX)
        $('#lume-player-settings-form').on('submit', function (e) {
            e.preventDefault(); // Impede o envio padrão do formulário

            // GARANTE QUE O CAMPO OCULTO COM OS DADOS DOS ITENS DE DELAY ESTEJA ATUALIZADO
            // Isso é crucial caso o usuário apenas digite sem interagir com outros campos
            // que acionariam o updateDelayedItemsData() automaticamente.
            updateDelayedItemsData(); 

            // Desabilita o botão e mostra status de salvamento
            saveButton.prop('disabled', true).text(adminConfig.i18n.saving);
            saveStatusDiv.empty().removeClass('notice notice-success notice-error notice-info').hide();

            // Coleta os dados do formulário
            const dataToSend = {
                action: 'lume_player_save_options', // Ação AJAX definida no PHP
                nonce: adminConfig.nonce, // Nonce de segurança
                options: getFormOptions() // Coleta todas as opções do formulário
            };
            
            // Se estiver editando um vídeo específico, adiciona o video_id
            if (adminConfig.is_video_specific_editing && adminConfig.current_video_id) {
                dataToSend.video_id = adminConfig.current_video_id;
            }

            // Envia a requisição AJAX
            $.ajax({
                url: adminConfig.ajax_url,
                type: 'POST',
                data: dataToSend,
                success: function (response) {
                    if (response.success) {
                        saveStatusDiv.addClass('notice notice-success is-dismissible').html(`<p>${response.data.message}</p>`).slideDown();
                        // Opcional: Atualiza o objeto adminConfig.options com os novos dados sanitizados e salvos
                        // Isso garante que o preview e os campos reflitam o estado salvo, caso o PHP altere algo
                        adminConfig.options = response.data.options;
                        updatePreviewColors(adminConfig.options); // Atualiza preview com os dados recém-salvos

                        // Dispara evento customizado para o player de preview (se existir na página)
                        // Este evento deve ser escutado pelo player.js (o frontend, que deve ter o preview do player)
                        const customEvent = new CustomEvent('lumePlayerOptionsChanged', { detail: adminConfig.options });
                        document.dispatchEvent(customEvent);

                    } else {
                        saveStatusDiv.addClass('notice notice-error is-dismissible').html(`<p>${response.data.message || adminConfig.i18n.saveError}</p>`).slideDown();
                    }
                },
                error: function (xhr, status, error) {
                    console.error("AJAX Error:", status, error, xhr.responseText);
                    saveStatusDiv.addClass('notice notice-error is-dismissible').html(`<p>${adminConfig.i18n.ajaxError} (${status}: ${error})</p>`).slideDown();
                },
                complete: function () {
                    // Restaura o botão e esconde a mensagem de status após um tempo
                    saveButton.prop('disabled', false).text(adminConfig.i18n.saveChanges);
                    setTimeout(() => saveStatusDiv.fadeOut(500), 5000); // Esconde após 5 segundos
                }
            });
        });

        // --- Inicialização da Página ---
        initializeColorPickers(); // Inicializa os seletores de cor
        updatePreviewColors(adminConfig.options); // Atualiza o preview com as opções carregadas inicialmente
        loadExistingDelayedItems(); // Carrega os itens com delay existentes
        updateDelayedItemsData(); // NOVO: Chama no carregamento para definir a visibilidade inicial do botão
    });
})(jQuery);
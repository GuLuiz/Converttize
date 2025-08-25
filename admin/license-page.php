<?php
// Processa ativação de licença
if (isset($_POST['activate_license'])) {
    $license_key = sanitize_text_field($_POST['license_key']);
    
    global $yt_license_manager;
    $result = $yt_license_manager->activate_license($license_key);
    
    if ($result['success']) echo '<div class="notice notice-success"><p>✅ ' . $result['message'] . '</p></div>';
    else echo '<div class="notice notice-error"><p>❌ ' . $result['message'] . '</p></div>';
}

// Processa remoção de licença
if (isset($_POST['remove_license'])) {
    delete_option('yt_license_key');
    delete_option('yt_license_cached_status');
    echo '<div class="notice notice-info"><p>🗑️ Licença removida</p></div>';
}

$current_license = get_option('yt_license_key');
$status = $yt_license_manager->get_license_status();
$is_test = strpos($yt_license_manager->api_url, 'localhost') !== false;
?>

<div class="wrap">
    <h1>🎬 Converttize - Licenciamento</h1>
    
    <?php if ($is_test): ?>

        <div style="background: #fff3cd; padding: 15px; margin: 15px 0; border-left: 4px solid #ffc107;">
            <h3>⚠️ MODO DE TESTE ATIVO</h3>
            <p>Conectando com servidor local para demonstração.</p>
            <p><strong>API:</strong> <code><?php echo $yt_license_manager->api_url; ?></code></p>
            <a href="http://localhost/yt-license-test/admin-panel/"  class="button">
                🎛️ Abrir Painel de Testes
            </a>
        </div>

    <?php endif; ?>
    
    <div class="flex-16">
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
                                echo '<small>Todas as funcionalidades liberadas</small>';
                                break;
                            case 'degraded':
                            case 'suspended':
                                echo '<span style="color: orange; font-weight: bold;">⚠️ SUSPENSA</span><br>';
                                echo '<small>Player básico do YouTube ativo</small>';
                                break;
                            case 'cancelled':
                                echo '<span style="color: red; font-weight: bold;">❌ CANCELADA</span><br>';
                                echo '<small>Licença cancelada</small>';
                                break;
                            case 'refunded': // ✅ NOVO: Case para reembolso
                                echo '<span style="color: red; font-weight: bold;">💸 REEMBOLSADA</span><br>';
                                echo '<small>Licença reembolsada</small>';
                                break;
                            case 'inactive':
                            default: // ✅ NOVO: Default para casos não previstos
                                echo '<span style="color: red; font-weight: bold;">❌ INATIVA</span><br>';
                                echo '<small>Licença necessária</small>';
                                break;
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Domínio</th>
                    <td><code><?php echo $_SERVER['HTTP_HOST']; ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Última Verificação</th>
                    <td>
                        <?php 
                        $last_check = get_option('yt_license_last_check');
                        echo $last_check ? date('d/m/Y H:i:s', $last_check) : 'Nunca';
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
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="license_key">Chave da Licença</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="license_key" 
                                       name="license_key" 
                                       class="regular-text" 
                                       placeholder="Cole sua chave de licença aqui"
                                       required />
                                <p class="description">
                                    Insira a chave recebida por email após a compra.
                                    <?php if ($is_test): ?>
                                    <br><strong>Para teste:</strong> Use o painel acima para gerar uma chave.
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Ativar Licença', 'primary', 'activate_license'); ?>
                </form>
                
            <?php else: ?>
                <!-- Licença Ativa -->
                <table class="form-table">
                    <tr>
                        <th scope="row">Chave Ativa</th>
                        <td>
                            <code><?php echo substr($current_license, 0, 8) . '...' . substr($current_license, -8); ?></code>
                            <br><small>Chave parcialmente oculta por segurança</small>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" onclick="checkLicenseNow()" class="button">
                        🔄 Verificar Agora
                    </button>
                    
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <?php submit_button('🗑️ Remover Licença', 'delete', 'remove_license', false, [
                            'onclick' => 'return confirm("Tem certeza que deseja remover a licença?")'
                        ]); ?>
                    </form>
                </p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($is_test): ?>

        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>🧪 Ferramentas de Teste</h2>
            <p>Use estas chaves para testar diferentes cenários:</p>
            <ul>
                <li><code>TEST_STARTER_ACTIVE_123456789012</code> - Plano Starter Ativo</li>
                <li><code>TEST_PRO_ACTIVE_123456789012345</code> - Plano Pro Ativo</li>
                <li><code>TEST_DEGRADED_123456789012345</code> - Licença Suspensa</li>
                <li><code>TEST_REFUNDED_123456789012345</code> - ✅ Licença Reembolsada</li>
                <li><code>TEST_CANCELLED_123456789012345</code> - ✅ Licença Cancelada</li>
            </ul>
        </div>

    <?php endif; ?>
</div>

<script>

    function checkLicenseNow() {
        const button = event.target;
        button.disabled = true;
        button.textContent = '🔄 Verificando...';
        
        // Força uma nova verificação
        jQuery.post(ajaxurl, {
            action: 'yt_force_license_check'
        }, function(response) {
            location.reload();
        }).always(function() {
            button.disabled = false;
            button.textContent = '🔄 Verificar Agora';
        });
    }

</script>
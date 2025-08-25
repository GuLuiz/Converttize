<?php
// config.php - Configurações centralizadas do plugin

if (!defined('ABSPATH')) {
    exit;
}

return [
    // 🔐 Configurações de Licença
    'license' => [
        'api_url_local' => 'http://localhost/server-license/api/',
        'api_url_production' => 'https://greenyellow-mole-539634.hostingersite.com/api/',
        'security_secret' => 'CONV_SEC_2024_XYZ789',
        'cache_duration' => 60, // segundos
        'check_interval' => 3600, // 1 hora
    ],
    
    // 🎬 Configurações do Player
    'player' => [
        'default_theme' => 'dark',
        'auto_play' => false,
        'show_controls' => true,
        'responsive' => true,
        'custom_css' => true,
    ],
    
    // 🔧 Configurações Técnicas
    'technical' => [
        'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
        'log_file' => 'converttize-debug.log',
        'rate_limit' => 30, // requests por minuto
        'timeout' => 10, // segundos
    ],
    
    // 🌍 Configurações de Ambiente
    'environment' => [
        'local_hosts' => ['localhost', '127.0.0.1', 'local-test','server-license'],
        'staging_hosts' => ['staging.example.com'],
        'production_hosts' => ['greenyellow-mole-539634.hostingersite.com'],
    ]
];
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

require_once __DIR__ . '/src/McpServer.php';
require_once __DIR__ . '/src/Providers/GoogleAdsProvider.php';
require_once __DIR__ . '/src/Providers/GoogleAnalyticsProvider.php';
require_once __DIR__ . '/src/Providers/MetaAdsProvider.php';

// Suppress PHP warnings/deprecations from vendor libs — they would corrupt the stdout MCP channel
set_error_handler(function (int $errno, string $errstr): bool {
    if ($errno !== E_USER_ERROR) {
        fwrite(STDERR, "[PHP $errno] $errstr\n");
        return true;
    }
    return false;
});
error_reporting(E_ALL);

$server = new McpServer();

(new GoogleAdsProvider())->registerTools($server);
(new GoogleAnalyticsProvider())->registerTools($server);
(new MetaAdsProvider())->registerTools($server);

$server->run();

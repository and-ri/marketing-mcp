<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/McpServer.php';

foreach (glob(__DIR__ . '/../src/Providers/*.php') as $file) {
    require_once $file;
}

/** Collects the tools registered by one or more providers. */
function registeredTools(object ...$providers): array
{
    $server = new McpServer();
    foreach ($providers as $p) {
        $p->registerTools($server);
    }
    return (new ReflectionProperty($server, 'tools'))->getValue($server);
}

/** Calls a private/protected static method. */
function callStatic(string $class, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
}

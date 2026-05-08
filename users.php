<?php

declare(strict_types=1);

/**
 * User management CLI for marketing-mcp server.
 *
 * Commands:
 *   php users.php add <name>                     Create user, print token
 *   php users.php list                           List all users
 *   php users.php remove <name>                  Delete user and their env
 *   php users.php token:reset <name>             Generate a new token
 *   php users.php env:set <name> <KEY> <VALUE>   Set one env variable
 *   php users.php env:show <name>                Show env variables (masked)
 *   php users.php env:import <name>              Import KEY=VALUE lines from stdin
 */

// ─── Database ────────────────────────────────────────────────────────────────

function openDb(): PDO
{
    $path = __DIR__ . '/data/users.sqlite';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    $db = new PDO("sqlite:$path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT UNIQUE NOT NULL,
        token      TEXT UNIQUE NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS user_env (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        key     TEXT NOT NULL,
        value   TEXT NOT NULL,
        PRIMARY KEY (user_id, key)
    )");
    return $db;
}

function requireUser(PDO $db, string $name): array
{
    $stmt = $db->prepare("SELECT * FROM users WHERE name = ?");
    $stmt->execute([$name]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        fwrite(STDERR, "Error: user '$name' not found.\n");
        exit(1);
    }
    return $user;
}

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function maskValue(string $value): string
{
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
}

// ─── Commands ────────────────────────────────────────────────────────────────

function cmdAdd(PDO $db, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        fwrite(STDERR, "Usage: php users.php add <name>\n");
        exit(1);
    }

    $token = generateToken();
    try {
        $db->prepare("INSERT INTO users (name, token) VALUES (?, ?)")->execute([$name, $token]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            fwrite(STDERR, "Error: user '$name' already exists.\n");
            exit(1);
        }
        throw $e;
    }

    echo "User created: $name\n";
    echo "Token:        $token\n";
    echo "\n";
    echo "Claude Code config (~/.claude.json or project .mcp.json):\n";
    echo "{\n";
    echo "  \"mcpServers\": {\n";
    echo "    \"marketing\": {\n";
    echo "      \"type\": \"http\",\n";
    echo "      \"url\": \"https://YOUR_DOMAIN/mcp\",\n";
    echo "      \"headers\": { \"Authorization\": \"Bearer $token\" }\n";
    echo "    }\n";
    echo "  }\n";
    echo "}\n";
}

function cmdList(PDO $db): void
{
    $users = $db->query("SELECT id, name, token, created_at FROM users ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    if (!$users) {
        echo "No users found. Create one with: php users.php add <name>\n";
        return;
    }

    $fmt = "%-4s  %-20s  %-68s  %s\n";
    printf($fmt, 'ID', 'Name', 'Token', 'Created');
    echo str_repeat('-', 110) . "\n";
    foreach ($users as $u) {
        printf($fmt, $u['id'], $u['name'], $u['token'], $u['created_at']);
    }
}

function cmdRemove(PDO $db, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        fwrite(STDERR, "Usage: php users.php remove <name>\n");
        exit(1);
    }

    $user = requireUser($db, $name);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);
    echo "User '$name' removed.\n";
}

function cmdTokenReset(PDO $db, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        fwrite(STDERR, "Usage: php users.php token:reset <name>\n");
        exit(1);
    }

    $user  = requireUser($db, $name);
    $token = generateToken();
    $db->prepare("UPDATE users SET token = ? WHERE id = ?")->execute([$token, $user['id']]);
    echo "New token for '$name': $token\n";
}

function cmdEnvSet(PDO $db, array $args): void
{
    [$name, $key, $value] = array_pad($args, 3, null);
    if (!$name || !$key || $value === null) {
        fwrite(STDERR, "Usage: php users.php env:set <name> <KEY> <VALUE>\n");
        exit(1);
    }

    $user = requireUser($db, $name);
    $db->prepare("INSERT INTO user_env (user_id, key, value) VALUES (?, ?, ?)
        ON CONFLICT(user_id, key) DO UPDATE SET value = excluded.value")
        ->execute([$user['id'], $key, $value]);
    echo "Set $key for '$name'.\n";
}

function cmdEnvShow(PDO $db, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        fwrite(STDERR, "Usage: php users.php env:show <name>\n");
        exit(1);
    }

    $user = requireUser($db, $name);
    $rows = $db->prepare("SELECT key, value FROM user_env WHERE user_id = ? ORDER BY key");
    $rows->execute([$user['id']]);
    $env = $rows->fetchAll(PDO::FETCH_ASSOC);

    if (!$env) {
        echo "No env vars for '$name'. Set them with: php users.php env:set $name KEY VALUE\n";
        echo "Or import from a file:  php users.php env:import $name < .env\n";
        return;
    }

    echo "Env vars for '$name':\n";
    foreach ($env as $row) {
        printf("  %-40s = %s\n", $row['key'], maskValue($row['value']));
    }
}

function cmdEnvImport(PDO $db, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        fwrite(STDERR, "Usage: php users.php env:import <name> < .env\n");
        exit(1);
    }

    $user   = requireUser($db, $name);
    $count  = 0;
    $stmt   = $db->prepare("INSERT INTO user_env (user_id, key, value) VALUES (?, ?, ?)
        ON CONFLICT(user_id, key) DO UPDATE SET value = excluded.value");

    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        // Strip surrounding quotes
        if (preg_match('/^(["\'])(.*)\\1$/', $val, $m)) {
            $val = $m[2];
        }
        if ($key === '') {
            continue;
        }
        $stmt->execute([$user['id'], $key, $val]);
        $count++;
    }

    echo "Imported $count env var(s) for '$name'.\n";
}

// ─── Dispatch ────────────────────────────────────────────────────────────────

$db   = openDb();
$argv = $argv ?? [];
$cmd  = $argv[1] ?? 'help';
$rest = array_slice($argv, 2);

match ($cmd) {
    'add'          => cmdAdd($db, $rest),
    'list'         => cmdList($db),
    'remove'       => cmdRemove($db, $rest),
    'token:reset'  => cmdTokenReset($db, $rest),
    'env:set'      => cmdEnvSet($db, $rest),
    'env:show'     => cmdEnvShow($db, $rest),
    'env:import'   => cmdEnvImport($db, $rest),
    default        => (function () {
        echo "Marketing MCP — User Management\n\n";
        echo "Commands:\n";
        echo "  php users.php add <name>                     Create user, print token\n";
        echo "  php users.php list                           List all users\n";
        echo "  php users.php remove <name>                  Delete user and their env\n";
        echo "  php users.php token:reset <name>             Generate a new token\n";
        echo "  php users.php env:set <name> <KEY> <VALUE>   Set one env variable\n";
        echo "  php users.php env:show <name>                Show env variables (masked)\n";
        echo "  php users.php env:import <name>              Import KEY=VALUE lines from stdin\n";
        echo "\nExamples:\n";
        echo "  php users.php add alice\n";
        echo "  php users.php env:import alice < alice.env\n";
        echo "  php users.php env:set alice META_ACCESS_TOKEN EAAxxxx\n";
        echo "  php users.php env:show alice\n";
    })(),
};

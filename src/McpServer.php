<?php

class McpServer
{
    private array $tools = [];

    public function registerTool(
        string $name,
        string $description,
        array $inputSchema,
        callable $handler
    ): void {
        $this->tools[$name] = compact('name', 'description', 'inputSchema', 'handler');
    }

    public function run(): void
    {
        while (true) {
            $header = fgets(STDIN);
            if ($header === false) {
                break;
            }
            $header = trim($header);
            if ($header === '') {
                continue;
            }
            if (!str_starts_with($header, 'Content-Length:')) {
                continue;
            }
            $length = (int) trim(substr($header, 15));
            fgets(STDIN); // blank line
            $body = '';
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = fread(STDIN, $remaining);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            $request = json_decode($body, true);
            if (!is_array($request)) {
                continue;
            }
            // Notifications have no id — no response needed
            if (!array_key_exists('id', $request)) {
                continue;
            }
            $response = $this->dispatch($request);
            $this->send($response);
        }
    }

    public function processRequest(array $request): array
    {
        return $this->dispatch($request);
    }

    private function dispatch(array $request): array
    {
        $id = $request['id'];
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        return match ($method) {
            'initialize'  => $this->handleInitialize($id),
            'tools/list'  => $this->handleToolsList($id),
            'tools/call'  => $this->handleToolCall($id, $params),
            'ping'        => ['jsonrpc' => '2.0', 'id' => $id, 'result' => []],
            default       => $this->errorResponse($id, -32601, "Method not found: $method"),
        };
    }

    private function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'marketing-mcp', 'version' => '1.0.0'],
            ],
        ];
    }

    private function handleToolsList(mixed $id): array
    {
        $tools = array_map(
            fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']],
            $this->tools
        );
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => array_values($tools)]];
    }

    private function handleToolCall(mixed $id, array $params): array
    {
        $name      = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            return $this->errorResponse($id, -32602, "Unknown tool: $name");
        }

        try {
            $result = ($this->tools[$name]['handler'])($arguments);
            $text   = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => ['content' => [['type' => 'text', 'text' => $text]]],
            ];
        } catch (Throwable $e) {
            fwrite(STDERR, "[ERROR] {$e->getMessage()}\n{$e->getTraceAsString()}\n");
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => [
                    'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                    'isError' => true,
                ],
            ];
        }
    }

    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function send(array $response): void
    {
        $body   = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $length = strlen($body);
        fwrite(STDOUT, "Content-Length: $length\r\n\r\n$body");
        fflush(STDOUT);
    }
}

<?php

/**
 * Acessa configurações aninhadas com notação de ponto.
 * Ex: config('db.host'), config('app')
 */
function config(string $chave, mixed $padrao = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require BASE_PATH . '/config/config.php';
    }

    $partes = explode('.', $chave);
    $valor  = $cfg;

    foreach ($partes as $parte) {
        if (!is_array($valor) || !array_key_exists($parte, $valor)) {
            return $padrao;
        }
        $valor = $valor[$parte];
    }

    return $valor;
}

function view(string $template, array $dados = []): void
{
    extract($dados, EXTR_SKIP);
    $arquivo = BASE_PATH . '/app/Views/' . $template . '.php';
    if (!file_exists($arquivo)) {
        throw new \RuntimeException("View não encontrada: {$template}");
    }
    require $arquivo;
}

function redirect(string $uri, int $codigo = 302): never
{
    header("Location: {$uri}", true, $codigo);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verificar(): bool
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token === '' && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['DELETE', 'PUT', 'PATCH'])) {
        parse_str(file_get_contents('php://input'), $body);
        $token = $body['_csrf'] ?? '';
    }
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function sanitizar(string $valor): string
{
    return htmlspecialchars(trim($valor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_resposta(mixed $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ip_valido(string $ip): bool
{
    // Aceita IPv4 simples ou CIDR
    if (str_contains($ip, '/')) {
        [$endereco, $prefixo] = explode('/', $ip, 2);
        return filter_var($endereco, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefixo) && (int)$prefixo <= 32;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function formatar_bytes(int $bytes): string
{
    $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($unidades) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $unidades[$i];
}

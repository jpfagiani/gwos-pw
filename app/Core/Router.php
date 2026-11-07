<?php

namespace App\Core;

class Router
{
    private array $rotas = [];

    public function get(string $rota, string $handler): void
    {
        $this->adicionar('GET', $rota, $handler);
    }

    public function post(string $rota, string $handler): void
    {
        $this->adicionar('POST', $rota, $handler);
    }

    public function put(string $rota, string $handler): void
    {
        $this->adicionar('PUT', $rota, $handler);
    }

    public function delete(string $rota, string $handler): void
    {
        $this->adicionar('DELETE', $rota, $handler);
    }

    private function adicionar(string $metodo, string $rota, string $handler): void
    {
        $this->rotas[] = [
            'metodo'  => $metodo,
            'rota'    => $rota,
            'handler' => $handler,
            'regex'   => $this->construirRegex($rota),
        ];
    }

    private function construirRegex(string $rota): string
    {
        $padrao = preg_replace('/\{[a-zA-Z_]+\}/', '([^/]+)', $rota);
        return '#^' . $padrao . '$#';
    }

    public function resolver(): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri    = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // Suporte a method override via _method
        if ($metodo === 'POST' && isset($_POST['_method'])) {
            $metodo = strtoupper($_POST['_method']);
        }

        foreach ($this->rotas as $rota) {
            if ($rota['metodo'] !== $metodo) {
                continue;
            }

            if (preg_match($rota['regex'], $uri, $matches)) {
                array_shift($matches);
                $this->executar($rota['handler'], $matches);
                return;
            }
        }

        http_response_code(404);
        if (file_exists(BASE_PATH . '/app/Views/erros/404.php')) {
            require BASE_PATH . '/app/Views/erros/404.php';
        } else {
            echo '<h1>404 — Página não encontrada</h1>';
        }
    }

    private function executar(string $handler, array $params): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $publicas = [
            'GET /login', 'POST /login',
            'GET /acesso', 'POST /acesso', 'POST /acesso/sair',
            'GET /acesso/cadastro', 'POST /acesso/cadastro',
            'GET /acesso/esqueci', 'POST /acesso/esqueci',
            'GET /acesso/senha', 'POST /acesso/senha',
            'GET /senha/reset', 'POST /senha/reset',
        ];
        $caminho = rtrim($uri, '/') ?: '/';
        $publica = in_array($metodo . ' ' . $caminho, $publicas, true);

        if (!$publica) {
            Auth::exigir();
        }

        if (!in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (!csrf_verificar()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Requisição recusada. Atualize a página e tente novamente.';
                return;
            }

            $admin = Auth::admin();
            if ($admin && ($admin['perfil'] ?? '') === 'operador'
                && !in_array($metodo . ' ' . $caminho, ['POST /logout', 'POST /senha/trocar'], true)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Seu perfil permite somente leitura.';
                return;
            }
        }

        [$classe, $metodo] = explode('@', $handler);
        $classeCompleta = 'App\\Controllers\\' . $classe;

        if (!class_exists($classeCompleta)) {
            throw new \RuntimeException("Controller não encontrado: {$classeCompleta}");
        }

        $controller = new $classeCompleta();

        if (!method_exists($controller, $metodo)) {
            throw new \RuntimeException("Método não encontrado: {$classeCompleta}::{$metodo}");
        }

        $controller->$metodo(...$params);
    }
}

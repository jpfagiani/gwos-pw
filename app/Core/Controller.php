<?php

namespace App\Core;

class Controller
{
    protected function view(string $nome, array $dados = []): void
    {
        $arquivoView = BASE_PATH . '/app/Views/' . $nome . '.php';

        ob_start();
        extract($dados, EXTR_SKIP);
        require $arquivoView;
        $conteudo = ob_get_clean();

        $dados['conteudo'] = $conteudo;
        extract($dados, EXTR_SKIP);
        require BASE_PATH . '/app/Views/layout.php';
    }

    protected function json(mixed $dados, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    protected function erro404(): void
    {
        http_response_code(404);
        require BASE_PATH . '/app/Views/erros/404.php';
    }
}

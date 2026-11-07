<?php

namespace App\Core;

class Controller
{
    protected function view(string $nome, array $dados = []): void
    {
        // Os parâmetros ficam com nomes que nenhuma view usa: com EXTR_SKIP,
        // uma variável de view chamada "dados" (ex.: ranking) não era
        // sobrescrita e virava o array inteiro de variáveis.
        $__arquivoView = BASE_PATH . '/app/Views/' . $nome . '.php';
        $__vars = $dados;
        unset($dados, $nome);

        ob_start();
        extract($__vars, EXTR_SKIP);
        require $__arquivoView;
        $conteudo = ob_get_clean();

        $__vars['conteudo'] = $conteudo;
        extract($__vars, EXTR_SKIP);
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

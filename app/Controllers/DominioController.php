<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria};

class DominioController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $dominios = Database::fetchAll(
            'SELECT * FROM dominios ORDER BY tipo, dominio'
        );

        $this->view('dominios/index', compact('dominios'));
    }

    public function criar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $dominio = strtolower(trim($_POST['dominio'] ?? ''));
        $tipo    = trim($_POST['tipo'] ?? '');

        if ($dominio === '' || !in_array($tipo, ['whitelist', 'blacklist'])) {
            json_resposta(['erro' => 'Domínio e tipo são obrigatórios.'], 422);
        }

        if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $dominio)) {
            json_resposta(['erro' => 'Domínio inválido.'], 422);
        }

        $existe = Database::fetch('SELECT id FROM dominios WHERE dominio = ?', [$dominio]);
        if ($existe) { json_resposta(['erro' => 'Domínio já cadastrado.'], 409); }

        $id = Database::insert(
            'INSERT INTO dominios (dominio, tipo, origem) VALUES (?, ?, ?)',
            [$dominio, $tipo, 'manual']
        );

        $tarefas = $this->aplicarRegras();
        Auditoria::registrar('criar_dominio', 'dominios', (int)$id, null, compact('dominio', 'tipo') + ['tarefas' => $tarefas]);

        json_resposta(['sucesso' => 'Domínio adicionado; aplicação enfileirada.', 'id' => $id, 'tarefas' => $tarefas]);
    }

    public function remover(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $dominio = Database::fetch('SELECT * FROM dominios WHERE id = ?', [$id]);
        if (!$dominio) { json_resposta(['erro' => 'Domínio não encontrado.'], 404); }

        Database::execute('DELETE FROM dominios WHERE id = ?', [$id]);
        $tarefas = $this->aplicarRegras();
        Auditoria::registrar('remover_dominio', 'dominios', (int)$id, $dominio, ['tarefas' => $tarefas]);

        json_resposta(['sucesso' => 'Domínio removido; aplicação enfileirada.', 'tarefas' => $tarefas]);
    }

    public function aplicar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $tarefas = $this->aplicarRegras();
        Auditoria::registrar('aplicar_dominios', 'dominios', null, null, ['tarefas' => $tarefas]);

        json_resposta(['sucesso' => 'Aplicação das listas enfileirada.', 'tarefas' => $tarefas]);
    }

    private function aplicarRegras(): array
    {
        return [
            \App\Core\Tarefas::enfileirar('domains_squid', [], Auth::id(), 'domains_squid'),
            \App\Core\Tarefas::enfileirar('domains_dns', [], Auth::id(), 'domains_dns'),
        ];
    }
}

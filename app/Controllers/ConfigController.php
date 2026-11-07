<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria, Session};

class ConfigController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $configuracoes = Database::fetchAll(
            'SELECT chave, valor, descricao FROM configuracoes ORDER BY chave'
        );

        $this->view('configuracoes/index', compact('configuracoes'));
    }

    public function salvar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $dados = $_POST['config'] ?? [];

        if (!is_array($dados)) {
            json_resposta(['erro' => 'Dados inválidos.'], 422);
        }

        foreach ($dados as $chave => $valor) {
            $chave = preg_replace('/[^a-z0-9_]/', '', $chave);
            if ($chave === '') continue;

            Database::execute(
                'INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$chave, trim($valor)]
            );
        }

        Auditoria::registrar('salvar_configuracoes', 'configuracoes', null, null, $dados);

        Session::flash('sucesso', 'Configurações salvas.');
        $this->redirect('/configuracoes');
    }
}

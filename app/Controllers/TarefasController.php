<?php

namespace App\Controllers;

use App\Core\{Auth, Controller, Tarefas};

class TarefasController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->view('tarefas/index', [
            'titulo' => 'Operações',
            'modulo' => 'tarefas',
            'tarefas' => Tarefas::recentes(100),
        ]);
    }

    public function lista(): void
    {
        Auth::exigir();
        $this->json(['tarefas' => Tarefas::recentes(100), 'coletado_em' => gmdate('c')]);
    }

    public function obter(string $id): void
    {
        Auth::exigir();
        $tarefa = Tarefas::obter($id);
        if (!$tarefa) $this->json(['erro' => 'Operação não encontrada.'], 404);
        $this->json(['tarefa' => $tarefa]);
    }
}

<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria};

class HorarioController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $horarios = Database::fetchAll(
            'SELECT h.*, g.nome as grupo_nome
             FROM horarios h
             LEFT JOIN ip_grupos g ON g.id = h.grupo_id
             ORDER BY h.hora_inicio'
        );

        $grupos = Database::fetchAll('SELECT id, nome FROM ip_grupos WHERE ativo = 1 ORDER BY nome');

        $this->view('horarios/index', compact('horarios', 'grupos'));
    }

    public function criar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $nome        = trim($_POST['nome'] ?? '');
        $grupo_id    = $_POST['grupo_id'] ? (int)$_POST['grupo_id'] : null;
        $dias        = trim($_POST['dias_semana'] ?? '1111100');
        $hora_inicio = trim($_POST['hora_inicio'] ?? '');
        $hora_fim    = trim($_POST['hora_fim'] ?? '');
        $acao        = trim($_POST['acao'] ?? 'bloquear');

        if ($nome === '' || $hora_inicio === '' || $hora_fim === '') {
            json_resposta(['erro' => 'Nome, hora de início e fim são obrigatórios.'], 422);
        }

        if (!in_array($acao, ['permitir', 'bloquear'])) {
            json_resposta(['erro' => 'Ação inválida.'], 422);
        }

        if (!preg_match('/^[01]{7}$/', $dias)) {
            json_resposta(['erro' => 'Dias da semana inválidos.'], 422);
        }

        $id = Database::insert(
            'INSERT INTO horarios (nome, grupo_id, dias_semana, hora_inicio, hora_fim, acao)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$nome, $grupo_id, $dias, $hora_inicio, $hora_fim, $acao]
        );

        $tarefa = \App\Core\Tarefas::enfileirar('hours_squid', [], Auth::id(), 'hours_squid');

        Auditoria::registrar('criar_horario', 'horarios', (int)$id, null, compact('nome', 'hora_inicio', 'hora_fim') + ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'Horário criado; aplicação enfileirada.', 'id' => $id, 'tarefa_id' => $tarefa]);
    }

    public function remover(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $horario = Database::fetch('SELECT * FROM horarios WHERE id = ?', [$id]);
        if (!$horario) { json_resposta(['erro' => 'Horário não encontrado.'], 404); }

        Database::execute('DELETE FROM horarios WHERE id = ?', [$id]);

        $tarefa = \App\Core\Tarefas::enfileirar('hours_squid', [], Auth::id(), 'hours_squid');

        Auditoria::registrar('remover_horario', 'horarios', (int)$id, $horario, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'Horário removido; aplicação enfileirada.', 'tarefa_id' => $tarefa]);
    }
}

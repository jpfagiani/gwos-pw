<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria};

class GrupoController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $grupos = Database::fetchAll(
            'SELECT g.*, COUNT(i.id) as total_ips
             FROM ip_grupos g
             LEFT JOIN ips i ON i.grupo_id = g.id AND i.ativo = 1
             GROUP BY g.id
             ORDER BY g.nome'
        );

        $this->view('grupos/index', compact('grupos'));
    }

    public function criar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $nome      = trim($_POST['nome'] ?? '');
        $tipo      = trim($_POST['tipo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');

        if ($nome === '' || !in_array($tipo, ['bloqueado', 'parcial', 'liberado', 'isento'])) {
            json_resposta(['erro' => 'Nome e tipo são obrigatórios.'], 422);
        }

        $id = Database::insert(
            'INSERT INTO ip_grupos (nome, tipo, descricao) VALUES (?, ?, ?)',
            [$nome, $tipo, $descricao]
        );

        Auditoria::registrar('criar_grupo', 'ip_grupos', (int)$id, null, compact('nome', 'tipo'));

        json_resposta(['sucesso' => 'Grupo criado.', 'id' => $id]);
    }

    public function remover(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $grupo = Database::fetch('SELECT * FROM ip_grupos WHERE id = ?', [$id]);
        if (!$grupo) { json_resposta(['erro' => 'Grupo não encontrado.'], 404); }

        Database::execute('DELETE FROM ip_grupos WHERE id = ?', [$id]);
        $tarefa = $this->aplicarRegras();
        Auditoria::registrar('remover_grupo', 'ip_grupos', (int)$id, $grupo, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'Grupo removido; aplicação enfileirada.', 'tarefa_id' => $tarefa]);
    }

    public function ips(string $id): void
    {
        Auth::exigir();

        $grupo = Database::fetch('SELECT * FROM ip_grupos WHERE id = ?', [$id]);
        if (!$grupo) { json_resposta(['erro' => 'Grupo não encontrado.'], 404); }

        $ips = Database::fetchAll(
            'SELECT * FROM ips WHERE grupo_id = ? ORDER BY endereco',
            [$id]
        );

        json_resposta(['grupo' => $grupo, 'ips' => $ips]);
    }

    public function adicionarIp(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $endereco  = trim($_POST['endereco'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');

        if (!ip_valido($endereco)) {
            json_resposta(['erro' => 'Endereço IP inválido.'], 422);
        }

        $grupo = Database::fetch('SELECT id FROM ip_grupos WHERE id = ?', [$id]);
        if (!$grupo) { json_resposta(['erro' => 'Grupo não encontrado.'], 404); }

        $existe = Database::fetch('SELECT id FROM ips WHERE endereco = ? AND grupo_id = ?', [$endereco, $id]);
        if ($existe) { json_resposta(['erro' => 'IP já cadastrado neste grupo.'], 409); }

        $ipId = Database::insert(
            'INSERT INTO ips (grupo_id, endereco, descricao) VALUES (?, ?, ?)',
            [$id, $endereco, $descricao]
        );

        $tarefa = $this->aplicarRegras();
        Auditoria::registrar('adicionar_ip', 'ips', (int)$ipId, null, ['grupo_id' => $id, 'endereco' => $endereco, 'tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'IP adicionado; aplicação enfileirada.', 'id' => $ipId, 'tarefa_id' => $tarefa]);
    }

    public function removerIp(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $ip = Database::fetch('SELECT * FROM ips WHERE id = ?', [$id]);
        if (!$ip) { json_resposta(['erro' => 'IP não encontrado.'], 404); }

        Database::execute('DELETE FROM ips WHERE id = ?', [$id]);
        $tarefa = $this->aplicarRegras();
        Auditoria::registrar('remover_ip', 'ips', (int)$id, $ip, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'IP removido; aplicação enfileirada.', 'tarefa_id' => $tarefa]);
    }

    public function aplicar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $tarefa = $this->aplicarRegras();
        Auditoria::registrar('aplicar_nftables', 'ip_grupos', null, null, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'Regras enfileiradas para aplicação.', 'tarefa_id' => $tarefa]);
    }

    private function aplicarRegras(): string
    {
        return \App\Core\Tarefas::enfileirar('firewall_apply', [], Auth::id(), 'firewall_apply');
    }
}

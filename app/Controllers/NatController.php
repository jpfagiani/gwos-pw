<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria};

class NatController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $entradas = Database::fetchAll(
            'SELECT * FROM nat_um_para_um ORDER BY criado_em DESC'
        );

        $this->view('nat/index', compact('entradas'));
    }

    public function criar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $ip_externo = trim($_POST['ip_externo'] ?? '');
        $ip_interno = trim($_POST['ip_interno'] ?? '');
        $descricao  = trim($_POST['descricao']  ?? '');

        if (!filter_var($ip_externo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !filter_var($ip_interno, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            json_resposta(['erro' => 'IPs inválidos.'], 422);
        }

        $existe = Database::fetch(
            'SELECT id FROM nat_um_para_um WHERE ip_externo = ? OR ip_interno = ?',
            [$ip_externo, $ip_interno]
        );
        if ($existe) {
            json_resposta(['erro' => 'Um dos IPs já está em uso em outra regra NAT.'], 409);
        }

        $id = Database::insert(
            'INSERT INTO nat_um_para_um (ip_externo, ip_interno, descricao) VALUES (?, ?, ?)',
            [$ip_externo, $ip_interno, $descricao ?: '']
        );

        Auditoria::registrar('criar_nat', 'nat_um_para_um', (int)$id, null, [
            'ip_externo' => $ip_externo, 'ip_interno' => $ip_interno,
        ]);

        json_resposta(['sucesso' => 'Regra NAT criada.', 'id' => $id]);
    }

    public function toggle(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $nat = Database::fetch('SELECT * FROM nat_um_para_um WHERE id = ?', [$id]);
        if (!$nat) { json_resposta(['erro' => 'Regra não encontrada.'], 404); }

        $novo = $nat['ativo'] ? 0 : 1;
        Database::execute('UPDATE nat_um_para_um SET ativo = ? WHERE id = ?', [$novo, $id]);

        $acao = $novo ? 'ativar' : 'desativar';
        $tarefa = $this->executarScript('nat', $acao, [$nat['ip_externo'], $nat['ip_interno']]);

        Auditoria::registrar('toggle_nat', 'nat_um_para_um', (int)$id, $nat, ['ativo' => $novo, 'tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => $novo ? 'NAT ativado; aplicação enfileirada.' : 'NAT desativado; aplicação enfileirada.', 'ativo' => $novo, 'tarefa_id' => $tarefa]);
    }

    public function remover(string $id): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $nat = Database::fetch('SELECT * FROM nat_um_para_um WHERE id = ?', [$id]);
        if (!$nat) { json_resposta(['erro' => 'Regra não encontrada.'], 404); }

        if ($nat['ativo']) {
            $tarefa = $this->executarScript('nat', 'desativar', [$nat['ip_externo'], $nat['ip_interno']]);
        } else {
            $tarefa = null;
        }

        Database::execute('DELETE FROM nat_um_para_um WHERE id = ?', [$id]);
        Auditoria::registrar('remover_nat', 'nat_um_para_um', (int)$id, $nat, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => $tarefa ? 'Regra removida; desativação enfileirada.' : 'Regra NAT removida.', 'tarefa_id' => $tarefa]);
    }

    public function aplicarTodas(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $tarefa = $this->executarScript('nftables');
        Auditoria::registrar('aplicar_nat_todas', 'nat_um_para_um', null, null, ['tarefa_id' => $tarefa]);

        json_resposta(['sucesso' => 'Regras NAT enfileiradas para aplicação.', 'tarefa_id' => $tarefa]);
    }

    // Executa script da whitelist — nunca interpola variáveis externas no comando
    private function executarScript(string $chave, string $acao = '', array $args = []): ?string
    {
        if ($chave === 'nftables') {
            return \App\Core\Tarefas::enfileirar('nat_apply', [], Auth::id(), 'nat_apply');
        }
        if ($chave === 'nat' && count($args) === 2) {
            return \App\Core\Tarefas::enfileirar('nat_toggle', [
                'acao' => $acao,
                'ip_externo' => (string) $args[0],
                'ip_interno' => (string) $args[1],
            ], Auth::id(), 'nat:' . $acao . ':' . $args[0] . ':' . $args[1]);
        }
        return null;
    }
}

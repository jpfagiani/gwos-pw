<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Politicas, Monitoramento};

/** Políticas e Regras: visão central + ações rápidas por equipamento (com prévia). */
class PoliticaController extends Controller
{
    private const ACOES = ['alterar_grupo', 'bloquear', 'liberar_temporario', 'revogar'];
    private const MAX_LOTE = 50;

    public function index(): void
    {
        Auth::exigir();
        $grupos = Database::fetchAll(
            "SELECT g.id,g.nome,g.tipo,COUNT(i.id) quantidade FROM ip_grupos g
             LEFT JOIN ips i ON i.grupo_id=g.id AND i.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW())
             WHERE g.ativo=1 GROUP BY g.id ORDER BY FIELD(g.tipo,'liberado','parcial','bloqueado','isento'),g.nome"
        );
        $resumo = [
            'dominios_white' => (int) Database::valor("SELECT COUNT(*) FROM dominios WHERE ativo=1 AND tipo='whitelist'"),
            'dominios_black' => (int) Database::valor("SELECT COUNT(*) FROM dominios WHERE ativo=1 AND tipo='blacklist'"),
            'horarios'       => (int) Database::valor('SELECT COUNT(*) FROM horarios WHERE ativo=1'),
            'nat'            => (int) Database::valor('SELECT COUNT(*) FROM nat_um_para_um'),
            'temporarias'    => (int) Database::valor("SELECT COUNT(*) FROM ips WHERE origem_acao='liberacao_temporaria' AND ativo=1 AND expira_em>NOW()"),
        ];
        $temporarias = Database::fetchAll(
            "SELECT i.endereco,i.expira_em,g.nome grupo_nome FROM ips i JOIN ip_grupos g ON g.id=i.grupo_id
             WHERE i.origem_acao='liberacao_temporaria' AND i.ativo=1 AND i.expira_em>NOW() ORDER BY i.expira_em"
        );
        $this->view('politicas/index', compact('grupos', 'resumo', 'temporarias'));
    }

    /**
     * POST /politicas/acao — ips[] (até 50), acao, grupo_id?, minutos?, previa?
     * Com previa=1 nada é gravado: devolve o que mudaria para cada equipamento.
     */
    public function acao(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) json_resposta(['erro' => 'Token inválido.'], 403);

        $acao = (string) ($_POST['acao'] ?? '');
        if (!in_array($acao, self::ACOES, true)) json_resposta(['erro' => 'Ação desconhecida.'], 422);

        $bruto = $_POST['ips'] ?? [];
        if (is_string($bruto)) $bruto = preg_split('/[\s,;]+/', trim($bruto)) ?: [];
        $ips = [];
        foreach ((array) $bruto as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && !in_array($ip, $ips, true)) $ips[] = $ip;
        }
        if (!$ips) json_resposta(['erro' => 'Informe ao menos um endereço IP.'], 422);
        if (count($ips) > self::MAX_LOTE) json_resposta(['erro' => 'No máximo ' . self::MAX_LOTE . ' equipamentos por vez.'], 422);
        foreach ($ips as $ip) {
            if (!Politicas::ipValido($ip)) json_resposta(['erro' => "Endereço IP inválido: {$ip}"], 422);
        }

        $grupoId = (int) ($_POST['grupo_id'] ?? 0);
        $minutos = (int) ($_POST['minutos'] ?? 0);
        if ($acao === 'alterar_grupo' && $grupoId <= 0) json_resposta(['erro' => 'Escolha o grupo de destino.'], 422);
        if ($acao === 'liberar_temporario' && ($minutos < Politicas::MIN_MINUTOS || $minutos > Politicas::MAX_MINUTOS)) {
            json_resposta(['erro' => 'Informe a duração entre ' . Politicas::MIN_MINUTOS . ' minutos e 7 dias.'], 422);
        }

        if (!empty($_POST['previa'])) {
            $destino = null;
            if ($acao === 'alterar_grupo') $destino = Database::fetch('SELECT nome,tipo FROM ip_grupos WHERE id=? AND ativo=1', [$grupoId]) ?: null;
            if ($acao === 'bloquear') $destino = Politicas::grupoPorTipo('bloqueado');
            if ($acao === 'liberar_temporario') $destino = Politicas::grupoPorTipo('liberado');
            if (in_array($acao, ['alterar_grupo', 'bloquear', 'liberar_temporario'], true) && !$destino) {
                json_resposta(['erro' => 'O grupo de destino não existe. Crie-o em Grupos e IPs.'], 422);
            }
            $linhas = [];
            foreach ($ips as $ip) {
                $atual = Politicas::regrasExatas($ip);
                $efetivo = Monitoramento::gruposParaIp($ip);
                $linhas[] = [
                    'ip' => $ip,
                    'atual' => $atual ? implode(' / ', array_unique(array_column($atual, 'grupo_nome'))) : ($efetivo ? implode(' / ', $efetivo) . ' (por rede)' : 'sem regra'),
                    'depois' => $acao === 'revogar' ? 'volta à política anterior' : ($destino['nome'] ?? '—'),
                ];
            }
            json_resposta(['previa' => true, 'acao' => $acao, 'minutos' => $minutos ?: null, 'linhas' => $linhas]);
        }

        $adminId = Auth::id();
        $resultados = []; $falhas = 0;
        foreach ($ips as $ip) {
            $r = match ($acao) {
                'alterar_grupo'      => Politicas::alterarGrupo($ip, $grupoId, $adminId),
                'bloquear'           => Politicas::bloquear($ip, $adminId),
                'liberar_temporario' => Politicas::liberarTemporario($ip, $minutos, $adminId),
                'revogar'            => Politicas::revogarTemporario($ip, $adminId),
            };
            if (!$r['ok']) $falhas++;
            $resultados[] = ['ip' => $ip] + $r;
        }
        json_resposta([
            'ok' => $falhas === 0,
            'resumo' => $falhas === 0 ? 'Ação aplicada em ' . count($ips) . ' equipamento(s). A aplicação no firewall/Squid foi enfileirada.' : "{$falhas} de " . count($ips) . ' falharam.',
            'resultados' => $resultados,
        ], $falhas === count($ips) ? 422 : 200);
    }
}

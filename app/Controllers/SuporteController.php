<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Modulos, Monitoramento};

/** Diagnóstico, Ajuda e Busca global (Relatório Mestre v2.0, seções 2, 3 e 10). */
class SuporteController extends Controller
{
    public function diagnostico(): void
    {
        Auth::exigir();
        $checks = [];
        $add = static function (string $grupo, string $nome, string $estado, string $detalhe) use (&$checks): void {
            $checks[$grupo][] = ['nome' => $nome, 'estado' => $estado, 'detalhe' => $detalhe];
        };

        foreach (Modulos::instalados() as $m) {
            foreach ($m['servicos'] as $svc) {
                $ativo = trim((string) shell_exec('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null')) === 'active';
                $add('Serviços', $svc, $ativo ? 'ok' : 'bad', $ativo ? 'ativo' : 'parado ou não verificável');
            }
        }
        $temBanco = Modulos::temBanco();
        if ($temBanco) {
            try { Database::valor('SELECT 1'); $add('Banco de dados', 'MariaDB', 'ok', 'consulta de teste respondeu'); }
            catch (\Throwable $e) { $add('Banco de dados', 'MariaDB', 'bad', 'sem resposta'); }
            foreach (['iface_wan' => 'WAN', 'iface_lan' => 'LAN'] as $chave => $rot) {
                $nome = (string) (Database::valor('SELECT valor FROM configuracoes WHERE chave=?', [$chave]) ?? '');
                if ($nome === '') { $add('Rede', "Interface {$rot}", 'nd', 'não configurada em Configurações'); continue; }
                $i = Monitoramento::interfaceInfo($nome);
                $add('Rede', "Interface {$rot} ({$nome})", !$i['existe'] ? 'bad' : (in_array($i['estado'], ['up', 'unknown'], true) ? 'ok' : 'bad'),
                    !$i['existe'] ? 'interface inexistente' : 'estado ' . ($i['estado'] ?? '?') . ' · IP ' . ($i['ip'] ?? 'sem IP'));
            }
            $ultima = Database::valor('SELECT MAX(coletado_em) FROM metricas_rede');
            $idade = $ultima ? time() - strtotime((string) $ultima) : null;
            $add('Coleta', 'Métricas de interface (cron)', $idade === null ? 'nd' : ($idade <= 180 ? 'ok' : 'warn'), $idade === null ? 'nenhuma amostra gravada' : "última há {$idade}s");
            $ultAcesso = Database::valor('SELECT MAX(ocorrido_em) FROM acessos_internet');
            $add('Coleta', 'Importação do access.log do Squid', $ultAcesso ? 'ok' : 'nd', $ultAcesso ? "último registro em {$ultAcesso}" : 'nenhum acesso importado');
            $falhas = (int) count(array_filter(\App\Core\Tarefas::recentes(50), static fn($t) => in_array($t['estado'] ?? '', ['failed', 'timeout', 'unconfirmed'], true)));
            $add('Operações', 'Tarefas privilegiadas recentes', $falhas ? 'warn' : 'ok', $falhas ? "{$falhas} com falha/timeout nas últimas 50" : 'sem falhas nas últimas 50');
        }
        $dns = @exec('timeout 4 getent hosts example.com 2>/dev/null', $o, $cod);
        $add('Rede', 'Resolução DNS (example.com)', $cod === 0 ? 'ok' : 'warn', $cod === 0 ? 'resolveu' : 'não resolveu em 4 s (ou getent ausente)');

        $c = DashboardController::cargaSistema(); $m = DashboardController::memoriaSistema(); $d = DashboardController::discoSistema(); $u = DashboardController::uptimeSistema();
        $add('Recursos', 'Carga (1/5/15 min)', $c ? 'ok' : 'nd', $c ?? 'indisponível');
        $add('Recursos', 'Memória', $m ? ($m['usada_pct'] >= 92 ? 'warn' : 'ok') : 'nd', $m ? "{$m['usada_mb']} / {$m['total_mb']} MB ({$m['usada_pct']}%)" : 'indisponível');
        $add('Recursos', 'Disco raiz', $d ? ($d['usada_pct'] >= 85 ? 'warn' : 'ok') : 'nd', $d ? "{$d['usada_gb']} / {$d['total_gb']} GB ({$d['usada_pct']}%)" : 'indisponível');
        $add('Recursos', 'Tempo ligado', $u !== null ? 'ok' : 'nd', $u !== null ? floor($u / 86400) . ' d ' . floor(($u % 86400) / 3600) . ' h' : 'indisponível');

        $dirBk = '/var/lib/gwos/backups'; $ult = null;
        foreach (@glob($dirBk . '/*.tar.gz') ?: [] as $f) { $ult = max($ult ?? 0, (int) @filemtime($f)); }
        $add('Backup', 'Último backup', $ult ? ((time() - $ult) < 30 * 3600 ? 'ok' : 'warn') : 'nd', $ult ? date('d/m/Y H:i', $ult) : 'nenhum encontrado (ou diretório restrito)');

        $this->view('diagnostico/index', compact('checks', 'temBanco'));
    }

    public function ajuda(): void { Auth::exigir(); $this->view('ajuda/index'); }

    public function busca(): void
    {
        Auth::exigir();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) > 120) $q = mb_substr($q, 0, 120);
        $r = ['equipamentos' => [], 'usuarios' => [], 'dominios' => [], 'acessos' => []];
        if ($q !== '' && Modulos::temBanco()) {
            $l = '%' . addcslashes($q, '%_\\') . '%';
            $r['equipamentos'] = Database::fetchAll('SELECT ip,mac,hostname,origem FROM equipamentos WHERE ip LIKE ? OR mac LIKE ? OR hostname LIKE ? ORDER BY ip LIMIT 30', [$l, $l, $l]);
            $r['usuarios'] = Database::fetchAll('SELECT u.nome,u.email,s.ip FROM usuarios_internet u LEFT JOIN sessoes_internet s ON s.usuario_id=u.id AND s.expira_em>NOW() WHERE u.nome LIKE ? OR u.email LIKE ? ORDER BY u.nome LIMIT 30', [$l, $l]);
            $r['dominios'] = Database::fetchAll('SELECT dominio,tipo FROM dominios WHERE ativo=1 AND dominio LIKE ? ORDER BY dominio LIMIT 30', [$l]);
            $r['acessos'] = Database::fetchAll('SELECT ip,dominio,COUNT(*) n,MAX(ocorrido_em) ultimo FROM acessos_internet WHERE dominio LIKE ? OR ip LIKE ? OR email LIKE ? GROUP BY ip,dominio ORDER BY ultimo DESC LIMIT 30', [$l, $l, $l]);
        }
        $this->view('busca/index', compact('q', 'r'));
    }
}

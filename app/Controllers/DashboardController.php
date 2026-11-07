<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller};

class DashboardController extends Controller
{
    public function info(): void
    {
        Auth::exigir();
        header('Content-Type: application/json');

        // Contadores reais das DUAS interfaces (antes só a WAN era lida e a
        // "LAN" do gráfico era inventada multiplicando por 0.6)
        $ifaceWan = (string) (Database::valor("SELECT valor FROM configuracoes WHERE chave = 'iface_wan'") ?? '');
        $ifaceLan = (string) (Database::valor("SELECT valor FROM configuracoes WHERE chave = 'iface_lan'") ?? '');
        if ($ifaceWan === '') {
            $ifaceWan = trim(shell_exec("ip route show default 2>/dev/null | awk '{print \$5;exit}'") ?? '');
        }
        [$wanRx, $wanTx] = self::contadores($ifaceWan);
        [$lanRx, $lanTx] = self::contadores($ifaceLan);

        echo json_encode([
            'ip'     => trim(shell_exec("ip route get 1 2>/dev/null | awk '{print \$7;exit}'") ?? '') ?: '—',
            'gw'     => trim(shell_exec("ip route show default 2>/dev/null | awk '{print \$3;exit}'") ?? '') ?: '—',
            'dns'    => trim(shell_exec("awk '/^nameserver/{print \$2;exit}' /etc/resolv.conf 2>/dev/null") ?? '') ?: '—',
            'lan_rx' => $lanRx,
            'lan_tx' => $lanTx,
            'wan_rx' => $wanRx,
            'wan_tx' => $wanTx,
            'coletado_em' => gmdate('c'),
            'rede_disponivel' => $ifaceWan !== '' || $ifaceLan !== '',
            'carga' => self::cargaSistema(),
            'memoria' => self::memoriaSistema(),
            'disco' => self::discoSistema(),
            'uptime' => self::uptimeSistema(),
            'conn'   => (int)(Database::valor('SELECT COUNT(DISTINCT ip_cliente) FROM relatorio_diario WHERE data = CURDATE()') ?? 0),
            'wan'    => \App\Core\Monitoramento::interfaceInfo($ifaceWan),
            'lan'    => \App\Core\Monitoramento::interfaceInfo($ifaceLan),
            'acessos' => self::acessosRecentes(6),
            'log'    => self::lerLogSquid(100),
        ]);
        exit;
    }

    /** GET /ping — pulso leve do cabeçalho/rodapé. */
    public function ping(): void
    {
        Auth::exigir();
        json_resposta(['ok' => true, 'hora' => date('d/m/Y H:i:s')]);
    }

    /** GET /dashboard/servicos — estado dos serviços dos módulos instalados. */
    public function servicos(): void
    {
        Auth::exigir();
        json_resposta(['servicos' => self::servicosRotulados($this->statusServicos()), 'coletado_em' => date('H:i:s')]);
    }

    private static function nomeServico(string $svc): string
    {
        return [
            'named' => 'DNS (BIND9)', 'gwos-dnsmasq' => 'DNS interno / DHCP (dnsmasq)', 'nftables' => 'Firewall (nftables)',
            'squid' => 'Proxy (Squid)', 'chrony' => 'Hora (Chrony)', 'mariadb' => 'Banco (MariaDB)', 'nginx' => 'Painel (Nginx)',
            'smbd' => 'Samba', 'isc-dhcp-server' => 'DHCP', 'kea-dhcp4-server' => 'DHCP (Kea)',
        ][$svc] ?? (str_starts_with($svc, 'php') ? 'PHP-FPM' : $svc);
    }

    private static function servicosRotulados(array $status): array
    {
        $r = [];
        foreach ($status as $svc => $ativo) $r[] = ['id' => $svc, 'nome' => self::nomeServico($svc), 'ativo' => (bool) $ativo];
        return $r;
    }

    /** Últimos acessos importados do Squid (banco), com equipamento e usuário. */
    private static function acessosRecentes(int $n): array
    {
        $linhas = \App\Core\Monitoramento::acessos(['periodo' => '24h'], $n);
        $r = [];
        foreach ($linhas as $a) {
            $negado = str_contains((string) $a['status'], 'DENIED') || str_contains((string) $a['status'], 'BLOCKED');
            $r[] = [
                'ip' => $a['ip'], 'equipamento' => ($a['hostname'] ?? '') !== '' ? $a['hostname'] : '—',
                'usuario' => ($a['email'] ?? '') !== '' ? $a['email'] : '—', 'destino' => $a['dominio'] ?: '—',
                'download' => (int) $a['bytes'], 'upload' => $a['request_bytes'] === null ? null : (int) $a['request_bytes'],
                'protocolo' => strtoupper((string) ($a['protocolo'] ?? '')), 'hora' => date('H:i:s', strtotime((string) $a['ocorrido_em'])),
                'negado' => $negado,
            ];
        }
        return $r;
    }

    private static function contadores(string $iface): array
    {
        $iface = preg_replace('/[^a-zA-Z0-9\-_.]/', '', trim($iface));
        if ($iface === '' || !is_dir("/sys/class/net/{$iface}")) return [null, null];
        $rx = @file_get_contents("/sys/class/net/{$iface}/statistics/rx_bytes");
        $tx = @file_get_contents("/sys/class/net/{$iface}/statistics/tx_bytes");
        if ($rx === false || $tx === false || !ctype_digit(trim($rx)) || !ctype_digit(trim($tx))) return [null, null];
        return [
            (int) trim($rx),
            (int) trim($tx),
        ];
    }

    public static function cargaSistema(): ?string
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false || !preg_match('/^(\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?)/', $raw, $m)) return null;
        return $m[1];
    }

    public static function memoriaSistema(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false || !preg_match('/^MemTotal:\s+(\d+)/m', $raw, $total) || !preg_match('/^MemAvailable:\s+(\d+)/m', $raw, $livre)) return null;
        $totalKb = (int) $total[1];
        $usadaKb = max(0, $totalKb - (int) $livre[1]);
        return ['usada_pct' => $totalKb > 0 ? round($usadaKb * 100 / $totalKb, 1) : null,
            'usada_mb' => round($usadaKb / 1024), 'total_mb' => round($totalKb / 1024)];
    }

    public static function discoSistema(): ?array
    {
        $total = @disk_total_space('/');
        $livre = @disk_free_space('/');
        if ($total === false || $livre === false || $total <= 0) return null;
        return ['usada_pct' => round(($total - $livre) * 100 / $total, 1),
            'usada_gb' => round(($total - $livre) / 1073741824, 1), 'total_gb' => round($total / 1073741824, 1)];
    }

    public static function uptimeSistema(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');
        return $raw !== false && preg_match('/^(\d+(?:\.\d+)?)/', $raw, $m) ? (int) $m[1] : null;
    }

    private static function grupoMap(): array
    {
        static $map = null;
        if ($map === null) {
            $rows = Database::fetchAll(
                'SELECT i.endereco, g.nome FROM ips i JOIN ip_grupos g ON g.id = i.grupo_id WHERE i.ativo = 1'
            );
            $map = array_column($rows, 'nome', 'endereco');
        }
        return $map;
    }

    private static function lerLogSquid(int $n): array
    {
        $arquivo = '/var/log/squid/access.log';
        if (!is_readable($arquivo)) return [];

        $fp = popen('tail -n ' . $n . ' ' . escapeshellarg($arquivo) . ' 2>/dev/null', 'r');
        if (!$fp) return [];

        $linhas = [];
        while (($l = fgets($fp)) !== false) $linhas[] = trim($l);
        pclose($fp);

        $grupos = self::grupoMap();
        $result = [];

        foreach (array_reverse($linhas) as $l) {
            if (!$l) continue;
            $p    = preg_split('/\s+/', $l);
            $ts   = (int)($p[0] ?? 0);
            $ip   = $p[2] ?? '—';
            // O log novo insere bytes da requisição antes do método; mantém
            // compatibilidade com linhas antigas e identifica a URL pela forma da linha.
            $url  = isset($p[7]) && preg_match('/^[A-Z]{1,16}$/i', $p[6] ?? '') ? $p[7] : ($p[6] ?? '—');
            $host = parse_url($url, PHP_URL_HOST) ?: preg_replace('/:\d+$/', '', $url);
            $bloq = str_contains($p[3] ?? '', 'DENIED') || str_contains($p[3] ?? '', 'BLOCKED');

            $result[] = [
                'hora'      => date('d/m H:i:s', $ts),
                'ip'        => $ip,
                'dominio'   => $host,
                'grupo'     => $grupos[$ip] ?? '—',
                'bloqueado' => $bloq,
            ];
        }
        return $result;
    }

    private static function lerUltimosAcessos(int $n = 25): array
    {
        $arquivo = '/var/log/squid/access.log';
        if (!is_readable($arquivo)) return [];

        $fp = popen('tail -n 3000 ' . escapeshellarg($arquivo) . ' 2>/dev/null', 'r');
        if (!$fp) return [];

        $linhas = [];
        while (($l = fgets($fp)) !== false) $linhas[] = trim($l);
        pclose($fp);

        $grupos = self::grupoMap();
        $vistos = [];

        foreach (array_reverse($linhas) as $l) {
            if (!$l) continue;
            $p = preg_split('/\s+/', $l);
            if (count($p) < 7) continue;

            $ts    = (int)($p[0] ?? 0);
            $ip    = $p[2] ?? '';
            $url   = isset($p[7]) && preg_match('/^[A-Z]{1,16}$/i', $p[6] ?? '') ? $p[7] : ($p[6] ?? '');
            $bytes = (int)($p[4] ?? 0);
            $host  = parse_url($url, PHP_URL_HOST) ?: preg_replace('/:\d+$/', '', $url);
            $bloq  = str_contains($p[3] ?? '', 'DENIED') || str_contains($p[3] ?? '', 'BLOCKED');

            $chave = $ip . '|' . $host;
            if (isset($vistos[$chave])) continue;

            $vistos[$chave] = [
                'data_hora'  => date('d/m/Y H:i:s', $ts),
                'ip_cliente' => $ip,
                'dominio'    => $host,
                'grupo'      => $grupos[$ip] ?? '—',
                'bloqueado'  => $bloq,
                'bytes'      => $bytes,
            ];

            if (count($vistos) >= $n) break;
        }

        return array_values($vistos);
    }

    public function index(): void
    {
        Auth::exigir();

        // Modo leve: sem o módulo 10-banco-mariadb não há grupos, IPs nem
        // relatórios para mostrar. A visão geral dos módulos vira a home.
        if (!\App\Core\Modulos::temBanco()) {
            $this->redirect('/modulos');
        }

        $servicos = $this->statusServicos();
        $servicosLista = self::servicosRotulados($servicos);

        $cfg = static fn(string $k): string => (string) (Database::valor('SELECT valor FROM configuracoes WHERE chave=?', [$k]) ?? '');
        $ifWan = $cfg('iface_wan');
        if ($ifWan === '') $ifWan = trim((string) shell_exec("ip route show default 2>/dev/null | awk '{print \$5;exit}'"));
        $ifLan = $cfg('iface_lan');
        $wan = \App\Core\Monitoramento::interfaceInfo($ifWan);
        $lan = \App\Core\Monitoramento::interfaceInfo($ifLan);
        $hist24 = \App\Core\Monitoramento::historico24h($ifWan);

        $conectados = (int) Database::valor('SELECT COUNT(*) FROM equipamentos WHERE atualizado_em >= NOW() - INTERVAL 15 MINUTE');
        $bloqueiosHoje = (int) Database::valor("SELECT COUNT(*) FROM acessos_internet WHERE DATE(ocorrido_em)=CURDATE() AND (status LIKE '%DENIED%' OR status LIKE '%BLOCKED%')");
        $sessoesAtivas = (int) Database::valor('SELECT COUNT(*) FROM sessoes_internet WHERE expira_em > NOW()');
        $alertasAbertos = (int) Database::valor("SELECT COUNT(*) FROM alertas WHERE estado='aberto'");

        $inativos = count(array_filter($servicos, static fn($a) => !$a));
        if (!$servicos) { $estado = ['nd', 'Sem dados', 'Nenhum serviço registrado para verificar.']; }
        elseif ($wan['existe'] && $wan['estado'] !== null && $wan['estado'] !== 'up' && $wan['estado'] !== 'unknown') { $estado = ['erro', 'WAN sem link', 'A interface WAN não está ativa.']; }
        elseif ($inativos > 0) { $estado = ['aviso', 'Atenção', $inativos . ' serviço(s) parado(s)']; }
        else { $estado = ['ok', 'Rede Operacional', 'Tudo funcionando normalmente']; }

        $rankingHoje = \App\Core\Monitoramento::ranking('24h', 10);
        $alertasRecentes = Database::fetchAll("SELECT id,severidade,titulo,mensagem,estado,atualizado_em FROM alertas WHERE estado<>'resolvido' ORDER BY FIELD(severidade,'critico','aviso','info'), atualizado_em DESC LIMIT 4");
        $acessosRecentes = self::acessosRecentes(6);

        $gruposTipo = ['liberado' => 0, 'parcial' => 0, 'bloqueado' => 0];
        foreach (Database::fetchAll("SELECT g.tipo, COUNT(i.id) q FROM ip_grupos g LEFT JOIN ips i ON i.grupo_id=g.id AND i.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW()) WHERE g.ativo=1 GROUP BY g.tipo") as $g) {
            if (isset($gruposTipo[$g['tipo']])) $gruposTipo[$g['tipo']] = (int) $g['q'];
        }
        $agenda = Database::fetchAll('SELECT h.nome,h.acao,h.hora_inicio,h.hora_fim,h.dias_semana,g.nome grupo_nome FROM horarios h LEFT JOIN ip_grupos g ON g.id=h.grupo_id WHERE h.ativo=1 ORDER BY h.hora_inicio,h.id LIMIT 5');

        $equipamentos = array_slice(\App\Core\Monitoramento::dispositivos(), 0, 400);
        usort($equipamentos, static fn($a, $b) => strcmp((string) ($b['ultimo_acesso'] ?? ''), (string) ($a['ultimo_acesso'] ?? '')));
        $equipamentos = array_slice($equipamentos, 0, 5);
        $gruposAcao = \App\Core\Politicas::grupos();

        $this->view('dashboard/index', compact(
            'servicosLista', 'wan', 'lan', 'hist24', 'conectados', 'bloqueiosHoje', 'sessoesAtivas', 'alertasAbertos', 'estado',
            'rankingHoje', 'alertasRecentes', 'acessosRecentes', 'gruposTipo', 'agenda', 'equipamentos', 'gruposAcao'
        ));
    }

    private function statusServicos(): array
    {
        $status = [];
        foreach (\App\Core\Modulos::instalados() as $modulo) {
            foreach ($modulo['servicos'] as $svc) {
                $status[$svc] = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null")) === 'active';
            }
        }
        return $status;
    }
}

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
            'log'    => self::lerLogSquid(100),
        ]);
        exit;
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

    private static function cargaSistema(): ?string
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false || !preg_match('/^(\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?)/', $raw, $m)) return null;
        return $m[1];
    }

    private static function memoriaSistema(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false || !preg_match('/^MemTotal:\s+(\d+)/m', $raw, $total) || !preg_match('/^MemAvailable:\s+(\d+)/m', $raw, $livre)) return null;
        $totalKb = (int) $total[1];
        $usadaKb = max(0, $totalKb - (int) $livre[1]);
        return ['usada_pct' => $totalKb > 0 ? round($usadaKb * 100 / $totalKb, 1) : null,
            'usada_mb' => round($usadaKb / 1024), 'total_mb' => round($totalKb / 1024)];
    }

    private static function discoSistema(): ?array
    {
        $total = @disk_total_space('/');
        $livre = @disk_free_space('/');
        if ($total === false || $livre === false || $total <= 0) return null;
        return ['usada_pct' => round(($total - $livre) * 100 / $total, 1),
            'usada_gb' => round(($total - $livre) / 1073741824, 1), 'total_gb' => round($total / 1073741824, 1)];
    }

    private static function uptimeSistema(): ?int
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
            $url  = $p[6] ?? '—';
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
            $url   = $p[6] ?? '';
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

        $totalIps      = (int) Database::valor('SELECT COUNT(*) FROM ips WHERE ativo = 1');
        $totalGrupos   = (int) Database::valor('SELECT COUNT(*) FROM ip_grupos WHERE ativo = 1');
        $totalDominios = (int) Database::valor('SELECT COUNT(*) FROM dominios WHERE ativo = 1');
        $totalNat      = (int) Database::valor('SELECT COUNT(*) FROM nat_um_para_um');

        $ultimosAcessos = self::lerUltimosAcessos(25);

        $this->view('dashboard/index', compact(
            'servicos', 'totalIps', 'totalGrupos', 'totalDominios', 'totalNat', 'ultimosAcessos'
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

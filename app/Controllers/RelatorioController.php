<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller};

class RelatorioController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        [$data_inicio, $data_fim, $erro] = $this->periodo();
        $pagina = max(1, min(100000, (int) ($_GET['pagina'] ?? 1)));
        $por_pag = 50;
        $offset = ($pagina - 1) * $por_pag;

        $total = $registros = 0;
        if ($erro === null) {
            $total = (int) Database::valor(
                'SELECT COUNT(*) FROM relatorio_diario WHERE data BETWEEN ? AND ?',
                [$data_inicio, $data_fim]
            );
            $registros = Database::fetchAll(
                'SELECT data, ip_cliente, dominio, acessos, bytes
                 FROM relatorio_diario
                 WHERE data BETWEEN ? AND ?
                 ORDER BY data DESC, acessos DESC
                 LIMIT ? OFFSET ?',
                [$data_inicio, $data_fim, $por_pag, $offset]
            );
            if ((Auth::admin()['perfil'] ?? '') === 'operador') {
                foreach ($registros as &$registro) $registro['ip_cliente'] = $this->mascararIp((string) $registro['ip_cliente']);
                unset($registro);
            }
        }

        $total_paginas = (int) ceil($total / $por_pag);

        $this->view('relatorios/index', compact(
            'registros', 'pagina', 'total_paginas', 'total', 'data_inicio', 'data_fim', 'erro'
        ));
    }

    public function exportar(): void
    {
        Auth::exigir();
        [$inicio, $fim, $erro] = $this->periodo();
        if ($erro !== null) { http_response_code(422); exit($erro); }
        $linhas = Database::fetchAll(
            'SELECT data, ip_cliente, dominio, acessos, bytes FROM relatorio_diario
             WHERE data BETWEEN ? AND ? ORDER BY data DESC, acessos DESC LIMIT 50000',
            [$inicio, $fim]
        );
        $operador = (Auth::admin()['perfil'] ?? '') === 'operador';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="gwos-relatorio-' . $inicio . '-' . $fim . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF");
        fputcsv($saida, ['Data', 'IP cliente', 'Domínio', 'Acessos', 'Bytes'], ';');
        foreach ($linhas as $linha) {
            $ip = $operador ? $this->mascararIp((string) $linha['ip_cliente']) : (string) $linha['ip_cliente'];
            fputcsv($saida, array_map([$this, 'protegerCsv'], [
                $linha['data'], $ip, $linha['dominio'], $linha['acessos'], $linha['bytes'],
            ]), ';');
        }
        fclose($saida);
        exit;
    }

    private function periodo(): array
    {
        $inicio = (string) ($_GET['data_inicio'] ?? date('Y-m-01'));
        $fim = (string) ($_GET['data_fim'] ?? date('Y-m-d'));
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
        if (!$a || !$b || $a->format('Y-m-d') !== $inicio || $b->format('Y-m-d') !== $fim) {
            return [$inicio, $fim, 'Informe datas válidas no formato AAAA-MM-DD.'];
        }
        if ($a > $b) return [$inicio, $fim, 'A data inicial deve ser anterior à data final.'];
        if ($a->diff($b)->days > 366) return [$inicio, $fim, 'O intervalo máximo do relatório é de 367 dias.'];
        return [$inicio, $fim, null];
    }

    private function mascararIp(string $ip): string
    {
        $binario = @inet_pton($ip);
        if ($binario === false) return 'mascarado';
        if (strlen($binario) === 4) {
            return inet_ntop(substr($binario, 0, 3) . "\0") ?: 'mascarado';
        }
        // IPv6: preserva o prefixo /56 e zera os 72 bits restantes.
        return inet_ntop(substr($binario, 0, 7) . str_repeat("\0", 9)) ?: 'mascarado';
    }

    private function protegerCsv(mixed $valor): string
    {
        $texto = (string) $valor;
        if (preg_match('/^[\s]*[=+\-@]/u', $texto)) $texto = "'" . $texto;
        return $texto;
    }
}

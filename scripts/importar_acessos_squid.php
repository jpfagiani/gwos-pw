<?php
/**
 * GWOS — Importa o access.log do Squid para a tabela acessos_internet
 * (data/hora, IP da máquina, e-mail do usuário, site, resultado).
 *
 * Roda de minuto em minuto pelo cron. Lê SÓ o que é novo desde a última
 * execução (posição guardada em /var/lib/gwos/acessos.pos) e reinicia do zero
 * quando o logrotate troca o arquivo. Também limpa as sessões vencidas e, uma
 * vez por dia, registros mais antigos que ACESSOS_RETENCAO_DIAS (padrão 365).
 *
 * Formato esperado (logformat gwos, squid.conf):
 *   ts elapsed ip status/http bytes metodo url usuario hier/peer mime
 */

use App\Core\{Database, AcessoInternet};

require dirname(__DIR__) . '/bootstrap/app.php';

const LOG        = '/var/log/squid/access.log';
const ESTADO     = '/var/lib/gwos/acessos.pos';
const LOTE       = 500;
const MAX_LINHAS = 300000;   // por execução; o resto fica para a próxima

// Uma execução por vez
$lock = fopen('/tmp/gwos_importar_acessos.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// Mantém o espelho de sessões em dia (remove as vencidas do arquivo do Squid)
try { AcessoInternet::sincronizar(); } catch (\Throwable $e) { /* sem banco: segue */ }

if (!is_readable(LOG)) {
    exit(0);
}

$st = stat(LOG);
[$inoSalvo, $posSalva] = array_pad(array_map('intval', explode(' ', @file_get_contents(ESTADO) ?: '0 0')), 2, 0);
$pos = ($inoSalvo === $st['ino'] && $posSalva <= $st['size']) ? $posSalva : 0;
if ($pos === $st['size']) {
    exit(0);   // nada novo
}

$fp = fopen(LOG, 'r');
fseek($fp, $pos);

$lote   = [];
$total  = 0;
$lidas  = 0;
$posFim = $pos;

$gravar = function (array $linhas): void {
    if (!$linhas) return;
    $marcas = implode(',', array_fill(0, count($linhas), '(?,?,?,?,?,?,?,?)'));
    $params = [];
    foreach ($linhas as $l) { array_push($params, ...$l); }
    Database::execute(
        "INSERT INTO acessos_internet (ocorrido_em, ip, email, metodo, dominio, url, status, bytes) VALUES {$marcas}",
        $params
    );
};

while ($lidas < MAX_LINHAS && ($linha = fgets($fp)) !== false) {
    // Linha incompleta no fim (Squid ainda escrevendo): para e retoma depois
    if (!str_ends_with($linha, "\n")) {
        break;
    }
    $posFim += strlen($linha);
    $lidas++;

    $p = preg_split('/\s+/', trim($linha));
    if (count($p) < 8) continue;

    [$ts, , $ip, $status, $bytes, $metodo, $url, $usuario] = $p;
    if (!ctype_digit(explode('.', $ts)[0]) || $ip === '') continue;

    $email = null;
    if ($usuario !== '-' && $usuario !== '') {
        $u = strtolower(urldecode($usuario));
        $email = str_contains($u, '@') ? mb_substr($u, 0, 150) : null;
    }

    // CONNECT traz "host:porta"; o resto, a URL completa
    if ($metodo === 'CONNECT' || !str_contains($url, '://')) {
        $dominio = preg_replace('/:\d+$/', '', $url);
    } else {
        $dominio = parse_url($url, PHP_URL_HOST) ?: $url;
    }
    $dominio = mb_substr(strtolower(trim($dominio, '[]. ')), 0, 253);
    if ($dominio === '') continue;

    $lote[] = [
        date('Y-m-d H:i:s', (int) $ts), $ip, $email, mb_substr($metodo, 0, 16),
        $dominio, mb_substr($url, 0, 1000), mb_substr($status, 0, 40), (int) $bytes,
    ];

    if (count($lote) >= LOTE) {
        $gravar($lote);
        $total += count($lote);
        $lote = [];
    }
}
fclose($fp);

try {
    $gravar($lote);
    $total += count($lote);
} catch (\Throwable $e) {
    // Não avança a posição: a próxima execução tenta de novo este trecho
    fwrite(STDERR, '[' . date('c') . '] erro ao gravar acessos: ' . $e->getMessage() . "\n");
    exit(1);
}

@mkdir(dirname(ESTADO), 0755, true);
file_put_contents(ESTADO, $st['ino'] . ' ' . $posFim);

// Retenção: uma vez por dia, de madrugada
if (date('Hi') < '0302' && date('Hi') >= '0300') {
    $dias = max(30, (int) (getenv('ACESSOS_RETENCAO_DIAS') ?: 365));
    Database::execute('DELETE FROM acessos_internet WHERE ocorrido_em < ?',
        [date('Y-m-d H:i:s', time() - $dias * 86400)]);
}

if ($total > 0 && PHP_SAPI === 'cli' && function_exists("posix_isatty") && posix_isatty(STDOUT)) {
    echo date('Y-m-d H:i:s') . " — {$total} acessos importados.\n";
}

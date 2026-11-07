<?php
/**
 * GWOS — Parser de log do Squid (cron a cada 5 min)
 * Lê as últimas linhas do access.log e upserta em relatorio_diario.
 */

define('BASE_PATH', dirname(__DIR__));

$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (str_starts_with(trim($linha), '#') || !str_contains($linha, '=')) continue;
        [$chave, $valor] = explode('=', $linha, 2);
        $_ENV[trim($chave)] = trim($valor);
    }
}

$env = fn(string $k, string $d = '') => $_ENV[$k] ?? getenv($k) ?: $d;

$host    = $env('DB_HOST',    '127.0.0.1');
$banco   = $env('DB_BANCO',   'gwos');
$usuario = $env('DB_USUARIO', 'gwos');
$senha   = $env('DB_SENHA',   '');
$logFile = '/var/log/squid/access.log';

if (!file_exists($logFile) || !is_readable($logFile)) {
    echo "Log não encontrado ou sem permissão: {$logFile}\n";
    exit(0);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$banco};charset=utf8mb4",
        $usuario, $senha,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "Erro ao conectar ao banco: " . $e->getMessage() . "\n";
    exit(1);
}

// Lê as últimas 5000 linhas (suficiente para janela de 5 min em redes pequenas)
$linhas = [];
$fp = popen('tail -n 5000 ' . escapeshellarg($logFile) . ' 2>/dev/null', 'r');
if ($fp) {
    while (($l = fgets($fp)) !== false) {
        $linhas[] = rtrim($l);
    }
    pclose($fp);
}

if (empty($linhas)) {
    exit(0);
}

$hoje    = date('Y-m-d');
$agora   = time();
$janela  = 310; // processa entradas dos últimos ~5 min + margem

$agregado = [];

foreach ($linhas as $linha) {
    if ($linha === '') continue;
    $p = preg_split('/\s+/', $linha, 11);
    if (count($p) < 8) continue;

    $ts      = (int)$p[0];
    $ip      = $p[2] ?? '';
    $status  = $p[3] ?? '';
    $bytes   = (int)($p[4] ?? 0);
    $url     = $p[6] ?? '';

    // Só processa entradas recentes
    if ($agora - $ts > $janela) continue;

    // Extrai domínio
    $host_url = parse_url($url, PHP_URL_HOST) ?: preg_replace('/:\d+$/', '', $url);
    $host_url = strtolower(trim($host_url, '.'));
    if ($host_url === '' || $ip === '') continue;

    $bloqueado = (int)(str_contains($status, 'DENIED') || str_contains($status, 'BLOCKED'));
    $chave     = "{$ip}\0{$host_url}";

    if (!isset($agregado[$chave])) {
        $agregado[$chave] = ['ip' => $ip, 'dominio' => $host_url, 'acessos' => 0, 'bytes' => 0, 'bloqueado' => 0];
    }
    $agregado[$chave]['acessos']++;
    $agregado[$chave]['bytes']    += $bytes;
    $agregado[$chave]['bloqueado'] = max($agregado[$chave]['bloqueado'], $bloqueado);
}

if (empty($agregado)) {
    exit(0);
}

$sql = 'INSERT INTO relatorio_diario (data, ip_cliente, dominio, acessos, bytes, bloqueado)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            acessos   = acessos   + VALUES(acessos),
            bytes     = bytes     + VALUES(bytes),
            bloqueado = VALUES(bloqueado)';

$stmt = $pdo->prepare($sql);
$ok   = 0;

foreach ($agregado as $r) {
    try {
        $stmt->execute([$hoje, $r['ip'], $r['dominio'], $r['acessos'], $r['bytes'], $r['bloqueado']]);
        $ok++;
    } catch (PDOException $e) {
        // domínio inválido ou duplicata de corrida — ignora
    }
}

echo date('Y-m-d H:i:s') . " — {$ok} registros inseridos/atualizados.\n";

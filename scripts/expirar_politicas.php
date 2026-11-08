<?php
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\{Database, Tarefas, Auditoria, Politicas};

$pdo = null;
try {
    $pdo = Database::conectar();
    $pdo->beginTransaction();
    $pdo->query('SELECT id FROM fila_expiracao_politicas WHERE id=1 FOR UPDATE')->fetch();
    $expirados = $pdo->query(
        'SELECT id, expira_em, restaurar_ips FROM ips
         WHERE ativo=1 AND expira_em IS NOT NULL AND expira_em<=NOW()
         ORDER BY expira_em, id LIMIT 500 FOR UPDATE'
    )->fetchAll();

    $desativar = $pdo->prepare('UPDATE ips SET ativo=0 WHERE id=?');
    foreach ($expirados as $ip) {
        $desativar->execute([(int) $ip['id']]);
        Politicas::restaurar($pdo, (string) $ip['restaurar_ips']);
    }
    if ($expirados) {
        $pdo->exec('UPDATE fila_expiracao_politicas
                    SET pendente=1, tarefa_firewall=NULL, tarefa_squid=NULL WHERE id=1');
    }
    $pdo->commit();

    foreach ($expirados as $ip) {
        Auditoria::registrar('expirar_politica', 'ips', (int) $ip['id'],
            ['ativo' => 1, 'expira_em' => $ip['expira_em']], ['ativo' => 0]);
    }

    $pdo->beginTransaction();
    $fila = $pdo->query('SELECT * FROM fila_expiracao_politicas WHERE id=1 FOR UPDATE')->fetch();
    if ($fila && $fila['pendente']) {
        $confirmadas = true;
        foreach (['tarefa_firewall' => 'firewall_apply', 'tarefa_squid' => 'hours_squid'] as $campo => $tipo) {
            $tarefa = $fila[$campo] ? Tarefas::obter($fila[$campo]) : null;
            $estado = $tarefa['status'] ?? null;
            if ($estado === 'succeeded') continue;
            $confirmadas = false;
            if (in_array($estado, ['queued', 'running'], true)) continue;
            $id = Tarefas::enfileirar($tipo, [], null, $tipo);
            $pdo->prepare("UPDATE fila_expiracao_politicas SET $campo=? WHERE id=1")->execute([$id]);
        }
        if ($confirmadas) {
            $pdo->exec('UPDATE fila_expiracao_politicas
                        SET pendente=0, tarefa_firewall=NULL, tarefa_squid=NULL WHERE id=1');
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n");
    exit(1);
}
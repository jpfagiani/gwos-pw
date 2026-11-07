#!/usr/bin/php
<?php
// Worker cron do painel. A fila aceita somente ações allowlisted em Tarefas.
require dirname(__DIR__) . '/bootstrap/app.php';

try {
    \App\Core\Tarefas::processarFila(5);
} catch (Throwable) {
    // Não grava saída bruta em uma superfície web; cron/journal contém apenas
    // um código de falha genérico quando o worker não consegue iniciar.
    exit(1);
}

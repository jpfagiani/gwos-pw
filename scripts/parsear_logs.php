<?php
/**
 * Recalcula o agregado diário a partir do registro canônico de acessos importado.
 * Repetir a execução não duplica contagens; o importador do Squid é responsável
 * pela leitura idempotente do access.log.
 */

define('BASE_PATH', dirname(__DIR__));
$lock=fopen('/tmp/gwos_parsear_logs.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
$envFile=BASE_PATH.'/.env';
if(is_file($envFile)) foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $linha) {
    if(str_starts_with(trim($linha),'#')||!str_contains($linha,'='))continue;
    [$chave,$valor]=explode('=',$linha,2); $_ENV[trim($chave)]=trim($valor);
}
$env=static fn(string $k,string $d='')=>$_ENV[$k]??(getenv($k)?:$d);
try {
    $pdo=new PDO('mysql:host='.$env('DB_HOST','127.0.0.1').';dbname='.$env('DB_BANCO','gwos').';charset=utf8mb4',$env('DB_USUARIO','gwos'),$env('DB_SENHA',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $data=date('Y-m-d'); $inicio=$data.' 00:00:00'; $fim=(new DateTimeImmutable('tomorrow'))->format('Y-m-d').' 00:00:00';
    $pdo->beginTransaction();
    $del=$pdo->prepare('DELETE FROM relatorio_diario WHERE data=?'); $del->execute([$data]);
    $sql="INSERT INTO relatorio_diario (data,ip_cliente,dominio,acessos,bytes,bloqueado)
          SELECT ?,ip,dominio,COUNT(*),COALESCE(SUM(bytes),0),MAX(CASE WHEN status LIKE '%DENIED%' OR status LIKE '%BLOCKED%' THEN 1 ELSE 0 END)
          FROM acessos_internet WHERE ocorrido_em>=? AND ocorrido_em<? GROUP BY ip,dominio";
    $stmt=$pdo->prepare($sql); $stmt->execute([$data,$inicio,$fim]);
    $contagem=$stmt->rowCount(); $pdo->commit();
    echo date('Y-m-d H:i:s')." — agregado diário recalculado a partir de {$contagem} grupos.\n";
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,'['.date('c').'] '.$e->getMessage()."\n"); exit(1);
}

<?php

namespace App\Core;

class Auditoria
{
    public static function registrar(
        string $acao,
        string $tabela,
        ?int $registro_id,
        mixed $antes,
        mixed $depois
    ): void {
        $antes = self::sanitizar($antes);
        $depois = self::sanitizar($depois);
        try {
            Database::execute(
                'INSERT INTO auditoria (admin_id, acao, tabela, registro_id, dados_antes, dados_depois, ip, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    Auth::id(),
                    $acao,
                    $tabela,
                    $registro_id,
                    $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                    $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ]
            );
        } catch (\Throwable) {
            // Auditoria não pode quebrar o fluxo principal
        }
    }

    private static function sanitizar(mixed $dados): mixed
    {
        if (!is_array($dados)) return $dados;
        $limpo = [];
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match('/senha|password|token|secret|segredo|private|chave_privada/i', $chave)) {
                $limpo[$chave] = '[REDACTED]';
            } else {
                $limpo[$chave] = self::sanitizar($valor);
            }
        }
        return $limpo;
    }
}

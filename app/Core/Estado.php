<?php

namespace App\Core;

/**
 * GWOS — Leitura e escrita do estado compartilhado (/etc/gwos/gwos.conf).
 *
 * Leitura é direta (o arquivo é 644). Escrita NUNCA é: o painel roda como
 * www-data e chama 'sudo gwos-definir <chave> <valor>', que tem lista fechada
 * de chaves, valida o valor e só então grava e reconfigura os serviços.
 *
 * Toda tela de módulo passa por aqui — nenhuma escreve em /etc por conta
 * própria. É o que mantém o painel como editor validado em vez de execução
 * remota de comando como root.
 */
class Estado
{
    private const ARQUIVO  = '/etc/gwos/gwos.conf';
    private const DEFINIR  = '/usr/local/sbin/gwos-definir';

    private static ?array $cache = null;

    /** Todo o gwos.conf como array chave => valor. */
    public static function tudo(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $conf = [];
        foreach (@file(self::ARQUIVO, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            $conf[trim($chave)] = trim($valor, " \t\"'");
        }

        return self::$cache = $conf;
    }

    public static function obter(string $chave, ?string $padrao = null): ?string
    {
        $valor = self::tudo()[$chave] ?? null;
        return ($valor === null || $valor === '') ? $padrao : $valor;
    }

    /** Valor que é lista separada por espaço (forwarders, servidores NTP). */
    public static function lista(string $chave): array
    {
        $valor = self::obter($chave, '');
        return $valor === '' ? [] : preg_split('/\s+/', $valor, -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function existe(): bool
    {
        return is_readable(self::ARQUIVO);
    }

    /**
     * Grava uma chave via gwos-definir.
     *
     * @return array{ok: bool, saida: string}
     */
    public static function definir(string $chave, string $valor): array
    {
        if (!is_executable(self::DEFINIR)) {
            return ['ok' => false, 'saida' => 'gwos-definir não encontrado — reinstale o módulo.'];
        }

        // A validação de verdade é a do gwos-definir. Esta é só a primeira
        // barreira, para o painel não chamar o sudo com lixo óbvio.
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $chave)) {
            return ['ok' => false, 'saida' => 'Chave inválida.'];
        }
        if (preg_match('/[\r\n#]/', $valor)) {
            return ['ok' => false, 'saida' => 'Valor contém caractere não permitido.'];
        }

        $cmd = 'sudo ' . escapeshellarg(self::DEFINIR) . ' '
             . escapeshellarg($chave) . ' ' . escapeshellarg($valor) . ' 2>&1';

        $saida  = [];
        $codigo = 1;
        @exec($cmd, $saida, $codigo);

        self::$cache = null;   // o arquivo mudou

        return [
            'ok'    => ($codigo === 0),
            'saida' => trim(implode("\n", $saida)),
        ];
    }

    /** Grava uma chave a partir de uma lista (junta com espaço). */
    public static function definirLista(string $chave, array $valores): array
    {
        $limpos = array_values(array_filter(array_map('trim', $valores), fn($v) => $v !== ''));
        return self::definir($chave, implode(' ', $limpos));
    }

    /** Redes internas conhecidas — principal e, se houver, a secundária. */
    public static function redesInternas(): array
    {
        $redes = [];
        if ($principal = self::obter('REDE_LAN')) {
            $redes[] = $principal;
        }
        if (self::obter('REDE2_ATIVO') === '1' && ($secundaria = self::obter('REDE2_CIDR'))) {
            $redes[] = $secundaria;
        }
        return $redes;
    }
}

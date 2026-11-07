<?php

namespace App\Core;

/**
 * GWOS — Servidor de hora (módulo 30-hora-chrony).
 *
 * As fontes vivem em NTP_SERVIDORES / NTP_POOL do gwos.conf e são escritas por
 * gwos-definir; o gwos-integrar transforma isso em /etc/chrony/conf.d/gwos.conf
 * junto com o 'allow' das redes internas.
 *
 * 'chronyc tracking' e 'chronyc sources' respondem para qualquer usuário.
 * 'chronyc clients' precisa do socket de comando, que é do root — essa vai
 * pelo gwos-servico.
 */
class Hora
{
    private const CONF = '/etc/chrony/conf.d/gwos.conf';

    /** @return string[] */
    public static function servidores(): array
    {
        return Estado::lista('NTP_SERVIDORES');
    }

    /** @return string[] */
    public static function pool(): array
    {
        return Estado::lista('NTP_POOL');
    }

    /** @param string[] $valores @return array{ok:bool,saida:string} */
    public static function definirServidores(array $valores): array
    {
        foreach ($valores as $v) {
            if (!self::validaFonte($v)) {
                return ['ok' => false, 'saida' => "Fonte inválida: {$v}"];
            }
        }
        if ($valores === []) {
            return ['ok' => false, 'saida' => 'Informe ao menos um servidor de hora.'];
        }
        return Estado::definirLista('NTP_SERVIDORES', $valores);
    }

    /** @param string[] $valores @return array{ok:bool,saida:string} */
    public static function definirPool(array $valores): array
    {
        foreach ($valores as $v) {
            if (!self::validaFonte($v)) {
                return ['ok' => false, 'saida' => "Pool inválido: {$v}"];
            }
        }
        return Estado::definirLista('NTP_POOL', $valores);
    }

    public static function validaFonte(string $valor): bool
    {
        if (filter_var($valor, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        return (bool) preg_match(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/',
            $valor
        );
    }

    /** Redes autorizadas a sincronizar, lidas do arquivo em vigor. */
    public static function redesLiberadas(): array
    {
        $redes = [];
        foreach (@file(self::CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            if (preg_match('/^\s*allow\s+(\S+)/', $linha, $m)) {
                $redes[] = $m[1];
            }
        }
        return $redes;
    }

    /**
     * Estado da sincronização.
     * @return array<string,string>
     */
    public static function tracking(): array
    {
        if (!self::temChronyc()) {
            return [];
        }

        $saida = [];
        @exec('chronyc tracking 2>/dev/null', $saida);

        $dados = [];
        foreach ($saida as $linha) {
            if (str_contains($linha, ':')) {
                [$chave, $valor] = explode(':', $linha, 2);
                $dados[trim($chave)] = trim($valor);
            }
        }
        return $dados;
    }

    /**
     * Fontes de tempo com o estado de cada uma.
     * @return array<int,array{estado:string,nome:string,estrato:string,ultimo:string}>
     */
    public static function fontes(): array
    {
        if (!self::temChronyc()) {
            return [];
        }

        $saida = [];
        @exec('chronyc -n sources 2>/dev/null', $saida);

        $fontes = [];
        foreach ($saida as $linha) {
            // ^* 200.160.7.186   1  10   377   45m  -1234us[...]
            if (!preg_match('/^(.)(.)\s+(\S+)\s+(\d+)\s+\d+\s+\S+\s+(\S+)/', $linha, $m)) {
                continue;
            }
            $fontes[] = [
                'estado'  => self::descreverEstado($m[2]),
                'nome'    => $m[3],
                'estrato' => $m[4],
                'ultimo'  => $m[5],
            ];
        }
        return $fontes;
    }

    /** Clientes que sincronizam com este gateway (precisa de root). */
    public static function clientes(): array
    {
        return Servico::estado('hora');
    }

    public static function temChronyc(): bool
    {
        $saida = [];
        $codigo = 1;
        @exec('command -v chronyc 2>/dev/null', $saida, $codigo);
        return $codigo === 0;
    }

    private static function descreverEstado(string $simbolo): string
    {
        return match ($simbolo) {
            '*'     => 'em uso',
            '+'     => 'aceitável',
            '-'     => 'descartada',
            '?'     => 'inalcançável',
            'x'     => 'inconsistente',
            '~'     => 'instável',
            default => $simbolo,
        };
    }
}

<?php

namespace App\Core;

/**
 * GWOS — Operações que precisam de root, via /usr/local/sbin/gwos-servico.
 *
 * Ver o ruleset do nftables, saber quem sincroniza a hora ou mandar o Squid
 * reler a configuração exige root, e o painel roda como www-data. Em vez de
 * dar 'nft' ou 'squid' inteiros ao sudoers, tudo passa por um par
 * (ação, módulo) de lista fechada no script — não há argumento livre.
 */
class Servico
{
    private const BIN = '/usr/local/sbin/gwos-servico';

    private const ACOES = [
        'estado'     => ['firewall', 'proxy', 'dns', 'hora'],
        'recarregar' => ['firewall', 'proxy', 'dns'],
    ];

    /** @return array{ok:bool,saida:string} */
    public static function estado(string $alvo): array
    {
        return self::executar('estado', $alvo);
    }

    /** @return array{ok:bool,saida:string} */
    public static function recarregar(string $alvo): array
    {
        return self::executar('recarregar', $alvo);
    }

    /** @return array{ok:bool,saida:string} */
    private static function executar(string $acao, string $alvo): array
    {
        if (!in_array($alvo, self::ACOES[$acao] ?? [], true)) {
            return ['ok' => false, 'saida' => "Operação não permitida: {$acao} {$alvo}"];
        }
        if (!is_executable(self::BIN)) {
            return ['ok' => false, 'saida' => 'gwos-servico não encontrado — reinstale os módulos.'];
        }

        $cmd = 'sudo ' . escapeshellarg(self::BIN) . ' '
             . escapeshellarg($acao) . ' ' . escapeshellarg($alvo) . ' 2>&1';

        $saida  = [];
        $codigo = 1;
        @exec($cmd, $saida, $codigo);

        return ['ok' => ($codigo === 0), 'saida' => implode("\n", $saida)];
    }
}

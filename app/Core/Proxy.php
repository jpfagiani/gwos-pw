<?php

namespace App\Core;

/**
 * GWOS — Proxy Squid (módulo 50-proxy-squid).
 *
 * As portas vêm do gwos.conf — as mesmas que o firewall usa no
 * redirecionamento — e por isso são escritas por gwos-definir, com o
 * gwos-integrar regerando os dois lados juntos. Editar a porta só no Squid
 * deixaria o nftables mandando tráfego para porta morta.
 *
 * As listas de IPs e domínios são geradas pelo painel a partir do banco
 * (grupos, domínios, horários) — aqui elas aparecem só para conferência.
 */
class Proxy
{
    private const CONF_D  = '/etc/squid/conf.d';
    private const CA      = '/etc/squid/ssl_cert/gwos-ca.crt';
    private const ACCESS  = '/var/log/squid/access.log';

    /** @return array<string,string> */
    public static function portas(): array
    {
        return [
            'explícito (forward-proxy)' => Estado::obter('SQUID_PORTA_FWD', '3127'),
            'HTTP transparente'         => Estado::obter('SQUID_PORTA', '3128'),
            'HTTPS (SSL Bump)'          => Estado::obter('SQUID_PORTA_SSL', '3129'),
        ];
    }

    /** @return array{ok:bool,saida:string} */
    public static function definirPorta(string $chave, string $valor): array
    {
        if (!in_array($chave, ['SQUID_PORTA', 'SQUID_PORTA_SSL', 'SQUID_PORTA_FWD'], true)) {
            return ['ok' => false, 'saida' => 'Porta desconhecida.'];
        }
        if (!ctype_digit($valor) || (int) $valor < 1 || (int) $valor > 65535) {
            return ['ok' => false, 'saida' => "Porta inválida: {$valor}"];
        }
        return Estado::definir($chave, $valor);
    }

    public static function sslBumpAtivo(): bool
    {
        return is_readable(self::CA);
    }

    /** Validade da CA, como o openssl devolve. */
    public static function validadeCa(): ?string
    {
        if (!self::sslBumpAtivo()) {
            return null;
        }
        $saida = [];
        @exec('openssl x509 -in ' . escapeshellarg(self::CA) . ' -noout -enddate 2>/dev/null', $saida);
        $linha = $saida[0] ?? '';
        return str_contains($linha, '=') ? trim(explode('=', $linha, 2)[1]) : null;
    }

    /**
     * As listas que o squid.conf inclui, com quantas entradas cada uma tem.
     * Uma lista ausente impede o Squid de subir — por isso a tela mostra todas.
     *
     * @return array<int,array{arquivo:string,descricao:string,existe:bool,entradas:int}>
     */
    public static function listas(): array
    {
        $catalogo = [
            'gwos_ips_liberados.txt'  => 'IPs com acesso total',
            'gwos_ips_parciais.txt'   => 'IPs com restrição de blacklist e horário',
            'gwos_ips_bloqueados.txt' => 'IPs restritos à whitelist',
            'gwos_ips_livres.txt'     => 'IPs que não passam pelo SSL Bump',
            'gwos_whitelist.txt'      => 'Domínios sempre liberados',
            'gwos_blacklist.txt'      => 'Domínios bloqueados',
            'gwos_sites_livres.txt'   => 'Domínios sem inspeção',
            'gwos_horarios.conf'      => 'Regras de horário',
            'gwos_redes.conf'         => 'ACLs das redes internas',
            'gwos_integracao.conf'    => 'Resolver DNS',
            'gwos_portas.conf'        => 'Portas de escuta',
            'gwos_tcp_outgoing.conf'  => 'NAT 1:1 por cliente',
        ];

        $listas = [];
        foreach ($catalogo as $arquivo => $descricao) {
            $caminho = self::CONF_D . '/' . $arquivo;
            $existe  = is_file($caminho);
            $listas[] = [
                'arquivo'   => $arquivo,
                'descricao' => $descricao,
                'existe'    => $existe,
                'entradas'  => $existe ? self::contarEntradas($caminho) : 0,
            ];
        }
        return $listas;
    }

    /** @return array{ok:bool,saida:string} */
    public static function verificarConfiguracao(): array
    {
        return Servico::estado('proxy');
    }

    /** @return array{ok:bool,saida:string} */
    public static function recarregar(): array
    {
        return Servico::recarregar('proxy');
    }

    /** Últimas linhas do access.log — exige www-data no grupo proxy. */
    public static function ultimosAcessos(int $quantas = 15): array
    {
        if (!is_readable(self::ACCESS)) {
            return [];
        }

        $saida = [];
        @exec('tail -n ' . (int) $quantas . ' ' . escapeshellarg(self::ACCESS) . ' 2>/dev/null', $saida);
        return array_reverse($saida);
    }

    public static function logLegivel(): bool
    {
        return is_readable(self::ACCESS);
    }

    private static function contarEntradas(string $caminho): int
    {
        $total = 0;
        foreach (@file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha !== '' && !str_starts_with($linha, '#')) {
                $total++;
            }
        }
        return $total;
    }
}

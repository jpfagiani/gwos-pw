<?php

namespace App\Core;

/**
 * GWOS — Leitura e escrita do módulo 20-dns-bind9.
 *
 * Leitura é direta dos arquivos do BIND (todos legíveis). Escrita passa por
 * gwos-definir (forwarders) e gwos-zona (zonas), ambos no sudoers e ambos
 * validando com named-checkconf antes de valer. O painel nunca escreve em
 * /etc/bind nem recarrega o named por conta própria.
 */
class Dns
{
    private const ZONA        = '/usr/local/sbin/gwos-zona';
    private const ZONAS_PROJETO = '/etc/bind/named.conf.local';

    // -----------------------------------------------------------------
    // Resolvers upstream
    // -----------------------------------------------------------------

    /** @return string[] */
    public static function forwarders(): array
    {
        return Estado::lista('DNS_FORWARDERS');
    }

    /** @param string[] $ips @return array{ok:bool,saida:string} */
    public static function definirForwarders(array $ips): array
    {
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return ['ok' => false, 'saida' => "IP inválido: {$ip}"];
            }
        }
        if ($ips === []) {
            return ['ok' => false, 'saida' => 'Informe ao menos um resolver.'];
        }
        return Estado::definirLista('DNS_FORWARDERS', $ips);
    }

    // -----------------------------------------------------------------
    // Zonas
    // -----------------------------------------------------------------

    /**
     * Zonas de encaminhamento do administrador.
     * @return array<int,array{dominio:string,forwarders:string[]}>
     */
    public static function zonas(): array
    {
        if (!is_executable(self::ZONA)) {
            return [];
        }

        $saida = [];
        @exec(escapeshellarg(self::ZONA) . ' listar 2>/dev/null', $saida);

        $zonas = [];
        foreach ($saida as $linha) {
            if (!str_contains($linha, "\t")) {
                continue;
            }
            [$dominio, $ips] = explode("\t", $linha, 2);
            $zonas[] = [
                'dominio'    => trim($dominio),
                'forwarders' => preg_split('/\s+/', trim($ips), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ];
        }
        return $zonas;
    }

    /**
     * Zonas que vêm do repositório (named.conf.local) — só leitura no painel.
     * São as do governo, versionadas junto com o módulo.
     * @return array<int,array{dominio:string,forwarders:string[]}>
     */
    public static function zonasDoProjeto(): array
    {
        if (!is_readable(self::ZONAS_PROJETO)) {
            return [];
        }

        $conteudo = (string) @file_get_contents(self::ZONAS_PROJETO);
        $zonas    = [];

        if (preg_match_all(
            '/zone\s+"([^"]+)"\s*\{(.*?)\};/s',
            $conteudo,
            $blocos,
            PREG_SET_ORDER
        )) {
            foreach ($blocos as $bloco) {
                if (!str_contains($bloco[2], 'forward')) {
                    continue;   // ignora a zona RPZ, que é master
                }
                $ips = [];
                if (preg_match('/forwarders\s*\{([^}]*)\}/', $bloco[2], $m)) {
                    $ips = preg_split('/[\s;]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                $zonas[] = ['dominio' => $bloco[1], 'forwarders' => $ips];
            }
        }
        return $zonas;
    }

    /** @param string[] $ips @return array{ok:bool,saida:string} */
    public static function adicionarZona(string $dominio, array $ips): array
    {
        return self::chamarZona(['adicionar', $dominio, implode(' ', $ips)]);
    }

    /** @return array{ok:bool,saida:string} */
    public static function removerZona(string $dominio): array
    {
        return self::chamarZona(['remover', $dominio]);
    }

    /** @return array{ok:bool,saida:string} */
    private static function chamarZona(array $args): array
    {
        if (!is_executable(self::ZONA)) {
            return ['ok' => false, 'saida' => 'gwos-zona não encontrado — reinstale o módulo 20-dns-bind9.'];
        }

        $cmd = 'sudo ' . escapeshellarg(self::ZONA);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $saida  = [];
        $codigo = 1;
        @exec($cmd . ' 2>&1', $saida, $codigo);

        return ['ok' => ($codigo === 0), 'saida' => trim(implode("\n", $saida))];
    }

    // -----------------------------------------------------------------
    // Diagnóstico
    // -----------------------------------------------------------------

    /**
     * Resolve um nome por um servidor. Roda como www-data — dig não pede root.
     * @return array{ok:bool,respostas:string[],erro:?string}
     */
    public static function resolver(string $nome, string $servidor = '127.0.0.1', int $porta = 53): array
    {
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]{0,251}[a-zA-Z0-9])?$/', $nome)) {
            return ['ok' => false, 'respostas' => [], 'erro' => 'Nome inválido.'];
        }
        if (filter_var($servidor, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'respostas' => [], 'erro' => 'Servidor inválido.'];
        }
        if ($porta < 1 || $porta > 65535) {
            return ['ok' => false, 'respostas' => [], 'erro' => 'Porta inválida.'];
        }

        $cmd = sprintf(
            'dig +short +time=3 +tries=1 %s @%s -p %d 2>&1',
            escapeshellarg($nome),
            escapeshellarg($servidor),
            $porta
        );

        $saida  = [];
        $codigo = 1;
        @exec($cmd, $saida, $codigo);

        $respostas = array_values(array_filter(array_map('trim', $saida), fn($l) => $l !== ''));
        $enderecos = array_values(array_filter(
            $respostas,
            fn($l) => filter_var($l, FILTER_VALIDATE_IP) !== false
        ));

        return [
            'ok'        => ($codigo === 0 && $enderecos !== []),
            'respostas' => $respostas,
            'erro'      => ($enderecos === [] ? 'Sem resposta com endereço.' : null),
        ];
    }

    /**
     * Testa cada forwarder resolvendo um domínio externo.
     *
     * O BIND só cai para o próximo forwarder em timeout ou SERVFAIL. NXDOMAIN
     * é resposta válida e encerra a busca — um resolver interno que negue
     * domínios externos derruba a internet da rede inteira sem aviso. Este
     * teste é o que revela isso antes de acontecer.
     *
     * @return array<int,array{ip:string,ok:bool}>
     */
    public static function testarForwarders(string $dominio = 'google.com'): array
    {
        $resultado = [];
        foreach (self::forwarders() as $ip) {
            $teste = self::resolver($dominio, $ip);
            $resultado[] = ['ip' => $ip, 'ok' => $teste['ok']];
        }
        return $resultado;
    }

    /** Domínios bloqueados na zona RPZ (dois registros por domínio). */
    public static function totalBloqueados(): int
    {
        $arquivo = '/etc/bind/db.rpz.gwos';
        if (!is_readable($arquivo)) {
            return 0;
        }
        $conteudo = (string) @file_get_contents($arquivo);
        return (int) floor(substr_count($conteudo, 'IN CNAME .') / 2);
    }

    /** A RPZ só bloqueia de fato se o response-policy estiver nas options. */
    public static function rpzAtiva(): bool
    {
        $arquivo = '/etc/bind/named.conf.options';
        return is_readable($arquivo)
            && str_contains((string) @file_get_contents($arquivo), 'response-policy');
    }
}

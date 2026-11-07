<?php

namespace App\Core;

/**
 * GWOS — Nomes internos da LAN (módulo 25-dns-interno-dnsmasq).
 *
 * Os nomes vivem em /etc/hosts, marcados com "# gwos-dns", e são servidos pelo
 * dnsmasq. Leitura é direta do arquivo; escrita passa por
 * scripts/aplicar_dns_hosts.sh — o mesmo que o comando 'gwos dns' usa, já no
 * sudoers e já responsável por recarregar o dnsmasq.
 */
class Nomes
{
    private const HOSTS  = '/etc/hosts';
    private const MARCA  = '# gwos-dns';

    private static function script(): ?string
    {
        $caminho = BASE_PATH . '/scripts/aplicar_dns_hosts.sh';
        return is_file($caminho) ? $caminho : null;
    }

    /**
     * @return array<int,array{ip:string,host:string,fqdn:string}>
     */
    public static function listar(): array
    {
        $nomes = [];
        foreach (@file(self::HOSTS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            if (!str_contains($linha, self::MARCA)) {
                continue;
            }
            $campos = preg_split('/\s+/', trim(str_replace(self::MARCA, '', $linha)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($campos) < 2) {
                continue;
            }
            $nomes[] = [
                'ip'   => $campos[0],
                'host' => $campos[1],
                'fqdn' => $campos[2] ?? $campos[1],
            ];
        }
        return $nomes;
    }

    public static function existe(string $host): bool
    {
        foreach (self::listar() as $nome) {
            if (strcasecmp($nome['host'], $host) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok:bool,saida:string} */
    public static function adicionar(string $host, string $ip): array
    {
        return self::chamar(['add', $host, $ip]);
    }

    /** @return array{ok:bool,saida:string} */
    public static function atualizar(string $host, string $ip): array
    {
        return self::chamar(['update', $host, $ip]);
    }

    /** @return array{ok:bool,saida:string} */
    public static function remover(string $host): array
    {
        return self::chamar(['del', $host]);
    }

    public static function validaHost(string $host): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $host);
    }

    /** @return array{ok:bool,saida:string} */
    private static function chamar(array $args): array
    {
        $script = self::script();
        if ($script === null) {
            return ['ok' => false, 'saida' => 'aplicar_dns_hosts.sh não encontrado.'];
        }

        // Valida antes de chegar ao sudo — o script recebe os campos crus
        $host = $args[1] ?? '';
        if (!self::validaHost($host)) {
            return ['ok' => false, 'saida' => "Nome inválido: '{$host}'. Use apenas letras, números e hífen."];
        }
        if (isset($args[2]) && filter_var($args[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'saida' => "IP inválido: '{$args[2]}'."];
        }

        $cmd = 'sudo ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $saida  = [];
        $codigo = 1;
        @exec($cmd . ' 2>&1', $saida, $codigo);

        return ['ok' => ($codigo === 0), 'saida' => trim(implode("\n", $saida))];
    }
}

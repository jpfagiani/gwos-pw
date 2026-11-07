<?php

namespace App\Core;

/**
 * GWOS — Registro dos módulos instalados nesta máquina.
 *
 * Lê /etc/gwos/modulos.d/, que os instaladores de modulos/ escrevem. É daqui
 * que o painel descobre o que existe: instalou o chrony, o arquivo aparece e
 * a seção surge no menu na próxima página — sem script de atualização.
 *
 * Nenhuma tela toca o disco direto; tudo passa por aqui. É o que permite,
 * mais adiante, trocar a leitura local por uma chamada remota sem reescrever
 * as telas.
 */
class Modulos
{
    private const DIR_REGISTRO = '/etc/gwos/modulos.d';

    /** Marcador em modulos.d => metadados da seção no painel. */
    private const CATALOGO = [
        'base' => [
            'pasta'    => '00-base',
            'titulo'   => 'Rede',
            'icone'    => 'bi-hdd-network',
            'secao'    => 'Rede',
            'rota'     => '/modulos/rede',
            'tela'     => false,   // vira true quando a tela do modulo existir
            'servicos' => [],
            'resumo'   => 'Interfaces, IP do gateway e domínio interno',
        ],
        'banco-mariadb' => [
            'pasta'    => '10-banco-mariadb',
            'titulo'   => 'Banco de dados',
            'icone'    => 'bi-database',
            'secao'    => 'Sistema',
            'rota'     => null,    // sem tela própria
            'tela'     => false,
            'servicos' => ['mariadb'],
            'resumo'   => 'Grupos, domínios, horários e relatórios',
        ],
        'dns-bind9' => [
            'pasta'    => '20-dns-bind9',
            'titulo'   => 'DNS',
            'icone'    => 'bi-signpost-split',
            'secao'    => 'Rede',
            'rota'     => '/modulos/dns',
            'tela'     => true,
            'servicos' => ['named'],
            'resumo'   => 'Resolvers, zonas e bloqueio por domínio',
        ],
        'dns-interno' => [
            'pasta'    => '25-dns-interno-dnsmasq',
            'titulo'   => 'Nomes internos',
            'icone'    => 'bi-tag',
            'secao'    => 'Rede',
            'rota'     => '/modulos/nomes',
            'tela'     => true,
            'servicos' => ['gwos-dnsmasq'],
            'resumo'   => 'Vincular um nome a um IP da LAN',
        ],
        'hora-chrony' => [
            'pasta'    => '30-hora-chrony',
            'titulo'   => 'Hora',
            'icone'    => 'bi-clock-history',
            'secao'    => 'Rede',
            'rota'     => '/modulos/hora',
            'tela'     => true,
            'servicos' => ['chrony'],
            'resumo'   => 'Servidores NTP e quem sincroniza',
        ],
        'firewall-nftables' => [
            'pasta'    => '40-firewall-nftables',
            'titulo'   => 'Firewall',
            'icone'    => 'bi-shield-lock',
            'secao'    => 'Rede',
            'rota'     => '/modulos/firewall',
            'tela'     => true,
            'servicos' => ['nftables'],
            'resumo'   => 'Regras, NAT e redirecionamentos',
        ],
        'proxy-squid' => [
            'pasta'    => '50-proxy-squid',
            'titulo'   => 'Proxy',
            'icone'    => 'bi-globe',
            'secao'    => 'Rede',
            'rota'     => '/modulos/proxy',
            'tela'     => true,
            'servicos' => ['squid'],
            'resumo'   => 'Portas, listas e certificado da CA',
        ],
        'painel-web' => [
            'pasta'    => '60-painel-web',
            'titulo'   => 'Painel',
            'icone'    => 'bi-window',
            'secao'    => 'Sistema',
            'rota'     => null,
            'tela'     => false,   // vira true quando a tela do modulo existir
            // O serviço do PHP muda com a distribuição (php8.2-fpm no Debian
            // 12, php8.4-fpm no 13) — resolvido em instalados().
            'servicos' => ['nginx', 'php-fpm'],
            'resumo'   => 'Este painel',
        ],
    ];

    private static ?array $cache = null;

    /**
     * Módulos instalados, na ordem do catálogo.
     * Cada item traz os metadados mais 'marcador' e 'instalado_em'.
     */
    public static function instalados(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $lista = [];
        foreach (self::CATALOGO as $marcador => $meta) {
            $arquivo = self::DIR_REGISTRO . '/' . $marcador;
            if (!is_file($arquivo)) {
                continue;
            }
            $meta['marcador']     = $marcador;
            $meta['instalado_em'] = self::lerCampo($arquivo, 'instalado_em');
            $meta['servicos']     = self::resolverServicos($meta['servicos']);
            $lista[$marcador]     = $meta;
        }

        return self::$cache = $lista;
    }

    public static function temModulo(string $marcador): bool
    {
        return isset(self::instalados()[$marcador]);
    }

    public static function info(string $marcador): ?array
    {
        return self::instalados()[$marcador] ?? null;
    }

    /**
     * Seções do menu: ['Rede' => [modulo, ...], 'Sistema' => [...]].
     * Só entram módulos instalados cuja tela já existe — enquanto 'tela' for
     * false o módulo aparece apenas na visão geral, sem link quebrado no menu.
     */
    public static function menu(): array
    {
        $secoes = [];
        foreach (self::instalados() as $modulo) {
            if ($modulo['rota'] === null || $modulo['tela'] !== true) {
                continue;
            }
            $secoes[$modulo['secao']][] = $modulo;
        }
        return $secoes;
    }

    /**
     * Estado dos serviços de um módulo: ['named' => true, ...].
     * Usa systemctl is-active, que não precisa de root.
     */
    public static function statusServicos(string $marcador): array
    {
        $modulo = self::info($marcador);
        if (!$modulo) {
            return [];
        }

        $estado = [];
        foreach ($modulo['servicos'] as $servico) {
            $saida = null;
            $codigo = 1;
            @exec('systemctl is-active ' . escapeshellarg($servico) . ' 2>/dev/null', $saida, $codigo);
            $estado[$servico] = ($codigo === 0);
        }
        return $estado;
    }

    /**
     * Módulos do catálogo que ainda não foram instalados, com o comando de
     * instalação. É o que mostra ao administrador o que dá para acrescentar.
     */
    public static function naoInstalados(): array
    {
        $lista = [];
        foreach (self::CATALOGO as $marcador => $meta) {
            if (isset(self::instalados()[$marcador])) {
                continue;
            }
            $meta['marcador'] = $marcador;
            $meta['comando']  = 'bash modulos/' . $meta['pasta'] . '/instalar.sh';
            $lista[$marcador] = $meta;
        }
        return $lista;
    }

    /**
     * O banco é opcional: sem ele o painel roda em modo leve.
     *
     * NÃO testa /etc/gwos/db.conf: aquele arquivo é 0600 root, e o painel roda
     * como www-data — is_readable() devolvia false mesmo com o MariaDB
     * instalado. O painel então caía no modo leve, procurava o arquivo de
     * usuários que não existia e recusava todo login como "senha incorreta",
     * sem nunca consultar o banco.
     *
     * Quem manda é o que o painel realmente usa para conectar: as credenciais
     * do .env, que o instalador grava legíveis para o www-data.
     */
    public static function temBanco(): bool
    {
        if (!self::temModulo('banco-mariadb')) {
            return false;
        }
        return (string) config('db.senha', '') !== '';
    }

    /**
     * Substitui nomes de serviço genéricos pelo nome real desta máquina.
     * Hoje só o PHP-FPM precisa disso: o Debian 12 traz php8.2-fpm e o 13
     * traz php8.4-fpm, e fixar a versão faria o painel reportar "parado"
     * para um serviço que nem existe com aquele nome.
     *
     * @param string[] $servicos
     * @return string[]
     */
    private static function resolverServicos(array $servicos): array
    {
        $resolvidos = [];
        foreach ($servicos as $servico) {
            if ($servico !== 'php-fpm') {
                $resolvidos[] = $servico;
                continue;
            }
            $versao = self::versaoPhp();
            if ($versao !== null) {
                $resolvidos[] = "php{$versao}-fpm";
            }
        }
        return $resolvidos;
    }

    /** Versão do PHP-FPM instalada, de /etc/gwos/gwos.conf ou de /etc/php. */
    public static function versaoPhp(): ?string
    {
        $versao = Estado::obter('PHP_VERSAO');
        if ($versao !== null && $versao !== '') {
            return $versao;
        }

        foreach (glob('/etc/php/*/fpm') ?: [] as $dir) {
            return basename(dirname($dir));
        }

        // Último recurso: a versão do próprio processo que está servindo o painel
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    private static function lerCampo(string $arquivo, string $campo): ?string
    {
        foreach (@file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            if (str_starts_with($linha, $campo . '=')) {
                return trim(substr($linha, strlen($campo) + 1));
            }
        }
        return null;
    }
}

<?php

namespace App\Core;

/**
 * GWOS — Firewall (módulo 40-firewall-nftables).
 *
 * As regras NÃO são editadas aqui, e isso é de propósito: elas são derivadas
 * — do gwos.conf, dos módulos instalados e, quando há banco, dos grupos de IPs
 * e do NAT 1:1. Um formulário que escrevesse regra crua criaria uma segunda
 * fonte de verdade e a primeira regeneração a apagaria.
 *
 * Esta tela mostra o que está em vigor, explica de onde cada decisão veio e
 * permite regerar — que é a única ação que faz sentido.
 */
class Firewall
{
    /** @return array{ok:bool,saida:string} */
    public static function ruleset(): array
    {
        return Servico::estado('firewall');
    }

    /** @return array{ok:bool,saida:string} */
    public static function regerar(): array
    {
        return Servico::recarregar('firewall');
    }

    /**
     * De onde vem cada parte das regras — o que a tela precisa explicar.
     * @return array<int,array{item:string,situacao:bool,detalhe:string}>
     */
    public static function decisoes(): array
    {
        $ruleset = self::ruleset();
        $texto   = $ruleset['ok'] ? $ruleset['saida'] : '';

        $temSquid = Modulos::temModulo('proxy-squid');
        $temBind  = Modulos::temModulo('dns-bind9');
        $porta    = Estado::obter('SQUID_PORTA', '3128');
        $portaSsl = Estado::obter('SQUID_PORTA_SSL', '3129');

        return [
            [
                'item'     => 'Masquerade na saída',
                'situacao' => str_contains($texto, 'masquerade'),
                'detalhe'  => 'Sem isto a LAN não alcança a internet.',
            ],
            [
                'item'     => "Redireciona HTTP para o Squid (:{$porta})",
                'situacao' => str_contains($texto, "redirect to :{$porta}"),
                'detalhe'  => $temSquid
                    ? 'Módulo 50-proxy-squid instalado — o tráfego é filtrado.'
                    : 'Módulo 50-proxy-squid ausente — HTTP sai direto, sem filtro.',
            ],
            [
                'item'     => "Redireciona HTTPS para o Squid (:{$portaSsl})",
                'situacao' => str_contains($texto, "redirect to :{$portaSsl}"),
                'detalhe'  => Modulos::temModulo('proxy-squid')
                    ? 'Depende do SSL Bump: sem a CA, o HTTPS não é interceptado.'
                    : 'Módulo 50-proxy-squid ausente.',
            ],
            [
                'item'     => 'Força o DNS da LAN pelo resolver local',
                'situacao' => str_contains($texto, 'dport 53 redirect'),
                'detalhe'  => $temBind
                    ? 'Impede que um cliente troque o DNS e escape da RPZ.'
                    : 'Módulo 20-dns-bind9 ausente — o DNS da LAN sai direto.',
            ],
        ];
    }

    /** Sub-redes locais que são roteadas em vez de interceptadas. */
    public static function redesRoteadas(): array
    {
        $ruleset = self::ruleset();
        if (!$ruleset['ok']) {
            return [];
        }

        preg_match_all('/ip daddr ([0-9.]+\/[0-9]+) return/', $ruleset['saida'], $m);
        return array_values(array_unique($m[1] ?? []));
    }

    public static function encaminhamentoAtivo(): bool
    {
        $valor = @file_get_contents('/proc/sys/net/ipv4/ip_forward');
        return trim((string) $valor) === '1';
    }

    /** As regras sobrevivem ao reboot? */
    public static function persistente(): bool
    {
        $saida  = [];
        $codigo = 1;
        @exec('systemctl is-enabled nftables 2>/dev/null', $saida, $codigo);
        return $codigo === 0;
    }
}

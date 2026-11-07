#!/bin/bash
# ============================================================================
# GWOS — Operações de leitura e recarga dos serviços dos módulos
# Instalado como /usr/local/sbin/gwos-servico
# ============================================================================
# Algumas informações e ações precisam de root e o painel roda como www-data:
# ver o ruleset do nftables, saber quem sincroniza a hora, mandar o Squid
# reler a configuração. Em vez de dar 'nft' ou 'squid' inteiros ao sudoers,
# tudo passa por aqui, com um par (ação, módulo) de lista fechada.
#
#   gwos-servico estado     firewall|proxy|dns|hora
#   gwos-servico recarregar proxy|dns|firewall
#
# Nenhuma ação aceita argumento livre — não há como injetar parâmetro.
# Toda recarga valida a configuração antes (squid -k parse, named-checkconf).
# ============================================================================

set -uo pipefail

for _l in "/etc/gwos/lib.sh" \
          "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: lib.sh não encontrado." >&2; exit 1; }

ACAO="${1:-}"
ALVO="${2:-}"

uso() {
    echo "Uso: gwos-servico estado     <firewall|proxy|dns|hora>" >&2
    echo "     gwos-servico recarregar <firewall|proxy|dns>" >&2
    exit 1
}

exigir_root

case "${ACAO}:${ALVO}" in

    # ---------------------------------------------------------------- estado
    estado:firewall)
        command -v nft >/dev/null 2>&1 || { echo "nftables não instalado." >&2; exit 1; }
        nft list ruleset
        ;;

    estado:proxy)
        command -v squid >/dev/null 2>&1 || { echo "Squid não instalado." >&2; exit 1; }
        squid -k parse 2>&1 | tail -20
        ;;

    estado:dns)
        command -v named-checkconf >/dev/null 2>&1 || { echo "BIND9 não instalado." >&2; exit 1; }
        named-checkconf 2>&1 || true
        rndc status 2>/dev/null || true
        ;;

    estado:hora)
        command -v chronyc >/dev/null 2>&1 || { echo "chrony não instalado." >&2; exit 1; }
        # 'clients' exige o socket de comando, que é só do root — é por isso
        # que esta consulta passa por aqui em vez de sair direto do painel.
        chronyc clients 2>/dev/null || echo "(nenhum cliente registrou consulta ainda)"
        ;;

    # ------------------------------------------------------------ recarregar
    recarregar:proxy)
        squid -k parse >/dev/null 2>&1 || {
            echo "ERRO: squid -k parse reprovou — nada recarregado." >&2
            squid -k parse 2>&1 | tail -10 >&2
            exit 1
        }
        squid -k reconfigure && echo "[OK] Squid recarregado."
        ;;

    recarregar:dns)
        named-checkconf >/dev/null 2>&1 || {
            echo "ERRO: named-checkconf reprovou — nada recarregado." >&2
            named-checkconf 2>&1 >&2
            exit 1
        }
        { rndc reload || systemctl reload named; } >/dev/null 2>&1 \
            && echo "[OK] BIND9 recarregado."
        ;;

    recarregar:firewall)
        [ -x /usr/local/sbin/gwos-gerar-nftables ] || {
            echo "ERRO: gwos-gerar-nftables ausente." >&2; exit 1; }
        /usr/local/sbin/gwos-gerar-nftables
        ;;

    *)
        uso ;;
esac

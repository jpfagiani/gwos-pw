#!/bin/bash
# GWOS — Módulo 20-dns-bind9: desinstalação

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
carregar_conf
titulo "══ Removendo o módulo 20-dns-bind9 ══"

aviso "A LAN perde o resolver e o bloqueio de domínios por DNS."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

svc_parar named
rm -f /etc/bind/db.rpz.gwos \
      /etc/bind/named.conf.local \
      /etc/bind/named.conf.options \
      /etc/bind/named.conf.gwos-integracao \
      /etc/bind/named.conf.gwos-forwarders
rm -rf /var/log/named
ok "Configurações do GWOS removidas do BIND9."

# Zonas escritas à mão não são nossas para apagar
if [ -f /etc/bind/named.conf.zonas-locais ]; then
    aviso "Mantido: /etc/bind/named.conf.zonas-locais (suas zonas)."
fi

remover_pacotes bind9 bind9-utils

# Devolve o gateway a um resolver externo, senão a máquina fica sem DNS
if grep -q '^nameserver 127.0.0.1' /etc/resolv.conf 2>/dev/null; then
    { echo "# Restaurado ao remover o módulo 20-dns-bind9"
      echo "nameserver 8.8.8.8"
      echo "nameserver 1.1.1.1"
    } > /etc/resolv.conf
    ok "/etc/resolv.conf apontado para resolvers externos."
fi

desregistrar_modulo dns-bind9
integrar --silencioso
ok "Módulo 20-dns-bind9 removido."

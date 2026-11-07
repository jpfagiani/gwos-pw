#!/bin/bash
# GWOS — Módulo 30-hora-chrony: desinstalação

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
titulo "══ Removendo o módulo 30-hora-chrony ══"

aviso "A LAN perde o servidor de hora deste gateway."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

rm -f /etc/chrony/conf.d/gwos.conf
desregistrar_modulo hora-chrony

# O chrony é o relógio da máquina: pacote_em_uso sempre o protege, e a
# remoção fica como decisão explícita fora do desinstalador.
aviso "O pacote chrony NÃO será removido — é o relógio desta máquina."
echo  "      Sem ele o horário deriva e a autenticação de domínio falha."
echo  "      Para remover mesmo assim: apt-get remove --purge chrony"
echo  "      (ative antes o systemd-timesyncd, ou a máquina fica sem hora)"
systemctl restart chrony 2>/dev/null || true
aviso "chrony mantido, sem as configurações do GWOS."

integrar --silencioso
ok "Módulo 30-hora-chrony removido."

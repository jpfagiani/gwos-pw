#!/bin/bash
# ============================================================================
# GWOS — Módulo 30-hora-chrony: servidor de hora (NTP)
# ============================================================================
# Instala o chrony sincronizando com o pool brasileiro (pool.ntp.br) e o
# deixa servindo a hora para as máquinas da LAN.
#
# Sozinho: o gateway mantém o relógio certo e já responde NTP para a rede
#          interna (allow das redes de /etc/gwos/gwos.conf).
# Junto:   'gwos-integrar' reescreve a lista de redes liberadas sempre que a
#          rede muda, e o firewall (módulo 40) já libera UDP/123 vindo da LAN.
#
# A diretiva 'local stratum 10' faz o gateway servir a hora mesmo com a
# internet fora — sem isso, a LAN inteira perde a sincronia quando a WAN cai.
# ============================================================================

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || {
    echo "ERRO: comum/lib.sh não encontrado. Copie a pasta 'comum/' junto com o módulo."
    exit 1; }

iniciar_modulo "30-hora-chrony"

instalar_pacotes chrony

# O systemd-timesyncd briga com o chrony pelo relógio
if systemctl is-enabled systemd-timesyncd &>/dev/null; then
    systemctl disable --now systemd-timesyncd 2>/dev/null || true
    ok "systemd-timesyncd desativado."
fi

# Fuso horário do gateway
timedatectl set-timezone America/Sao_Paulo 2>/dev/null || \
    aviso "Não foi possível definir o fuso horário automaticamente."

# A configuração propriamente dita (pool + allow das redes internas) é gerada
# por gwos-integrar, para acompanhar mudanças de rede sem reinstalar o módulo.
mkdir -p /etc/chrony/conf.d
svc_ativar chrony

registrar_modulo hora-chrony
integrar --silencioso

systemctl restart chrony
ok "chrony ativo e servindo a hora para $(redes_internas)."

echo ""
ok "Módulo 30-hora-chrony instalado."
echo -e "  Ver fontes    : ${BOLD}chronyc sources${NC}"
echo -e "  Ver clientes  : ${BOLD}chronyc clients${NC}"
echo -e "  Nos clientes da LAN, aponte o servidor NTP para ${BOLD}${IP_GATEWAY}${NC}."
echo ""

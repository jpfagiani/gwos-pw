#!/bin/bash
# ============================================================================
# GWOS — Módulo 25-dns-interno-dnsmasq: nomes internos da LAN
# ============================================================================
# Sobe um dnsmasq dedicado em 127.0.0.1:5353 (serviço gwos-dnsmasq) que
# resolve os nomes do domínio interno a partir do /etc/hosts do gateway.
# Não conflita com o dnsmasq padrão do Debian nem com o BIND9 na porta 53.
#
# Sozinho: responde nomes internos em 127.0.0.1:5353
#          (teste: dig @127.0.0.1 -p 5353 samba.cdpni.local)
# Junto:   o BIND9 (módulo 20) encaminha o domínio interno para cá, e a LAN
#          inteira passa a resolver os nomes pelo DNS normal (porta 53).
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

iniciar_modulo "25-dns-interno-dnsmasq"

instalar_pacotes dnsmasq

# O serviço padrão do dnsmasq tentaria a porta 53 e brigaria com o BIND9.
# Usamos uma unit própria, gwos-dnsmasq, com arquivo de configuração próprio.
svc_parar dnsmasq
rm -f /etc/dnsmasq.conf
ok "Serviço dnsmasq padrão desativado (usamos o gwos-dnsmasq)."

# Modelo disponível para o gerador mesmo sem a pasta modulos/
mkdir -p /etc/gwos/modelos
install -m 644 "${MOD_DIR}/config/dnsmasq-gwos.conf.modelo" \
               /etc/gwos/modelos/dnsmasq-gwos.conf.modelo
install -m 755 "${MOD_DIR}/gerar-config.sh" /usr/local/sbin/gwos-gerar-dnsmasq

cat > /etc/systemd/system/gwos-dnsmasq.service <<UNIT
[Unit]
Description=GWOS dnsmasq — resolucao de nomes internos da LAN
Documentation=file:///etc/dnsmasq.d/gwos.conf
After=network.target
Wants=network-online.target

[Service]
Type=forking
ExecStartPre=/usr/sbin/dnsmasq --test --conf-file=/etc/dnsmasq.d/gwos.conf
ExecStart=/usr/sbin/dnsmasq --conf-file=/etc/dnsmasq.d/gwos.conf --pid-file=/run/gwos-dnsmasq.pid
PIDFile=/run/gwos-dnsmasq.pid
ExecReload=/bin/kill -HUP \$MAINPID
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
ok "Unit gwos-dnsmasq criada."

/usr/local/sbin/gwos-gerar-dnsmasq
svc_ativar gwos-dnsmasq
ok "gwos-dnsmasq ativo em 127.0.0.1:${DNSMASQ_PORTA}."

registrar_modulo dns-interno
integrar --silencioso

echo ""
ok "Módulo 25-dns-interno-dnsmasq instalado."
echo -e "  Cadastrar nomes : ${BOLD}gwos dns add <nome> <ip>${NC}   (requer o módulo 60-painel-web)"
echo -e "  Ou direto       : edite /etc/hosts e rode ${BOLD}systemctl reload gwos-dnsmasq${NC}"
echo -e "  Teste local     : ${BOLD}dig @127.0.0.1 -p ${DNSMASQ_PORTA} <nome>.${DOMINIO_LOCAL}${NC}"
tem_bind9 || echo -e "  ${YELLOW}Sem o módulo 20-dns-bind9 os clientes da LAN não alcançam estes nomes.${NC}"
echo ""

#!/bin/bash
# ============================================================================
# GWOS — Módulo 20-dns-bind9: servidor DNS da rede
# ============================================================================
# Instala o BIND9 como resolver recursivo da LAN, com:
#   - encaminhamento para resolvers públicos (8.8.8.8 / 1.1.1.1)
#   - RPZ ativa (bloqueio de domínios por DNS)
#   - zonas dos domínios com DNS próprio, vindas do perfil da unidade
#
# Sozinho: DNS funcional para a LAN, com bloqueio por RPZ editável à mão em
#          /etc/bind/db.rpz.gwos.
# Junto:   a RPZ passa a ser gerada do banco (módulo 10) pelo painel (módulo
#          60); o domínio interno é encaminhado ao dnsmasq (módulo 25); o
#          firewall (módulo 40) força todo DNS da LAN a passar por aqui.
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

iniciar_modulo "20-dns-bind9"

instalar_pacotes bind9 bind9-utils dnsutils

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------
for arq in named.conf.options named.conf.local; do
    backup_arquivo "/etc/bind/${arq}"
    install -m 644 -o root -g bind "${MOD_DIR}/config/${arq}" "/etc/bind/${arq}"
done

# A zona RPZ só é criada se ainda não existir — nunca sobrescreve os bloqueios
# já aplicados pelo painel.
if [ ! -f /etc/bind/db.rpz.gwos ]; then
    install -m 644 -o bind -g bind "${MOD_DIR}/config/db.rpz.gwos" /etc/bind/db.rpz.gwos
    ok "Zona RPZ inicial criada."
else
    chown bind:bind /etc/bind/db.rpz.gwos
    aviso "Zona RPZ existente preservada (/etc/bind/db.rpz.gwos)."
fi

# Arquivos de integração — 'gwos-integrar' os regrava logo abaixo, mas
# precisam existir e ser válidos antes do primeiro named-checkconf.
[ -f /etc/bind/named.conf.gwos-integracao ] || \
    echo "// Gerado por gwos-integrar." > /etc/bind/named.conf.gwos-integracao

{
    echo "// Gerado por gwos-integrar a partir de DNS_FORWARDERS em ${GWOS_CONF}."
    echo "forwarders {"
    for _dns in $DNS_FORWARDERS; do
        echo "    ${_dns};"
    done
    echo "};"
    echo "forward only;"
} > /etc/bind/named.conf.gwos-forwarders

chown bind:bind /etc/bind/named.conf.gwos-integracao /etc/bind/named.conf.gwos-forwarders
ok "Resolvers upstream: ${DNS_FORWARDERS}"

# Zonas escritas à mão pelo administrador. Criado uma vez e nunca mais tocado
# — o named.conf.local É sobrescrito a cada reinstalação, este não.
if [ ! -f /etc/bind/named.conf.zonas-locais ]; then
    cat > /etc/bind/named.conf.zonas-locais <<'ZONAS'
// ============================================================
// GWOS — zonas do administrador
// ============================================================
// Arquivo criado uma vez pelo módulo 20-dns-bind9 e NUNCA
// sobrescrito. É aqui que vão as zonas escritas à mão.
//
// Depois de editar:  named-checkconf && rndc reload
//
// Encaminhar um domínio para outro servidor DNS:
//
//   zone "exemplo.sp.gov.br" {
//       type forward;
//       forward only;
//       forwarders { 10.1.6.222; };
//   };
//
// Zona própria, com arquivo de registros:
//
//   zone "minhazona.local" {
//       type master;
//       file "/etc/bind/db.minhazona.local";
//       allow-update { none; };
//   };
//
// Para nomes soltos da LAN (portal, samba, impressora) NÃO crie
// zona: use 'gwos dns add <nome> <ip>', que é servido pelo
// dnsmasq no domínio interno.
// ============================================================
ZONAS
    chown bind:bind /etc/bind/named.conf.zonas-locais
    ok "Ponto de extensão criado: /etc/bind/named.conf.zonas-locais"
else
    chown bind:bind /etc/bind/named.conf.zonas-locais
    aviso "Zonas do administrador preservadas (/etc/bind/named.conf.zonas-locais)."
fi

mkdir -p /var/log/named
chown bind:bind /var/log/named
chmod 755 /var/log/named
ok "Diretório de log criado."

# AppArmor do Debian já libera /var/log/named para o named; nada a fazer.

named-checkconf || erro "named-checkconf reprovou a configuração — nada foi iniciado."
ok "Configuração validada."

# ---------------------------------------------------------------------------
# Porta 53 já ocupada? (Samba AD DC, systemd-resolved, dnsmasq padrão, Pi-hole)
# ---------------------------------------------------------------------------
DONO_53=$(ss -lnpu 2>/dev/null | awk '$5 ~ /:53$/ {print $NF}' | grep -o 'users:(("[^"]*' \
          | sed 's/.*(("//' | sort -u | grep -v '^named$' | paste -sd ', ' - || true)
if [ -n "${DONO_53:-}" ]; then
    aviso "A porta 53 já está em uso por: ${DONO_53}"
    if systemctl is-active --quiet systemd-resolved; then
        aviso "systemd-resolved detectado — desative-o antes:"
        echo  "      systemctl disable --now systemd-resolved"
    fi
    case "$DONO_53" in
        *samba*|*smbd*)
            aviso "Servidor Samba como controlador de domínio (AD DC) tem DNS próprio."
            aviso "Nesse caso NÃO instale o BIND9 aqui — use outra máquina para o DNS." ;;
    esac
    erro "Libere a porta 53 e reexecute este módulo."
fi

svc_ativar named
ok "BIND9 ativo na porta 53."

# ---------------------------------------------------------------------------
# Esta máquina passa a resolver por si mesma
# ---------------------------------------------------------------------------
# Um único nameserver, de propósito. Acrescentar um resolver público aqui não
# é fallback, é BYPASS: o glibc só passa para o próximo em timeout, e o BIND
# responde — inclusive NXDOMAIN e bloqueios da RPZ. Na prática só serviria
# para, quando o BIND cair, a máquina resolver por fora sem os domínios
# internos e sem a RPZ, mascarando a falha.
#
# O BIND já tem os resolvers de DNS_FORWARDERS como saída; o fallback existe
# uma camada abaixo.

gerar_resolv_conf() {
    {
        echo "# Gerado pelo módulo 20-dns-bind9 do GWOS"
        echo "# Esta máquina resolve pelo BIND9 local, que já encaminha para"
        echo "# os resolvers de DNS_FORWARDERS (/etc/gwos/gwos.conf)."
        echo "#"
        echo "# NÃO acrescente um resolver público aqui: seria um desvio do"
        echo "# seu próprio DNS, sem os domínios internos e sem a RPZ."
        echo "#"
        echo "# Se o BIND9 não subir e a máquina precisar da internet para o"
        echo "# conserto (apt, git), use TEMPORARIAMENTE:"
        echo "#   echo 'nameserver 8.8.8.8' >> /etc/resolv.conf"
        echo "# e desfaça assim que o named voltar."
        echo "nameserver 127.0.0.1"
        echo "search ${DOMINIO_LOCAL}"
        echo "# Falha rápido em vez de pendurar 10 s quando o named está fora"
        echo "options timeout:2 attempts:2"
    } > "$1"
}

# Quem manda no resolv.conf? Escrever por cima de um arquivo gerenciado é
# inútil: o gerenciador reescreve no próximo lease ou reboot, a máquina volta
# a resolver pelo DNS do DHCP e o BIND local fica ignorado — sem nenhum erro.
GERENCIADOR=""
if [ -L /etc/resolv.conf ]; then
    GERENCIADOR="link simbólico (systemd-resolved?)"
elif grep -qi 'generated by dhcpcd' /etc/resolv.conf 2>/dev/null; then
    GERENCIADOR="dhcpcd"
elif grep -qi 'generated by NetworkManager' /etc/resolv.conf 2>/dev/null; then
    GERENCIADOR="NetworkManager"
elif grep -qi 'resolvconf' /etc/resolv.conf 2>/dev/null; then
    GERENCIADOR="resolvconf"
fi

if lsattr /etc/resolv.conf 2>/dev/null | awk '{print $1}' | grep -q 'i'; then
    aviso "/etc/resolv.conf está imutável (chattr +i) — não alterado."
    echo  "      Para apontar esta máquina ao BIND9 local:"
    echo  "        chattr -i /etc/resolv.conf"
    echo  "        printf 'nameserver 127.0.0.1\nsearch ${DOMINIO_LOCAL}\n' > /etc/resolv.conf"
    echo  "        chattr +i /etc/resolv.conf"

elif [ "$GERENCIADOR" = "dhcpcd" ]; then
    # O dhcpcd tem gancho próprio: 'nohook resolv.conf' o faz parar de mexer
    # no arquivo, e aí o nosso vale de verdade. Sem isto, o módulo escrevia
    # e o dhcpcd desfazia no próximo lease.
    aviso "O dhcpcd está gerenciando o /etc/resolv.conf."
    if [ -f /etc/dhcpcd.conf ]; then
        backup_arquivo /etc/dhcpcd.conf
        grep -q '^nohook resolv.conf' /etc/dhcpcd.conf || {
            printf '
# GWOS: este servidor roda o proprio BIND9 e gerencia o resolv.conf.
' >> /etc/dhcpcd.conf
            printf 'nohook resolv.conf
' >> /etc/dhcpcd.conf
        }
        ok "dhcpcd instruído a não tocar mais no resolv.conf (nohook)."
    else
        aviso "/etc/dhcpcd.conf não encontrado — o dhcpcd pode sobrescrever de novo."
    fi
    backup_arquivo /etc/resolv.conf
    gerar_resolv_conf /etc/resolv.conf
    ok "/etc/resolv.conf aponta para o BIND9 local."

elif [ -n "$GERENCIADOR" ]; then
    aviso "/etc/resolv.conf é gerenciado por: ${GERENCIADOR} — NÃO alterado."
    echo  "      Escrever por cima seria desfeito no próximo lease ou reboot, e"
    echo  "      esta máquina continuaria resolvendo por fora do próprio BIND9."
    echo  "      Ajuste no gerenciador para que ele entregue 127.0.0.1."

elif grep -q '^nameserver 127.0.0.1' /etc/resolv.conf 2>/dev/null; then
    ok "/etc/resolv.conf já aponta para o BIND9 local."

else
    backup_arquivo /etc/resolv.conf
    if gerar_resolv_conf /etc/resolv.conf 2>/dev/null; then
        ok "/etc/resolv.conf aponta para o BIND9 local."
    else
        aviso "Não foi possível escrever /etc/resolv.conf — deixado como estava."
    fi
fi

# ---------------------------------------------------------------------------
# Domínios com DNS próprio, vindos do perfil da unidade
# ---------------------------------------------------------------------------
# Ficam em named.conf.zonas-locais (preservado entre reinstalações), não no
# named.conf.local (regravado). Zona que já existe é mantida como está.
if [ -n "${ZONAS_INTERNAS:-}" ] && [ -x /usr/local/sbin/gwos-zona ]; then
    titulo "── Domínios internos ──"
    for _par in $ZONAS_INTERNAS; do
        _dom="${_par%%:*}"
        _ips="${_par#*:}"
        _ips="${_ips//,/ }"
        if [ -z "$_dom" ] || [ "$_dom" = "$_par" ]; then
            aviso "Entrada ignorada (formato dominio:ip): '${_par}'"
            continue
        fi
        if /usr/local/sbin/gwos-zona listar 2>/dev/null | cut -f1 | grep -qx "$_dom"; then
            info "Zona ${_dom} já existe — mantida."
        elif /usr/local/sbin/gwos-zona adicionar "$_dom" "$_ips" >/dev/null 2>&1; then
            ok "${_dom} → ${_ips}"
        else
            aviso "Não foi possível criar a zona ${_dom} (verifique os IPs)."
        fi
    done
fi

registrar_modulo dns-bind9
integrar --silencioso

echo ""
ok "Módulo 20-dns-bind9 instalado."
echo -e "  Teste: ${BOLD}dig google.com @${IP_GATEWAY}${NC}"
echo -e "  Nos clientes da LAN, aponte o DNS para ${BOLD}${IP_GATEWAY}${NC}."
tem_dnsmasq || echo -e "  ${YELLOW}Nomes internos (${DOMINIO_LOCAL}) exigem o módulo 25-dns-interno-dnsmasq.${NC}"
echo ""

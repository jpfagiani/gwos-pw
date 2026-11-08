# GWOS — Gateway Web OS

Gateway para rede local em Debian 12/13: DNS com bloqueio por domínio, proxy
HTTP/HTTPS com inspeção de TLS, firewall com NAT, servidor de hora e um painel
web para administrar tudo.

Cada servidor é um **módulo independente**. Dá para instalar só o DNS numa
máquina, só o proxy em outra, ou todos no mesmo gateway — quando convivem, se
integram sozinhos.

---

## Instalação

```bash
apt install -y git
git clone https://github.com/jpfagiani/gwos.git /opt/gwos
cd /opt/gwos

# Tudo, na ordem
bash install/install.sh

# Ou um servidor isolado
bash modulos/20-dns-bind9/instalar.sh
```

O painel inclui visões de monitoramento, equipamentos observados, ranking de
consumo, alertas, agenda de políticas e simulação. A telemetria depende dos
módulos correspondentes instalados e de haver registros coletados; valores sem
fonte aparecem vazios. Para VMs já instaladas, use `INSTRUCOES_ATUALIZACAO.md`
para atualizar o pacote e reaplicar o módulo do banco, que executa as migrations.

Ao final: `http://IP_DO_GATEWAY:8080`. Durante a instalação do módulo do
painel o instalador **pede a senha do administrador** (digitação oculta, com
confirmação, mínimo 8 caracteres); nada é gerado nem exibido. Para instalação
sem terminal, exporte `GWOS_ADMIN_SENHA` antes de rodar.

## Instalar usando o ZIP

Se recebeu `GWOS-v0.2.0-dev.zip`, extraia o arquivo diretamente em `/opt/gwos`
numa VM Debian limpa e rode `bash /opt/gwos/install/install.sh` como `root`.
O roteiro completo, incluindo WAN/LAN isoladas e verificações, está em
[`INSTALACAO_VM.md`](INSTALACAO_VM.md). Faça primeiro uma instalação de
laboratório: o pacote ainda requer homologação integrada.

---

## Os módulos

| Pasta | Serviço | Função |
|-------|---------|--------|
| `modulos/00-base` | — | Rede, firmware, encaminhamento de pacotes, estado compartilhado |
| `modulos/10-banco-mariadb` | `mariadb` | Grupos, IPs, domínios, horários, relatórios |
| `modulos/20-dns-bind9` | `named` | DNS da rede, RPZ, zonas dos domínios internos |
| `modulos/25-dns-interno-dnsmasq` | `gwos-dnsmasq` | Nomes internos da LAN |
| `modulos/30-hora-chrony` | `chrony` | Servidor de hora (NTP) |
| `modulos/40-firewall-nftables` | `nftables` | Firewall, NAT e redirecionamentos |
| `modulos/50-proxy-squid` | `squid` | Proxy 3127/3128/3129 com SSL Bump |
| `modulos/60-painel-web` | `nginx`, PHP-FPM da distribuição | Painel de administração |

Nenhuma dependência é obrigatória: sem o banco, o painel sobe em modo leve com
as telas cujos dados vivem em arquivo.

Detalhes em [`modulos/README.md`](modulos/README.md); cada módulo tem o seu.

---

## Como funcionam isolados

Nenhum módulo depende do instalador para descobrir a rede. Quem precisa saber
a LAN, a rede ou o domínio interno lê `/etc/gwos/gwos.conf`; se o arquivo não
existir, é criado a partir do que o sistema já tem — **sem tocar em
`/etc/network/interfaces`**. Só o `00-base` reconfigura a rede de verdade.

## Como se integram

Ao terminar, todo módulo roda `gwos-integrar`, que olha o que existe na máquina
e reescreve só os pontos de contato:

- o firewall só redireciona 80/443 se houver Squid;
- o Squid só resolve por `127.0.0.1` se houver BIND9;
- o BIND9 só encaminha o domínio interno se houver dnsmasq;
- o chrony libera as redes internas atuais.

Por isso a ordem não importa: instale o Squid depois do firewall e o firewall
se reajusta; remova o Squid e os redirecionamentos somem antes de a rede ficar
sem internet.

---

## Painel

O menu é montado a partir de `/etc/gwos/modulos.d`: instalou um módulo pelo
terminal, a seção aparece na próxima página.

| Tela | O que dá para fazer |
|------|---------------------|
| Módulos | O que está instalado, estado dos serviços, o que falta |
| DNS | Resolvers, teste de cada um, zonas, resolução ao vivo |
| Nomes internos | Vincular nome a IP |
| Hora | Servidores NTP, sincronização, quem consulta |
| Firewall | Regras em vigor e a origem de cada uma; regerar |
| Proxy | Portas, SSL Bump, listas, últimos acessos |
| Grupos, Domínios, Horários, NAT, Relatórios | Exigem o módulo de banco |

O painel nunca escreve em `/etc` nem recarrega serviço por conta própria: tudo
passa por `gwos-definir`, `gwos-zona` ou `gwos-servico`, que validam com a
ferramenta do próprio serviço (`named-checkconf`, `nft -c`, `squid -k parse`)
antes de aplicar e desfazem sozinhos se a configuração não passar.

---

## Linha de comando

```bash
gwos status                  # estado dos serviços
gwos diag                    # diagnóstico completo
gwos ip 10.14.29.20          # trocar o IP do gateway com validação e backup
gwos dns add portal 10.14.29.8
gwos log live                # acessos em tempo real
gwos backup criar

bash modulos/verificar-todos.sh   # diagnóstico módulo a módulo
```

Referência completa no [`MANUAL.md`](MANUAL.md).

---

## Requisitos

- Debian 12 ou 13, instalação mínima
- Duas interfaces de rede para uso como gateway (uma só basta para DNS ou hora)
- Acesso root

> **Trocar o IP do gateway é sempre `gwos ip`**, nunca edição manual seguida de
> `systemctl restart networking`. O comando valida com `ifquery`, faz backup e
> adiciona o IP novo antes de remover o antigo — a interface nunca fica sem
> endereço e a sessão SSH não cai.

## Evolução de segurança e dashboard

A cópia de entrega inclui endurecimento de sessão, autenticação por padrão no roteador, CSRF centralizado para alterações, logout via POST e monitoramento com estados de coleta. Consulte `DIAGNOSTICO_ATUAL.md`, `MATRIZ_FUNCIONALIDADES.md`, `CHANGELOG_IMPLEMENTACAO.md`, `TESTES_E_RESULTADOS.md` e `INSTRUCOES_ATUALIZACAO.md`.

A entrega ainda requer validação com PHP CLI e homologação em Debian de laboratório. Para uma VM, siga [`INSTALACAO_VM.md`](INSTALACAO_VM.md); não use em produção antes de concluir esses testes.

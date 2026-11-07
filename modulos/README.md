# GWOS — Instalação modular

Cada servidor do GWOS virou um módulo independente. Você pode instalar só o
DNS numa máquina, só o proxy em outra, ou todos no mesmo gateway — o resultado
é o mesmo de antes, mas dá para montar por partes.

```bash
# Escolher módulo a módulo (recomendado na primeira vez)
bash modulos/instalar-todos.sh --escolher

# Tudo, na ordem — gateway completo
bash modulos/instalar-todos.sh

# Só os que você quer
bash modulos/instalar-todos.sh 20-dns-bind9 30-hora-chrony

# Um servidor isolado
bash modulos/20-dns-bind9/instalar.sh

# Conferir o que está instalado
bash modulos/verificar-todos.sh
```

## Convenção de nomes dos portais

Uma unidade costuma ter mais de um portal web. Todos seguem
`portal-<o que administra>`, sem citar a unidade — o mesmo código serve a
qualquer presídio, e o prefixo comum agrupa os três:

| Portal | Nome de sistema | Porta | Repositório |
|--------|-----------------|-------|-------------|
| Sistemas externos | `portal-sistemas` | **80** | `jpfagiani/portal` |
| Gateway | `portal-gateway` | **8080** | este (`modulos/60-painel-web`) |
| Servidor de arquivos | `portal-samba` | **8443** | `jpfagiani/smb` |

As portas são **fixas em todas as unidades**. Padronizar vale mais que ganhar
a porta 80: quem dá suporte a vários presídios encontra cada portal sempre no
mesmo lugar, e a 80 fica para o portal de sistemas — o único que todos os
usuários acessam, e o único que se digita sem porta na barra de endereços.

O instalador só propõe outra porta se a 8080 estiver ocupada por algo que não
dá para mover.

```bash
systemctl status 'portal-*'      # os três de uma vez
```

O nome de **acesso** é outra coisa, e deve ser curto — é o que as pessoas
digitam:

```bash
gwos dns add gateway  10.14.29.15
gwos dns add samba    10.14.29.11
gwos dns add sistemas 10.14.29.8
```

Para `http://gateway` funcionar sem o domínio completo, entregue o sufixo de
busca aos clientes pela opção 15 do DHCP (`domain-name = cdpni.local`).

## Perfis de unidade

O que muda de uma unidade prisional para outra — DNS interno, servidor de hora,
domínios da intranet — não fica no código: fica em `modulos/perfis/`.

```bash
ls modulos/perfis/
# cdpni.conf  exemplo.conf.modelo
```

O módulo `00-base` lista os perfis e pergunta qual usar; escolhido um, ele
preenche os padrões das perguntas seguintes. Para uma unidade nova:

```bash
cp modulos/perfis/exemplo.conf.modelo modulos/perfis/minha-unidade.conf
# edite os valores e rode a instalação — o perfil aparece na lista
```

Um perfil define:

| Chave | O que é |
|-------|---------|
| `DOMINIO_LOCAL` | Domínio dos nomes da LAN |
| `DNS_FORWARDERS` | Resolvers para tudo que não é domínio interno |
| `ZONAS_INTERNAS` | `dominio:ip` dos domínios com DNS próprio |
| `NTP_SERVIDORES` | Servidor de hora da rede |
| `NTP_POOL` | Pool público de reserva |

Sem perfil, o instalador pergunta cada um — com padrões neutros (resolvers
públicos, sem zonas internas, só o pool de hora). Nada é específico de
unidade nenhuma no código.

## Quantas placas de rede você precisa

| Módulos | Placas |
|---------|--------|
| `00-base` e `40-firewall-nftables` | **duas** — são os módulos de gateway, que roteiam de uma para a outra |
| DNS, nomes internos, hora, proxy, banco, painel | **uma** basta |

Numa máquina de uma placa só, o `instalar-todos.sh` avisa antes de começar e
pula os dois módulos de gateway. O `00-base` executado direto também detecta e
sai sem alterar nada, dizendo quais módulos usar no lugar.

---

## Os módulos

| Pasta | Serviço | O que instala |
|-------|---------|---------------|
| `00-base` | — | Firmware, microcode, `/etc/network/interfaces`, `ip_forward`, estado compartilhado |
| `10-banco-mariadb` | `mariadb` | Banco `gwos`, usuário, schema, credenciais em `/etc/gwos/db.conf` |
| `20-dns-bind9` | `named` | DNS recursivo da LAN, RPZ (bloqueio por domínio), forward zones do governo |
| `25-dns-interno-dnsmasq` | `gwos-dnsmasq` | Nomes internos da LAN em `127.0.0.1:5353` |
| `30-hora-chrony` | `chrony` | Servidor de hora (NTP) para o gateway e para a LAN |
| `40-firewall-nftables` | `nftables` | Firewall, NAT/masquerade e os redirecionamentos de proxy e DNS |
| `50-proxy-squid` | `squid` | Proxy 3127/3128/3129, SSL Bump e a CA |
| `60-painel-web` | `nginx`, `php8.4-fpm` | Painel, comando `gwos`, sudoers e cron |

Ordem recomendada: a numérica. Nenhuma dependência é obrigatória: sem o
`10-banco-mariadb` o painel sobe em modo leve, com as telas cujos dados vivem
em arquivo.

## Telas no painel

Com o `60-painel-web` instalado, cada módulo abaixo ganha sua própria tela. A
lista de módulos é lida de `/etc/gwos/modulos.d` a cada carregamento —
instalou pelo terminal, a seção aparece na próxima página.

| Módulo | Tela | O que dá para fazer |
|--------|------|---------------------|
| `20-dns-bind9` | DNS | Editar resolvers, testar cada um, criar e remover zonas, resolver nome ao vivo |
| `25-dns-interno` | Nomes internos | Vincular nome a IP, alterar, remover |
| `30-hora-chrony` | Hora | Trocar servidores NTP, ver sincronização e quem consulta |
| `40-firewall-nftables` | Firewall | Ver regras em vigor e por que cada uma está lá; regerar |
| `50-proxy-squid` | Proxy | Portas, estado do SSL Bump, listas incluídas, últimos acessos |

Nenhuma tela escreve em `/etc` direto. Tudo passa por `gwos-definir`,
`gwos-zona` ou `gwos-servico`, que validam antes de aplicar — e o firewall não
tem edição de regra crua de propósito: as regras são derivadas, e um formulário
criaria uma segunda fonte de verdade que a primeira regeração apagaria.

---

## Como cada módulo funciona sozinho

Nenhum módulo depende do instalador para descobrir a rede. Quem precisa saber
qual é a LAN, a rede ou o domínio interno lê `/etc/gwos/gwos.conf`; se o
arquivo não existir, `modulos/comum/lib.sh` detecta tudo a partir do sistema
(rota padrão, IPs das interfaces) e o cria — **sem tocar em
`/etc/network/interfaces`**. Só o `00-base` reconfigura a rede de verdade.

O que cada um faz quando é o único instalado:

- **20-dns-bind9** — resolver completo para a LAN. Bloqueio por domínio
  editando `/etc/bind/db.rpz.gwos` à mão.
- **25-dns-interno** — responde nomes internos em `127.0.0.1:5353`
  (`dig @127.0.0.1 -p 5353 samba.cdpni.local`). A LAN só alcança esses nomes
  com o BIND9 junto.
- **30-hora-chrony** — servidor NTP funcional para a LAN, inclusive com a
  internet fora (`local stratum 10`).
- **40-firewall-nftables** — gateway com NAT e firewall; a LAN navega direto,
  sem proxy.
- **50-proxy-squid** — proxy explícito na porta 3127. Sem o painel, as listas
  de grupos ficam vazias e a regra `http_access deny all` bloqueia todo mundo;
  o instalador mostra a linha a trocar para liberar a LAN.
- **10-banco** e **60-painel** — precisam um do outro; os demais são opcionais.

---

## Como eles se encontram

Cada módulo, ao terminar, roda `gwos-integrar` (instalado em
`/usr/local/sbin/`). Esse script olha o que existe na máquina naquele momento
e reescreve **só os pontos de contato**:

| Ponto de contato | Arquivo gerado | Condição |
|------------------|----------------|----------|
| BIND9 → upstream | `/etc/bind/named.conf.gwos-forwarders` | lista de `DNS_FORWARDERS` do `gwos.conf` |
| BIND9 → dnsmasq | `/etc/bind/named.conf.gwos-integracao` | domínio interno encaminhado só se o módulo 25 existir |
| Squid → resolver | `/etc/squid/conf.d/gwos_integracao.conf` | `127.0.0.1` com BIND9; resolvers externos sem ele |
| Squid → redes | `/etc/squid/conf.d/gwos_redes.conf` | ACLs vindas de `gwos.conf` (cobre faixas fora do RFC 1918) |
| nftables | `/etc/nftables.conf` | redireciona 80/443 só se houver Squid; DNS só se houver BIND9 |
| chrony | `/etc/chrony/conf.d/gwos.conf` | fontes de `NTP_SERVIDORES`/`NTP_POOL` + libera as redes internas atuais |

Por isso a ordem não trava nada: instale o Squid depois do firewall e o
firewall se reajusta sozinho; remova o Squid e os redirecionamentos somem
antes que a LAN fique sem internet.

Estado e registro:

```
/etc/gwos/gwos.conf        parâmetros de rede compartilhados
/etc/gwos/db.conf          credenciais do banco (0600)
/etc/gwos/modulos.d/<nome> um arquivo por módulo instalado
/etc/gwos/lib.sh           cópia da biblioteca comum
/usr/local/sbin/gwos-integrar        re-costura tudo
/usr/local/sbin/gwos-definir         altera uma chave do gwos.conf, com validação
/usr/local/sbin/gwos-zona            zonas de encaminhamento do BIND9
/usr/local/sbin/gwos-servico         lê e recarrega serviços (par ação/módulo fixo)
/usr/local/sbin/gwos-gerar-nftables  regera o firewall
/usr/local/sbin/gwos-gerar-dnsmasq   regera o dnsmasq
```

O `gwos-definir` é o único caminho de escrita que o painel web tem no
`gwos.conf`: lista fechada de chaves, validador por chave, e `gwos-integrar` no
final. Interfaces, IP do gateway e rede ficam de fora de propósito — trocar IP
é `gwos ip`, que valida com `ifquery` e põe o IP novo antes de tirar o antigo.

```bash
gwos-definir --listar
gwos-definir DNS_FORWARDERS "8.8.8.8 1.1.1.1"
```

---

## Comandos por módulo

Todo módulo tem os mesmos três scripts:

```bash
bash modulos/<modulo>/instalar.sh      # instala e integra
bash modulos/<modulo>/verificar.sh     # diagnostica (sai 1 se houver problema)
bash modulos/<modulo>/desinstalar.sh   # remove e reintegra o que sobrou
```

Alguns aceitam opções:

```bash
bash modulos/10-banco-mariadb/instalar.sh --recriar   # zera o banco
bash modulos/instalar-todos.sh --pular 00-base        # tudo menos a rede
bash modulos/instalar-todos.sh 20-dns-bind9 50-proxy-squid
bash modulos/verificar-todos.sh --todos
```

---

## Copiar um módulo para outra máquina

Leve a pasta do módulo **e a pasta `comum/`** — é lá que está a biblioteca:

```bash
scp -r modulos/comum modulos/20-dns-bind9 root@outra-maquina:/tmp/gwos-dns/
ssh root@outra-maquina 'bash /tmp/gwos-dns/20-dns-bind9/instalar.sh'
```

Dois módulos precisam do repositório completo: o `10-banco-mariadb`
(`database/schema.sql`) e o `60-painel-web` (`app/`, `public/`, `scripts/`).

---

## Desinstalar

```bash
bash modulos/50-proxy-squid/desinstalar.sh   # um servidor
bash modulos/desinstalar-todos.sh            # tudo
```

Pergunta **uma vez** e depois vai até o fim, sem confirmação a cada passo.

**Pacotes compartilhados não são removidos.** Antes de desinstalar qualquer
pacote, o script verifica se outra coisa na máquina depende dele — e, se
depender, nem oferece: mantém e explica na tela.

| Pacote | Mantido quando |
|--------|----------------|
| `nginx` | há outros sites em `/etc/nginx/sites-enabled` |
| `php*` | há outros sites do nginx ou do Apache |
| `mariadb-server` | existe outro banco além do `gwos` |
| `dnsmasq` | o libvirt usa dnsmasq nas redes virtuais |
| `nftables` | Docker ou libvirt estão instalados |
| `chrony` | sempre — é o relógio da máquina |
| qualquer um | a remoção arrastaria outros pacotes junto |

O serviço também é poupado: se o nginx ainda serve outro site, o desinstalador
remove só o vhost do GWOS e **recarrega** em vez de parar.

O que nunca é apagado: os backups em `/var/lib/gwos/backups` e as zonas em
`/etc/bind/named.conf.zonas-locais`. São seus dados, não resíduo — o script
mostra o caminho e o comando para apagar, se você quiser.

## Reinstalar / reaplicar

Os instaladores são reexecutáveis. O que é preservado numa reinstalação:

- banco de dados (a menos de `--recriar`)
- CA do SSL Bump (os clientes não precisam reinstalar o certificado)
- zona RPZ com os bloqueios já aplicados
- senha do painel, se já tiver sido trocada

O que é sempre regravado: `squid.conf`, `named.conf.*`, `/etc/nftables.conf`,
`/etc/dnsmasq.d/gwos.conf` e a configuração do Nginx — com backup `.bak.<data>`
quando o arquivo já existia.

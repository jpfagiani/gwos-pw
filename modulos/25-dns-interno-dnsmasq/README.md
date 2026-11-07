# 25-dns-interno-dnsmasq — nomes internos da LAN

Resolve nomes como `samba.cdpni.local` a partir do `/etc/hosts` do gateway.

## O que faz

- Instala o `dnsmasq` e **desativa o serviço padrão** (ele brigaria pela
  porta 53 com o BIND9)
- Cria a unit própria `gwos-dnsmasq`, escutando em `127.0.0.1:5353`
- Gera `/etc/dnsmasq.d/gwos.conf` a partir de
  `config/dnsmasq-gwos.conf.modelo`, substituindo domínio e porta pelos
  valores de `/etc/gwos/gwos.conf`
- Instala `/usr/local/sbin/gwos-gerar-dnsmasq`, que refaz essa geração sempre
  que o domínio ou a porta mudarem

## Instalação

```bash
bash instalar.sh
```

## Cadastrar nomes

```bash
gwos dns add samba 10.14.29.11      # requer o módulo 60-painel-web
gwos dns list
gwos dns update samba 10.14.29.12
gwos dns del samba
```

Sem o painel, edite `/etc/hosts` e rode `systemctl reload gwos-dnsmasq`.
Propagação em até 60 s (TTL baixo).

## Sozinho

Responde apenas no loopback:

```bash
dig @127.0.0.1 -p 5353 samba.cdpni.local
```

Para a LAN inteira alcançar esses nomes é preciso o módulo **20-dns-bind9** —
é ele que escuta na porta 53 e encaminha o domínio interno para cá.

## Verificação

```bash
bash verificar.sh
```

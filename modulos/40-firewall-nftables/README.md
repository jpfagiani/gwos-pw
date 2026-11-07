# 40-firewall-nftables — firewall e NAT

Filtro de INPUT/FORWARD, masquerade para a WAN e os redirecionamentos que
levam o tráfego da LAN ao proxy e ao DNS local.

## O que faz

- Instala o `nftables` e desativa o `ufw`, se existir
- Instala `/usr/local/sbin/gwos-gerar-nftables`
- Gera e aplica `/etc/nftables.conf`, sempre validando com `nft -c` antes de
  substituir o arquivo em vigor
- Habilita o serviço no boot

## Regras condicionais

O gerador monta as regras de acordo com o que existe na máquina:

| Situação | Efeito |
|----------|--------|
| Squid instalado | `tcp dport 80 → :3128` e, com SSL Bump, `443 → :3129` |
| Squid ausente | HTTP/HTTPS saem direto, sem redirecionamento |
| BIND9 instalado | todo DNS da LAN é redirecionado para o resolver local |
| BIND9 ausente | DNS da LAN sai direto |
| Banco + painel | delega para `scripts/aplicar_nftables.sh`, que acrescenta os grupos de IPs e o NAT 1:1 do banco |

É isso que evita o pior caso da instalação por partes: redirecionar a LAN
inteira para uma porta onde não há serviço nenhum.

## Redes locais

Todas as sub-redes conectadas à interface LAN (IP principal **e** aliases) são
roteadas, nunca interceptadas — inclusive faixas fora do RFC 1918, como
`172.14.29.0/24`. A lista vem de `ip route show dev <LAN> scope link`, então
sobrevive à regeneração das regras sem depender do banco.

## Instalação

```bash
bash instalar.sh
```

## Operação

```bash
nft list ruleset          # regras em vigor
gwos-gerar-nftables       # regerar (usa o banco, se houver)
GWOS_NFT_BASE=1 gwos-gerar-nftables   # regerar ignorando o banco
```

## Verificação

```bash
bash verificar.sh
```

Além das tabelas e do masquerade, checa a coerência com os outros módulos:
acusa redirecionamento para um Squid que não existe, ou Squid instalado sem
redirecionamento.

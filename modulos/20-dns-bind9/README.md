# 20-dns-bind9 — servidor DNS da rede

BIND9 como resolver recursivo da LAN, com bloqueio de domínios por RPZ.

## O que faz

- Instala `bind9`, `bind9-utils` e `dnsutils`
- `named.conf.options`: `allow-query { localhost; localnets; }` (sobrevive a
  trocas de IP) e a diretiva **`response-policy`** que ativa a RPZ
- `named.conf.local`: zona RPZ, forward zones dos DNS internos do governo e o
  `include` do arquivo de integração
- Cria `/var/log/named` e aponta `/etc/resolv.conf` do gateway para `127.0.0.1`

> **Correção em relação ao instalador antigo:** a zona `rpz.gwos` era
> declarada mas nunca ativada — faltava `response-policy` em
> `named.conf.options`, então o bloqueio de domínios por DNS não surtia
> efeito. Agora está ativo.

## Resolvers upstream

Vêm de `DNS_FORWARDERS` em `/etc/gwos/gwos.conf`; o `gwos-integrar` transforma
a lista em `/etc/bind/named.conf.gwos-forwarders`, incluído pelo
`named.conf.options`. Padrão:

```
DNS_FORWARDERS="10.14.8.20 10.14.8.16 10.1.6.222 8.8.8.8 8.8.4.4 1.1.1.1"
```

Para mudar, edite o `gwos.conf` e rode `gwos-integrar` — não mexa no
`named.conf.options`.

### Duas coisas que valem saber sobre essa lista

**A ordem é uma dica, não uma regra.** O BIND escolhe o forwarder pelo tempo de
resposta medido, não pela posição na lista. Na prática os internos ganham por
serem mais perto, mas não conte com isso.

**Falha para o próximo só em timeout ou SERVFAIL.** `NXDOMAIN` é uma resposta
válida — se um resolver interno disser "esse domínio não existe" para um site
da internet, o BIND aceita e não tenta os públicos. Por isso o `verificar.sh`
testa cada forwarder resolvendo `google.com` e acusa os que falharem.

Se algum dos internos não resolver nomes externos, tire-o da lista global e
deixe só os públicos — os domínios do governo continuam funcionando, porque
estão fixados por zona:

```
DNS_FORWARDERS="8.8.8.8 8.8.4.4 1.1.1.1"
```

### Domínios internos do governo

Não passam pela lista global: têm encaminhamento fixo por zona em
`config/named.conf.local`, o que é determinístico e não depende de qual
resolver respondeu mais rápido.

| Zona | DNS interno |
|------|-------------|
| `cartoriosap.sp.gov.br` | 10.1.6.222 |
| `policiapenal.sp.gov.br` | 10.14.8.20 |
| `prodesp.sp.gov.br` | 10.1.6.222 |

Para acrescentar outro domínio interno, copie um desses blocos.

## Nome novo na rede: zona ou host?

Na maioria das vezes **não é zona**. Decida assim:

| Você quer | Faça |
|-----------|------|
| Um nome apontando para um IP da LAN (`portal`, `samba`, uma impressora) | `gwos dns add <nome> <ip>` — vira host no domínio interno, servido pelo dnsmasq |
| Mandar um domínio inteiro para outro servidor DNS | zona `type forward` |
| Ser autoridade de um domínio, com vários registros | zona `type master` + arquivo de registros |

### Criar uma zona

Com o módulo `60-painel-web` instalado, pela tela **DNS** do painel. Ou pelo
terminal:

```bash
gwos-zona listar
gwos-zona adicionar sistema.sp.gov.br "10.1.6.222 10.14.8.16"
gwos-zona remover   sistema.sp.gov.br
```

O `gwos-zona` valida o domínio e os IPs, roda `named-checkconf` **antes** de a
alteração valer e desfaz sozinho se a configuração não passar. É o mesmo
caminho que o painel usa — ele não escreve em `/etc/bind` por conta própria.

> **`ZONAS_INTERNAS` do `gwos.conf` só vale na instalação.** É lida uma vez, por
> este módulo, para criar as zonas iniciais do perfil da unidade. Editar essa
> linha depois **não muda nada** — nem com `gwos-integrar`, que regenera os
> forwarders e o encaminhamento do domínio interno, mas não as zonas (regerá-las
> em bloco apagaria as que você acrescentou à mão). Para mudar uma zona depois da
> instalação, use `gwos-zona`.

Para escrever à mão, use **`/etc/bind/named.conf.zonas-locais`** — o instalador
cria esse arquivo uma vez e nunca mais o toca. O `named.conf.local` **é
sobrescrito** a cada reinstalação do módulo; zona escrita lá se perde.

```bash
cat >> /etc/bind/named.conf.zonas-locais <<'EOF'
zone "exemplo.sp.gov.br" {
    type forward;
    forward only;
    forwarders { 10.1.6.222; };
};
EOF

named-checkconf && rndc reload
dig exemplo.sp.gov.br @127.0.0.1
```

Se a zona for permanente e tiver de valer para toda instalação nova,
acrescente-a a `config/named.conf.local` neste repositório em vez do arquivo
local — assim ela vai junto no git.

## Instalação

```bash
bash instalar.sh
```

### Antes de instalar em um servidor que já existe

O instalador aborta se a porta 53 estiver ocupada, mas vale conferir antes:

```bash
ss -lnup | grep :53
```

- **Samba como controlador de domínio (AD DC)** tem DNS próprio na 53. Não
  instale o BIND9 nessa máquina — use outra para o DNS. Samba como servidor de
  arquivos (membro ou standalone) não usa a 53 e não dá conflito.
- **systemd-resolved** ocupa a 53 em `127.0.0.53`: `systemctl disable --now systemd-resolved`.

Se o `/etc/resolv.conf` estiver imutável (`chattr +i`, comum em servidor para o
DHCP não sobrescrever), o módulo detecta, não força e imprime os três comandos
para você trocar à mão.

## Sozinho

Resolver completo para a LAN. Os bloqueios podem ser editados à mão em
`/etc/bind/db.rpz.gwos` (duas linhas por domínio: `dominio.` e `*.dominio.`),
seguidos de `rndc reload`.

## Com os outros

- **25-dns-interno**: o domínio interno passa a ser encaminhado ao dnsmasq
- **10 + 60**: a RPZ passa a ser gerada do banco por
  `scripts/aplicar_bind9_rpz.sh`
- **40-firewall**: todo DNS da LAN é forçado para cá, impedindo bypass da RPZ

## Verificação

```bash
bash verificar.sh
```

Confere o serviço, o `named-checkconf`, a presença do `response-policy` e
resolve `google.com` por `127.0.0.1`.

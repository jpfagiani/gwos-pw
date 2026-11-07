# 30-hora-chrony — servidor de hora (NTP)

Mantém o relógio do gateway certo **e** serve a hora para as máquinas da LAN.

## O que faz

- Instala o `chrony` e desativa o `systemd-timesyncd`
- Define o fuso `America/Sao_Paulo`
- `gwos-integrar` grava `/etc/chrony/conf.d/gwos.conf` com:
  - `server <NTP_SERVIDORES> iburst prefer` — a fonte interna da rede
  - `pool <NTP_POOL> iburst` — reserva, para o relógio não derivar se a fonte
    interna cair
  - um `allow <rede>` para cada rede interna de `/etc/gwos/gwos.conf`
  - `local stratum 10`

## Fontes de hora

Vêm de `/etc/gwos/gwos.conf`. Padrão:

```
NTP_SERVIDORES="10.14.8.20"
NTP_POOL="pool.ntp.br"
```

`NTP_SERVIDORES` aceita mais de um, separados por espaço. Depois de mudar,
rode `gwos-integrar`.

Apontar para o servidor interno é o certo quando ele é o controlador de
domínio: a latência é menor e o relógio das estações precisa bater com o dele
de qualquer forma. O pool público fica só como rede de segurança.

> **Diferença em relação ao instalador antigo:** ele só ajustava o pool, sem
> `allow` — o chrony ficava só sincronizando o próprio relógio e recusava os
> clientes da LAN. Agora o gateway é de fato o servidor NTP da rede, e o
> `local stratum 10` faz com que continue servindo a hora mesmo com a internet
> fora.

## Instalação

```bash
bash instalar.sh
```

## Nos clientes

Aponte o servidor NTP para o IP do gateway. No Windows:

```cmd
w32tm /config /manualpeerlist:"10.14.29.10" /syncfromflags:manual /update
```

## Operação

```bash
chronyc sources     # fontes de tempo
chronyc clients     # quem está sincronizando com este gateway
chronyc tracking    # desvio atual do relógio
```

As redes liberadas são reescritas automaticamente quando a rede muda — basta
rodar `gwos-integrar` (o `gwos ip` já faz isso).

## Verificação

```bash
bash verificar.sh
```

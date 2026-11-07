# 00-base — rede e preparação da máquina

Único módulo que reconfigura a rede. Os outros apenas **leem** os parâmetros
que ele grava.

## O que faz

- Pergunta o nome do servidor (hostname) e acerta o `/etc/hosts` — sem a
  entrada `127.0.1.1` o `sudo` demora segundos a cada comando
- Pergunta o perfil da unidade, o domínio, os resolvers e o servidor de hora
- No modo gateway: WAN, LAN, rede e rede secundária
- Máquina física: firmware de placas de rede, microcode da CPU, desativa o
  NetworkManager e fixa o nome das interfaces pelo MAC
  (`/etc/systemd/network/70-gwos-*.link`)
- Gera `/etc/network/interfaces` (com backup do anterior)
- Aplica os IPs com `ip addr` — **não** reinicia o `networking`, para não
  derrubar a sessão SSH
- Liga o encaminhamento de pacotes em `/etc/sysctl.d/90-gwos-base.conf`
- Grava `/etc/gwos/gwos.conf`

## Dois modos: gateway e servidor

O módulo detecta quantas placas de rede a máquina tem e se ajusta:

| Modo | Quando | O que pergunta |
|---|---|---|
| **gateway** | duas ou mais placas, e você confirma | WAN, LAN, rede interna, rede secundária, `ip_forward` |
| **servidor** | uma placa só, ou você recusa o papel de gateway | interface, endereçamento (manter/fixo/DHCP) — sem roteamento |

Nos dois modos ele pergunta nome do servidor, domínio, perfil da unidade,
resolvers e servidor de hora.

## Instale-o sempre — inclusive num servidor de uma placa

É ele quem grava o `/etc/gwos/gwos.conf`. **Sem esse arquivo, cada módulo
seguinte adivinha a rede sozinho — e adivinha errado.** O sintoma real, numa
máquina de uma placa:

```
[!] Sem /etc/gwos/gwos.conf — parâmetros detectados automaticamente:
    WAN=enp0s3  LAN=enp0s3  rede=10.0.2.0/24  gateway=10.0.2.15
```

WAN e LAN na mesma placa, a rede antiga e o IP da própria máquina como gateway.
O firewall gerado a partir disso descarta o tráfego da LAN e não encaminha nada.

Um servidor só de DNS, hora, proxy ou painel também precisa dele — o modo
`servidor` existe exatamente para isso e não toca no roteamento.

```bash
bash instalar.sh
```

## Cancelar é diferente de errar

No resumo final:

| Resposta | O que acontece |
|---|---|
| `s` | aplica |
| `n` | **refaz as perguntas**, com as respostas anteriores como padrão |
| `c` | cancela e sai com código 3 — o `instalar-todos.sh` interrompe a instalação inteira, em vez de seguir para módulos que dependem deste |

## O que é validado antes de gravar

O `/etc/network/interfaces` é gerado a partir das respostas, e um valor inválido
ali derruba a placa **no boot seguinte** — durante a instalação o `ip addr` ainda
funciona e o erro passa despercebido. Por isso:

- IP e máscara: octetos 0–255, prefixo 0–32, máscara contígua (`255.0.255.0` é recusada)
- gateway: precisa ser um vizinho na mesma rede do IP; loopback, `0.0.0.0` e o
  próprio endereço da máquina são recusados
- gateway vazio é aceito, com aviso, e a linha simplesmente não vai para o
  arquivo — escrever `gateway` sem valor faz o `ifup` abortar a interface inteira
- o IP da LAN precisa pertencer à rede declarada

## Depois

Para trocar o IP use sempre `gwos ip <novo_ip> [rede]` — ele valida, faz
backup e recarrega os serviços. Nunca `systemctl restart networking` num
gateway remoto.

## Verificação

```bash
bash verificar.sh
```

Confere as interfaces, o IP do gateway, o `ip_forward` e a rota padrão.

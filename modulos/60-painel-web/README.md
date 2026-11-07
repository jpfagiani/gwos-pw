# 60-painel-web — painel de administração

Nginx + PHP-FPM 8.4 servindo o painel, mais o comando `gwos`, as permissões
sudo e as tarefas de cron.

## Depende de

- repositório GWOS completo (`app/`, `public/`, `routes/`, `scripts/`)
- **10-banco-mariadb** — opcional; sem ele o painel sobe em modo leve

## Modo completo e modo leve

O painel se adapta ao que existe na máquina, igual aos instaladores.

| | Modo leve (sem banco) | Modo completo |
|---|---|---|
| Módulos, estado, status dos serviços | sim | sim |
| Rede, DNS, hora, nomes internos | sim | sim |
| Grupos, IPs, domínios, horários, NAT | não | sim |
| Dashboard e relatórios | não | sim |
| Usuários | `/etc/gwos/painel-usuarios.json` | tabela `admins` |

No modo leve a home passa a ser a tela **Módulos**. Para migrar depois, instale
o `10-banco-mariadb` e reexecute este módulo — o painel completo liga sozinho.

## A tela Módulos

Lê `/etc/gwos/modulos.d/` a cada carregamento: instalou o chrony pelo terminal,
a seção aparece na próxima página. Não há cadastro nem script de atualização.
Mostra o estado dos serviços de cada módulo, o `gwos.conf` em vigor e o comando
de instalação dos que ainda faltam.

## PHP: a versão da distribuição

O módulo detecta qual PHP o Debian oferece e usa esse — Debian 12 traz **8.2**,
Debian 13 traz **8.4**, e as duas servem. Só recorre ao repositório sury.org se
a distribuição for antiga demais (abaixo de 8.1), porque é repositório de
terceiros e uma dependência a mais para quebrar numa atualização.

A versão escolhida vai para `PHP_VERSAO` no `gwos.conf`, e o painel usa isso
para saber qual serviço monitorar. Fixar `php8.4-fpm` faria o painel reportar
"parado" num Debian 12, onde o serviço se chama `php8.2-fpm`.

## O que faz

- Instala Nginx e PHP com as extensões usadas pelo painel
- Publica o site em `public/`, com `try_files` para o front controller
- Gera o `.env` a partir de `/etc/gwos/db.conf`
- Cria `/etc/sudoers.d/gwos` — só para os scripts que existem — e **valida com
  `visudo -c` antes de manter o arquivo**
- Liga `/usr/local/bin/gwos` ao `scripts/gwos-cli.sh`
- Instala o cron: backup diário, parser e importação do Squid, e worker de
  tarefas privilegiadas a cada minuto como `www-data`
- Põe o `www-data` no grupo `proxy` (é o que permite o dashboard ler o
  `access.log`)
- Provisiona o primeiro administrador com senha aleatória de uso inicial; contas existentes são preservadas

## Instalação

```bash
bash instalar.sh
```

## Acesso

| | |
|---|---|
| URL | `http://IP_DO_GATEWAY:8080` (porta 8080 por convenção do projeto) |
| Login | `admin@gwos.local` |
| Senha | Aleatória, exibida uma única vez no console do instalador; troca obrigatória no primeiro acesso |

## Como o painel conversa com os outros módulos

Ao salvar uma alteração, o painel chama via `sudo` os scripts de `scripts/`,
que regeram as configurações dos outros módulos:

| Ação no painel | Script | Afeta |
|----------------|--------|-------|
| Grupos e IPs | `aplicar_nftables.sh` | nftables + listas do Squid |
| Domínios | `gerar_squid_dominios.sh`, `aplicar_bind9_rpz.sh` | Squid + RPZ do BIND9 |
| Horários | `gerar_squid_acl.sh` | Squid |
| NAT 1:1 | `aplicar_nat.sh` | nftables + `tcp_outgoing_address` |

Se um desses módulos não estiver instalado, o script simplesmente não tem o
que atualizar — nada quebra.

As ações que alteram firewall, NAT, domínios ou horários são registradas na
fila persistente do painel e aparecem em **Histórico de tarefas**. O resultado
é conferido após o comando; `failed`, `timeout` e `unconfirmed` não devem ser
tratados como sucesso. O worker depende do cron, das permissões em
`storage/tarefas` e das regras sudoers instaladas por este módulo.

## Verificação

```bash
bash verificar.sh
```

Testa os serviços, o `nginx -t`, o `.env` (inclusive as permissões 600), o
sudoers, o cron e faz um `curl` no painel.

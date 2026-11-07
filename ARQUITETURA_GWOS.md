# Arquitetura GWOS

## Visão de componentes

```text
Navegador administrativo ──> Nginx/PHP-FPM ──> Router ──> Auth/CSRF ──> Controllers/Views
                                                   │                  ├─ MariaDB (opcional no modo leve)
                                                   │                  └─ Serviços/sistema por scripts privilegiados
Cliente LAN ──> Firewall/nftables ──> Squid ──> sessão GWOS ──> Portal público PHP
                                      │                         └─ arquivo de sessão consultado por helper
                                      └─ logs de acesso importados para MariaDB

BIND9/RPZ ── dnsmasq para nomes internos ── LAN
Chrony ── fonte NTP ── clientes LAN
```

## Limites de confiança

- O painel administrativo autentica administradores; o portal autentica usuários finais em tabelas/serviços separados.
- O roteador protege as rotas administrativas por padrão. Rotas do portal e recuperação foram explicitamente listadas como públicas.
- CSRF deve acompanhar toda requisição mutável; mudanças não devem usar GET.
- Scripts de infraestrutura são privilegiados e devem continuar em allowlists fixas, com argumentos validados.
- Saídas de serviço e logs podem conter dados controlados por clientes; escapar antes de renderizar e sanitizar ao registrar.
- Métricas de tráfego vêm de contadores do kernel e precisam de amostras válidas, intervalo e detecção de reinício.

## Dados e estado

- Configurações: `.env` e `/etc/gwos/gwos.conf`/arquivos geridos pelos módulos.
- Dados administrativos e de política: schema SQL e migrations em `database/`.
- Estado de instalação: `/etc/gwos/modulos.d/`.
- Acesso de internet: tabelas/migrações de acesso e arquivo de sessões consumido pelo helper Squid.
- Logs: access.log do Squid, logs de serviço e tabela de auditoria.

## Mudanças desta entrega

A camada de roteamento adiciona uma verificação comum de autenticação, CSRF e somente leitura para `operador`. A camada de sessão impõe inatividade e duração máxima. Schema e migration `003_remover_credencial_padrao.sql` removem a conta pré-semeada insegura; o módulo do painel cria ou rotaciona a credencial com senha aleatória de uso inicial.

## Pendências arquiteturais

Fila persistente de tarefas com estados, timeout, código de saída, exclusão mútua e confirmação posterior; versionamento de configurações; rollback temporizado de firewall/rede; inventário de DHCP/dispositivos; alertas e métricas de host confiáveis. Estas etapas dependem de contratos operacionais e homologação em Debian.

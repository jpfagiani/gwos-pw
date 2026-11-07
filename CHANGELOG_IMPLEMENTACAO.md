# Changelog de implementação

Versão de entrega: `0.2.0-dev` (não homologada em produção)  
Data: 06/11/2026

## Segurança e acesso

- Logout administrativo alterado de GET para POST com token CSRF no formulário.
- Router aplica autenticação por padrão; mantém allowlist explícita para login, portal e recuperação de senha.
- Requisições mutáveis exigem CSRF na camada comum, inclusive para rotas públicas de formulário.
- Perfil `operador` fica somente leitura, com exceção de logout e troca da própria senha.
- Sessões expiram após 30 minutos sem atividade ou no teto configurável de 8 horas.
- Cada requisição administrativa revalida se a conta continua ativa e sincroniza o perfil atual da sessão.
- Auditoria redige recursivamente campos com nomes de senha, token, segredo e chave privada antes de gravar `dados_antes/dados_depois`.
- Schema deixa de semear a conta com senha fixa; migration 003 remove apenas a credencial legada conhecida, e o instalador provisiona/rotaciona a conta com senha aleatória de uso inicial exibida uma vez no console.

## Monitoramento e interface

- Contadores ausentes/inválidos do dashboard deixam de virar zero.
- Cálculo de tráfego agora é `(delta_bytes * 8) / delta_segundos`, com detecção de contador reiniciado.
- Coleta evita sobreposição e usa backoff exponencial limitado até 60 segundos quando há erro.
- Serviços do dashboard vêm do catálogo de módulos registrados.
- Dashboard apresenta carga, memória disponível, uso do disco raiz e tempo ligado quando o kernel expõe esses dados.
- Paleta aproximada à especificação v2.0; drawer móvel com backdrop, Escape, suporte a `inert` e foco visível.

## Operações, relatórios e recuperação

- Ações privilegiadas do painel agora entram em fila persistente allowlisted, com identificador, estados, timeout de 60 s, deduplicação, histórico de 90 dias e confirmação posterior do serviço/firewall.
- Worker cron roda como `www-data`; comandos são invocados por caminhos fixos, `sudo -n` e parâmetros NAT validados. A interface não apresenta saída bruta dos comandos.
- Incluída página de histórico de operações com atualização periódica.
- Backup usa `umask 077`, diretório restrito, arquivo temporário, verificação gzip/tar, SHA-256 e retenção dos sidecars.
- Restauração valida checksum quando presente, integridade e nomes/tipos de membros esperados, preserva a rede atual e propaga falhas de aplicação.
- Sessões foram movidas para `/var/lib/gwos/sessions`; backups permanecem inacessíveis ao usuário web.
- Relatório valida período (máximo 367 dias), exporta CSV limitado a 50 mil linhas, protege fórmulas e mascara IPs IPv4/IPv6 para perfil operador.
- Guia de instalação em VM descreve uso direto do ZIP, duas interfaces isoladas, verificação dos módulos e testes iniciais.

## Arquivos alterados

- `app/Core/Router.php`
- `app/Core/Auth.php`
- `app/Core/Session.php`
- `app/Controllers/DashboardController.php`
- `app/Views/layout.php`
- `app/Views/dashboard/index.php`
- `app/Core/Tarefas.php`, `app/Controllers/TarefasController.php`, `app/Views/tarefas/index.php`, `scripts/processar_tarefas.php`
- `app/Controllers/RelatorioController.php`, `scripts/backup.sh`, `scripts/restaurar_backup.sh`
- `config/config.php`
- `public/assets/js/app.js`
- `routes/web.php`

## Banco e infraestrutura

Schema de novas instalações alterado e migration `003_remover_credencial_padrao.sql` adicionada. O rollback não restaura uma credencial fixa: em laboratório, gere uma senha nova pela CLI `gwos senha <email>` e force troca no acesso. Instaladores, firewall, DNS, proxy, NAT, interfaces e serviços não foram executados nem alterados no host.

## Pendências

Timeout e confirmação do worker ainda precisam de teste integrado no Debian; rollback automático de firewall/rede, versionamento de configurações, testes de browser e homologação integrada do portal cativo permanecem necessários antes de produção.

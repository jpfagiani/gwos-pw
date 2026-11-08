# Changelog de implementação

Versão de entrega: `2.0.1-dev` (não homologada em produção)  
Data: 06/11/2026

## v2.0.1-dev — portal de senha de internet sem depender de e-mail

- Admin cadastra usuário de internet com senha digitada (entra já confirmado), define/redefine senha (encerra sessões) e gera link de uso único.
- Cadastro/“esqueci a senha” avisa quando o SMTP falha (antes mostrava sucesso sem enviar nada).
- Tela de SMTP em *Acesso com login* (servidor, porta, segurança, usuário, senha, remetente) + e-mail de teste; alterações vão para histórico e auditoria.
- Filtro “Decisão” do Monitoramento não fica mais cortado.

## Segurança e acesso

- Logout administrativo alterado de GET para POST com token CSRF no formulário.
- Router aplica autenticação por padrão; mantém allowlist explícita para login, portal e recuperação de senha.
- Requisições mutáveis exigem CSRF na camada comum, inclusive para rotas públicas de formulário.
- Perfil `operador` fica somente leitura, com exceção de logout e troca da própria senha.
- Sessões expiram após 30 minutos sem atividade ou no teto configurável de 8 horas.
- Cada requisição administrativa revalida se a conta continua ativa e sincroniza o perfil atual da sessão.
- Auditoria redige recursivamente campos com nomes de senha, token, segredo e chave privada antes de gravar `dados_antes/dados_depois`.
- Schema deixa de semear a conta com senha fixa; migration 003 remove apenas a credencial legada conhecida, e o instalador **pede ao operador a senha do administrador** (oculta, confirmada, mín. 8) ao criar a conta ou substituir a senha de fábrica. Instalação automatizada: `GWOS_ADMIN_SENHA`. A senha escolhida não força nova troca.

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

Schema de novas instalações alterado e migration `003_remover_credencial_padrao.sql` adicionada. O rollback não restaura uma credencial fixa: em laboratório, defina uma senha pela CLI `gwos senha <email>` (pergunta no terminal). Instaladores, firewall, DNS, proxy, NAT, interfaces e serviços não foram executados nem alterados no host.

## Pendências

Timeout, confirmação e rollback automático precisam de teste integrado no Debian; testes de browser e homologação integrada do portal cativo permanecem necessários antes de produção.

## Complemento — requisitos operacionais do Relatório Mestre v2.0

- Migration `004_operacional_v2.sql` adiciona campos de protocolo/porta e interface, expiração de IP, inventário observado e alertas deduplicáveis; o instalador do banco aplica as migrations também em bancos existentes.
- Monitoramento e ranking consultam os registros brutos importados do Squid. O log passou a registrar `%<st` (resposta) e `%>st` (requisição); a migration `005_trafego_direcional.sql` adiciona o campo, preservando `NULL` para upload legado não medido.
- Inventário é preenchido por leases DHCP, vizinhos de rede e acessos Squid observados; MAC/hostname ficam vazios quando a fonte não os fornece.
- Alertas de link WAN, serviços registrados, uso de disco/memória, idade/resultado do backup, pico de bloqueios, consumo por IP, MAC duplicado, bloqueio de login e novo equipamento são coletados a cada minuto; o operador pode reconhecer ou resolver eventos. Equipamentos e alertas não são dados fictícios.
- Agenda semanal mostra as regras ativas cadastradas; o simulador aceita IP, destino, protocolo, porta e horário, avalia grupos, whitelist/blacklist, porta segura e horário pela ordem conhecida. Ele é informativo e não consulta uma ACL em memória do Squid.
- Controle de acesso reúne políticas por grupo e sessões válidas do portal em uma página central, com links para as ações administrativas existentes.
- Monitoramento separa sockets estabelecidos do Squid (consulta `ss`) do histórico de access.log; a ficha individual mostra política, sessão, interface/lease quando conhecidos, volumes observados e histórico.
- O portal limita a três dispositivos simultâneos por usuário por padrão, com valor de configuração `acesso_max_sessoes_usuario` e bloqueio serializado por conta.
- Alertas incluem teste DNS limitado a quatro segundos, certificados próximos do vencimento, limite de sessões, operações failed/timeout/unconfirmed e mudanças recentes encontradas na auditoria.
- Regras de IP podem ter expiração. O cron desativa a regra vencida, registra auditoria e enfileira a atualização do firewall e do Squid.
- A página de auditoria e a impressão das páginas de relatório permitem salvar em PDF pelo navegador. O PDF gerado é uma impressão HTML; não há biblioteca de PDF embutida.
- Gráfico LAN/WAN passou a carregar histórico real RX/TX do kernel, coletado a cada minuto e mantido por sete dias.
- Configurações agora validam interface/rede/porta/limites, mostram diff e confirmação antes de gravar, versionam valores sem persistir segredos no histórico e permitem reverter chaves não sensíveis.
- Firewall ganhou prévia read-only com `nft -c` e diff, snapshots do ruleset/config e rollback automático quando a aplicação ou pós-checagem falha. A troca de IP também guarda snapshots, valida serviços e restaura rede, valores DB e configurações diante de falha.
- Limitações restantes: a aba ativa observa sockets TCP estabelecidos nas portas do Squid, mas não determina destino remoto nem bytes por socket; o histórico do proxy não recebe destino IP/interface por fluxo; os bytes de upload são o tamanho HTTP da requisição, não todo o tráfego IP; tráfego que bypassa o proxy fica fora do ranking. O simulador compara configuração cadastrada, não a ACL carregada nem a resolução atual de DNS. Teste real de reachability WAN, 2FA e rollback de rede ainda requerem evolução/homologação de laboratório.

## Relatórios e sessões

- Migration 006 registra encerramentos de sessão por logout, expiração, revogação administrativa, desativação, troca de senha, substituição no mesmo IP e exclusão do usuário.
- Relatórios exibem totais por período e tráfego HTTP agrupado por IP/equipamento/grupo/interface disponível, com exportação CSV de tráfego e sessões e impressão/PDF pelo navegador.
- Interface de rede que não é associada ao log de acesso aparece como “Não coletada”; upload anterior à migration 005 segue sem estimativa.

- Simulador considera expiração de política na data escolhida, seleciona o CIDR mais específico e limita regra global de permissão ao grupo parcial conforme a ACL gerada.
- Ajustados relatórios para agregar múltiplos grupos exatos sem duplicar contagens e para restringir identificadores pessoais de sessões ao perfil operador.
- Relatórios passam a exibir/exportar alertas e novos dispositivos por período; sessões arquivadas preservam grupo no momento do login.

## Integridade da importação do Squid

- Migration 007 adiciona `log_key` único por inode/geração/offset. Reprocessar o mesmo trecho depois de uma falha não duplica acessos. A geração é salva atomicamente antes da importação e a identificação usa `fstat` do arquivo aberto para cobrir rotação.
- `parsear_logs.php` recalcula o agregado diário a partir da tabela canônica, em vez de somar novamente as últimas linhas do access.log. O script shell legado chama o mesmo recálculo e saiu do cron duplicado.
- O relatório diário agora agrega os registros canônicos por data/IP/domínio diretamente.


## v2.0.0-dev — layout e funções do Relatório Mestre v2.0 (07/11/2026)

- **Design system** novo em `public/assets/css/gwos.css`; `layout.php` refeito: cabeçalho navy (marca, busca global, “Servidor Online” com pulso real via `/ping`, data/hora, sino de alertas, menu do usuário, tema escuro opcional), menu lateral com submenus na ordem do relatório (Dashboard, Monitoramento, Controle de Acesso, Ranking, Equipamentos, Políticas e Regras, Agenda, Alertas, Relatórios, Diagnóstico, Configurações, Ajuda) e rodapé.
- **Dashboard** conforme a imagem de referência: Estado Geral, WAN, LAN (sparklines ao vivo), tráfego 24 h (faixas de 30 min a partir de `metricas_rede`; lacunas e contador reiniciado = sem dados), Top 10, alertas, acessos em tempo real, Controle Rápido, Simulador, Agenda, Equipamentos e Serviços (só módulos instalados).
- **Login** do painel e **portal de internet** redesenhados (logins continuam separados).
- **Ações rápidas** (`Politicas`, `PoliticaController`, `POST /politicas/acao`): alterar grupo, bloquear, liberar temporariamente (5 min a 7 dias, retorno automático à política anterior), revogar; prévia + confirmação, lote de até 50 IPs, auditoria, fila allowlisted. Migration `008_liberacao_temporaria.sql`; `expirar_politicas.php` restaura as regras suspensas. Disponível nas telas Dashboard, Equipamentos (lote), Ficha e Políticas; oculto e bloqueado (403) para o perfil operador.
- Novas telas: **Políticas e Regras**, **Diagnóstico** (serviços, interfaces, coleta, recursos, backup), **Ajuda**, **Busca global**. Ranking com gráfico e períodos Hoje/Mês; Monitoramento com abas Bloqueios e Sessões.
- Limite conhecido: a ação rápida atua só em regra de IP exato; a precedência final com redes CIDR é a do squid.conf/nftables e deve ser validada na VM.

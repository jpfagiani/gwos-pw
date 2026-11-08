# Testes e resultados

Data: 06/11/2026

## Verificações executadas

| Verificação | Resultado | Observação |
|---|---|---|
| Inventário do ZIP, rotas, controllers, views, schema, migrations e scripts | Concluído | Inspeção estática da cópia extraída. |
| Regras centrais de acesso, CSRF, sanitização da auditoria e provisionamento sem senha fixa | Implementadas; validação runtime pendente | Ambiente disponível não inclui PHP CLI nem MariaDB. |
| `php -l` | Não executado | PHP/WSL não estão instalados neste Windows; os novos controllers/serviços precisam ser validados na VM Debian antes da homologação. |
| `bash -n` | Passou nesta revisão | Todos os scripts `.sh` do projeto, inclusive os fluxos novos de rollback. |
| JavaScript `node --check` | Passou nesta revisão | `public/assets/js/app.js` e scripts inline das views sem conteúdo PHP embutido. |
| Fila de operações | Implementada; integração pendente | Não foi possível exercitar cron, sudoers, concorrência, timeouts ou confirmação sem Debian/Squid/nftables. |
| ZIP de instalação | Conferido estruturalmente nesta revisão | O conteúdo do projeto é empacotado na raiz; migrations 006–007 e relatórios estão presentes; `.env` não está incluído. |
| Backup/restauração | Implementadas verificações estáticas | Não foi feito dump, restauração nem teste de checksum em banco real. |
| Teste visual responsivo | Pendente | Requer navegador e revisão visual em 1440, 1024, 768 e 390 px. |
| Teste de portal cativo/Squid e decisões por grupo | Pendente | Requer Debian 13 de laboratório, Squid, TLS, banco e clientes. |
| Timeout, falha e concorrência de tarefas | Pendente em laboratório | Fila e confirmação estão implementadas; comportamento real exige worker, sudoers e serviços Debian. |
| Rollback de firewall/rede | Implementado; validação runtime pendente | Prévia/diff, snapshot e rollback estão no código; falhas reais ainda precisam ser provocadas em VM isolada. |

## Classificação de evidência

- Estático: inventário e leitura de código/documentos.
- Implementado: alterações locais na cópia de entrega.
- Automatizado: `bash -n` e `node --check` nas listas descritas acima.
- Laboratório: não executado.
- Produção: não executado.

Esta versão está preparada para instalação isolada numa VM de laboratório, mas
não foi homologada para produção. Neste ambiente não há PHP CLI, MariaDB,
distribuição WSL ou VM conectada para executar a validação final.

## Funcionalidades v2 adicionadas nesta revisão

- Novas páginas: `/monitoramento`, `/ranking`, `/equipamentos`, `/equipamentos/ficha`, `/controle-acesso`, `/alertas`, `/agenda`, `/agenda/simulador` e `/relatorios/auditoria`.
- Novas coletas agendadas: alertas, inventário DHCP/vizinhos e expiração de políticas.
- Migration `004_operacional_v2.sql` executada pelo instalador também em banco existente; requer validação na versão MariaDB alvo.
- A interface de exportação PDF usa impressão do navegador. O ranking mede bytes de resposta e bytes de requisição HTTP do Squid. Alertas usam limites configuráveis e condições observadas no servidor.
- A migration 005 e o access.log medem requisições via `%>st`, separadas das respostas via `%<st`; registros antigos permanecem com upload `NULL`. Ela também grava expiração de lease/interface do inventário e limita três dispositivos ativos por usuário. Os volumes não representam todo o tráfego IP e excluem fluxos desviados do Squid.
- A aba de conexões consulta sockets estabelecidos nas portas do Squid; não mostra destinos remotos nem volume por socket. Histórico vem do banco alimentado pelo importador cron. O simulador aceita IP, destino, protocolo, porta e data, mas não interroga a ACL carregada nem resolve o domínio no momento do teste.
- A sintaxe PHP e o comportamento das migrations 004, alertas, descoberta de hosts e aplicação/expiração de firewall ainda não foram executados neste ambiente, pois não há PHP CLI, MariaDB ou VM Debian conectada.
- O verificador `php -l` continua indisponível neste Windows: não há PHP CLI nem parser PHP local instalado. A validação PHP e SQL deve ser executada na VM durante a instalação/homologação.


## Alterações desta revisão

- Adicionada a migration `006_historico_sessoes.sql` e tabela correspondente no schema de instalação nova.
- O ciclo do portal arquiva sessões encerradas por logout, expiração, revogação, desativação, troca de senha, substituição e exclusão; a sincronização de expiradas é transacional.
- A tela de relatórios agora agrega bytes Squid por IP/equipamento/grupo/interface observada, bloqueios e histórico de sessões, com exportação CSV separada e impressão/PDF.
- `bash -n` passou para todos os scripts `.sh`; `node --check` passou para o JavaScript global. A validação PHP/SQL e teste de execução continuam pendentes por falta de PHP CLI e MariaDB neste ambiente.
- A revisão estática também corrigiu o simulador para data simulada/CIDR mais específico e ampliou os relatórios com grupo de sessão, alertas e eventos de novos dispositivos. Estas alterações ainda não tiveram lint PHP nem execução MariaDB por indisponibilidade das ferramentas no ambiente.

- Revisão do importador Squid: identificada duplicação potencial após falha parcial e recálculo diário repetitivo; adicionada chave idempotente/migration 007 e agregação diária derivada da tabela canônica. Sintaxe PHP/SQL ainda precisa ser executada em Debian/MariaDB.

## Revisão de conclusão — 07/11/2026 (PHP 8.3.6, MariaDB 10.11, Ubuntu; fora do Debian 13)

| Verificação | Resultado |
|---|---|
| `php -l` em todos os `.php` | Passou (após corrigir `controle_acesso.php`) |
| `bash -n` em todos os `.sh`; `node --check` | Passou |
| `schema.sql` + migrations 001–007, duas rodadas | Passou, idempotente |
| Rotas → controllers/métodos/views | 0 inconsistências |
| Login (banco e modo arquivo), CSRF sem token = 403, `/logout` GET = 404, POST encerra sessão | Passou |
| Perfil operador: leitura 200, escrita 403 | Passou |
| Bloqueio após tentativas erradas | Passou |
| 28 telas com dados de teste | 200 sem warnings (após corrigir ranking e horários) |
| Provisionamento do admin com senha digitada (novo, legado, personalizado) | Passou |
| `gwos senha` com prompt | Passou |

Continuam pendentes (exigem Debian 13 real): Squid/portal cativo/TLS, nftables e rollback de rede, worker de tarefas com sudoers, captura visual responsiva.

## v2.0.0-dev — layout e ações (PHP 8.3, MariaDB 10.11, Chromium)

| Verificação | Resultado |
|---|---|
| `php -l`, `bash -n`, `node --check` | Passou |
| Migration 008 (duas rodadas) | Passou |
| 24 telas autenticadas com dados de teste | 200, sem warnings |
| Ações: prévia, liberar temporário, revogar, expiração via cron, bloquear, alterar grupo, IP inválido, sem CSRF (403) | Passou; regras anteriores restauradas |
| Operador: POST de ação = 403; botões ocultos | Passou |
| Capturas 1440/1024/768/390 px (login, dashboard, equipamentos, monitoramento, diagnóstico) | Geradas em `docs/capturas/`; layout sem rolagem horizontal da página no mobile |

Nos testes, os serviços aparecem “Parado” porque o ambiente de teste não tem systemd; em Debian real o estado vem de `systemctl is-active`.

## v2.0.1-dev — portal de senha (PHP 8.3, MariaDB, Chromium)

Fluxo completo testado: cadastro, link, senha fraca/boa, reuso de link (recusado), login, helper Squid (OK/ERR), limite de 3 dispositivos, sair, bloqueio após 5 erros, conta do portal não entra no painel admin.
Novo: admin cria usuário com senha digitada, duplicado/domínio errado/senha fraca recusados, redefinir senha encerra sessão, link gerado funciona de ponta a ponta, SMTP salvo/validado, cadastro público avisa quando o e-mail está indisponível. 24 telas do painel abertas no Chromium: todas HTTP 200, sem aviso de PHP nem erro de JavaScript.
Não validado aqui (só na VM Debian): nftables, Squid real, TLS do portal, systemctl.

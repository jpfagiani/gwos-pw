# Matriz de funcionalidades

| Área | Situação encontrada | Alteração nesta entrega | Validação pendente |
|---|---|---|---|
| Login administrativo | Hash, bloqueio por tentativas e troca no primeiro acesso | Revalida conta/perfil nas requisições; inatividade 30 min e teto absoluto de 8 h | Login, expiração e revogação contra MariaDB e modo leve |
| Auditoria | Registro de ações existente | Redação de campos com nomes sensíveis antes de persistir os objetos antes/depois | Conferir categorias reais de configuração em ambiente de laboratório |
| Logout administrativo | GET | POST protegido por CSRF | Teste HTTP em Debian e navegadores |
| Autorização | Controladores com verificações distribuídas; sem política comum | Roteador exige login por padrão; `operador` só pode ler, sair e trocar a própria senha | Revisão de permissões por perfil com responsáveis do ambiente |
| CSRF | Presente em boa parte dos fluxos | Verificação central para métodos mutáveis em rotas autenticadas e públicas | Cobertura de todos os formulários e chamadas AJAX no browser |
| Portal de internet | Cadastro, e-mail, login, sessão por IP, recuperação e administração já existem | Mantido separado do painel; redirect HTTP do Squid, sessão por IP, espelho atômico, revogação e limite padrão de 3 dispositivos por conta com serialização de login; precedência de grupos preservada | Integração real em clientes; troca IP/MAC, DHCP renew, sessões simultâneas e indisponibilidade precisam de VM |
| Grupos, domínios, horários, NAT | Funcionalidades existentes no banco e painel | Preservadas; protegidas pelo gate central | Regressão em banco de laboratório e estado aplicado |
| Firewall, DNS, proxy, Chrony e módulos | Configuração baseada em scripts e ferramentas do sistema | Preservados; menu/status do dashboard respeita módulos registrados | Validação e reversão em Debian com snapshots |
| Dashboard | Interface e contadores existentes | Unidades bps corretas; ausência/falha de coleta distinta de zero; evita requisições concorrentes; backoff após erro; serviços dos módulos instalados | Interface real, reinício de contador e falha de coleta |
| Navegação móvel | Controle JavaScript sem botão/layout correspondente | Drawer, backdrop, Escape, inert e foco visível | Larguras 1440, 1024, 768 e 390 px |
| Instalação em VM | Instalador modular para Debian já presente | Guia para usar o ZIP em VM Debian isolada com WAN/LAN separadas | Instalação e smoke test ainda precisam ocorrer na VM do usuário |
| Tarefas e operações seguras | Algumas rotinas rodavam sem confirmação posterior | Fila allowlisted com timeout, deduplicação e pós-checagem; diff validado de firewall antes da aplicação; snapshot versionado e rollback automático em erro; confirmação visual, histórico e reversão versionada de configurações | Cenários de falha e rollback precisam ser exercitados em VM Debian; aplicação de rede não deve ser homologada só por análise estática |
| Relatórios/auditoria | Relatórios existentes; auditoria básica de mudanças | Período validado, CSV limitado, neutralização de fórmula, máscara de IP para operador, filtro de eventos de auditoria e impressão HTML | Testar exportação e permissões com dados reais; salvar PDF depende do navegador |
| Monitoramento/ranking | Acesso bruto disponível via Squid, sem telas de filtros e ranking | Sockets estabelecidos lidos com `ss`; histórico filtrável por janela, IP, grupo com IP exato, domínio/usuário, protocolo e decisão; ranking 10/20/50; log direcional `%<st` resposta / `%>st` requisição compatível com registros antigos | Conferir permissões de `ss` e sockets Squid em Debian; destino IP e interface por fluxo não estão no log padrão; tráfego bypass fica fora |
| Equipamentos | Não havia inventário central | Descoberta por leases DHCP (com expiração), vizinhos ARP/NDP (inclui interface) e IPs vistos no Squid; ficha com MAC/hostname se fornecidos, política, sessão, consumo e histórico | Validar formatos reais de leases e política de retenção dos dispositivos antigos |
| Alertas/eventos | Não havia painel de eventos | Alertas para WAN, teste DNS, serviços, recursos, backup, certificados, bloqueios, consumo, MAC duplicado, login bloqueado, excesso de sessões, falhas operacionais, mudanças auditadas e novo equipamento | Requer cron e testar condições de recuperação em VM |
| Agenda/simulador | CRUD de regras por horário | Grade semanal e simulação por IP/destino/protocolo/porta/data; respeita expiração no instante simulado, escolhe CIDR mais específico e explica regra horária | Sem consulta à ACL carregada, DNS atual ou SNI; validar cruzando com squid.conf em VM |
| Políticas temporárias | IPs sem expiração | Expiração opcional; tarefa automática desativa e reaplica firewall/horários com auditoria | Confirmar coleta do cron e aplicação sem interrupção da rede |
| Controle de acesso | Grupos e sessões em páginas separadas | Visão central de políticas ativas e sessões válidas, com atalhos às ações administrativas existentes | Exercitar liberação e revogação em clientes de laboratório |
| PDF | Não havia exportação PDF nativa | Impressão HTML pronta para salvar como PDF pelo navegador | PDF não é gerado no servidor |
| Gráfico histórico | Só contadores em memória no navegador | Série real RX/TX do kernel coletada a cada minuto e retida por sete dias | Confirmar permissões cron e leitura das interfaces na VM |
| Backup/restauração | Rotinas existentes | Permissões restritas, SHA-256, validação de arquivo e retenção | Exercício de recuperação completo em laboratório ainda necessário |
| 2FA | Fora do escopo na especificação v2.0 | Não implementado | Não aplicável |


## Requisitos do relatório mestre — dados e evidência

| Requisito | Estado nesta cópia | Limite técnico atual |
|---|---|---|
| Tráfego por equipamento e período | Implementado | Baseado em access.log Squid; não mede fluxos que não passam pelo proxy.
| Download/upload | Implementado para novas coletas | Upload histórico é desconhecido; download/upload são bytes HTTP observados pelo Squid, não contadores totais de interface.
| Agrupamento por grupo/interface | Implementado com dados disponíveis | Política é agregada por IP exato; políticas CIDR não são projetadas para o relatório histórico. Interfaces não coletadas pelo Squid são exibidas como indisponíveis.
| Histórico de sessões | Implementado pela migration 006 | Registra início/fim/duração/motivo e grupo mais específico no login; sessões antigas sem snapshot de grupo exibem ausência. Só eventos após atualização podem ser recuperados.
| Eventos e dispositivos novos | Implementado no relatório | Usa alertas e eventos gravados pelo coletor; operador vê detalhes pessoais restritos; depende do cron e não recria ocorrências anteriores à coleta.
| Exportação CSV e PDF | Implementado | CSV para tráfego, sessões, alertas e dispositivos; PDF via impressão do navegador.

| Integridade do access.log | Implementado pela migration 007 | Chave estável por linha evita duplicação em reprocessamento/falha; evento antes da introdução da chave continua sem fingerprint.
| Agregado diário de acesso | Implementado | Recalculado de forma idempotente a partir de `acessos_internet`; não soma janelas sobrepostas do log bruto.

| Layout v2.0 (cabeçalho, menu, dashboard, login) | Implementado conforme as referências do Relatório Mestre | Capturas reais em `docs/capturas/` (1440/1024/768/390). Valores das imagens são ilustrativos; a tela usa dados reais |
| Ações rápidas por equipamento | Alterar grupo, bloquear, liberar temporário (reversível), revogar, lote com prévia | Aplicação no firewall/Squid depende do worker em Debian |
| Diagnóstico, Ajuda, Busca global, Políticas | Implementados | Checagens dependem de systemctl/cron no servidor real |

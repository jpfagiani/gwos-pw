# Resumo executivo — GWOS 2.0.1-dev

## Concluído nesta cópia

- Endurecimento do roteador: autenticação por padrão, CSRF central e perfil `operador` somente leitura.
- Logout administrativo convertido para POST protegido por CSRF.
- Sessões com expiração por inatividade e duração máxima; sessão administrativa revalida conta ativa e perfil.
- Remoção da senha fixa de novas instalações; criação/rotação da conta com a senha digitada por quem instala (sem senha fixa nem aleatória escondida); migration 003 para a credencial legada.
- Auditoria redige campos com nomes sensíveis nos dados antes/depois.
- Dashboard distingue falha/ausência de coleta, calcula taxa em bps, detecta contador reiniciado, impede sobreposição e usa backoff.
- Serviços exibidos no dashboard limitados aos módulos registrados.
- Dashboard com indicadores de carga, memória, disco raiz e uptime.
- Fila de operações privilegiadas allowlisted com histórico, deduplicação, timeout, estados de falha e verificação posterior.
- Backups e restauração com permissões restritas, integridade/checksum, retenção e validação dos membros do arquivo.
- Exportação CSV com período limitado, neutralização de fórmulas e máscara de IP/identificadores para operadores.
- Importação do access.log idempotente por chave de linha, com migration 007, e recálculo diário sem duplicar janelas.
- Diretório de sessões separado do diretório restrito de backups.
- Roteiro específico para extração do ZIP e instalação isolada numa VM Debian, com WAN/LAN em redes de laboratório.
- Drawer móvel, backdrop, Escape, inert e foco visível; paleta alinhada à especificação.
- Relatórios operacionais por equipamento/grupo, com upload/download observado, bloqueios, histórico de sessões com grupo do login, alertas, dispositivos novos e exportação CSV/impressão PDF.
- Documentos de diagnóstico, arquitetura, matriz, changelog, testes e atualização incluídos.

## Fechado na revisão de conclusão (07/11/2026)

- Senha do administrador agora é digitada na instalação (`pedir_senha_admin` em `modulos/comum/lib.sh`; `GWOS_ADMIN_SENHA` para modo automático); `gwos senha <email>` também pergunta no terminal.
- `php -l` em todos os PHP: corrigido erro de sintaxe em `operacional/controle_acesso.php` (faltava `?>`).
- Schema + migrations 001–007 aplicados e reaplicados (idempotência) em MariaDB 10.11: OK. Seed de domínios corrigido (coluna inexistente; agora idempotente).
- Runtime com PHP 8.3 + MariaDB: login, 28 telas, CSRF (403), perfil operador somente leitura, bloqueio por tentativas, logout POST. Bugs corrigidos: `/ranking` dava 500 (variável `$dados` colidia com `extract(EXTR_SKIP)` em `view()`) e warning em `horarios` (`global` dentro de view).

- Layout refeito conforme as imagens do Relatório Mestre v2.0 (cabeçalho, menu, dashboard, login) e funções novas: ações rápidas com prévia, liberação temporária reversível, Políticas, Diagnóstico, Ajuda e Busca. Capturas reais em `docs/capturas/`.

## Pendente antes de considerar pronto

- Testes do portal de internet integrado a Squid/MariaDB/TLS em Debian 13.
- Testes integrados de ID/estado/timeout/concorrência/resultado das tarefas e rollback de firewall/rede.
- Revisão completa de sudoers, telas de configurações, regras por perfil e proteção HTTPS de produção.
- Integração real em Debian 13 com PHP/MariaDB, portal cativo/Squid/TLS, tarefas privilegiadas e rollback.
- Validação visual com capturas em diferentes resoluções.
- Alguns dados previstos no relatório (destino IP por acesso, interface de rede por fluxo, CIDRs no agrupamento histórico e fluxos fora do Squid) não existem nas fontes atuais e são mostrados como indisponíveis, sem inferência.

## Evidência disponível

- Sintaxe dos scripts shell alterados e do JavaScript global/inline verificada localmente; migrations e histórico novo requerem execução na VM.
- Uma varredura confirmou que os formulários POST renderizados incluem CSRF, que `/logout` não está registrado como GET e que o schema novo não semeia administrador.
- PHP CLI/MariaDB, distribuição WSL, navegador conectado ao GWOS e Debian de laboratório não estão disponíveis neste ambiente.

## Risco e implantação

Esta é uma versão de desenvolvimento, não homologada para produção. Não foram executados instaladores nem alterados serviços, firewall, DNS, proxy, NAT ou interfaces no host. A migration 003 elimina uma credencial legada conhecida; faça snapshot/backup antes de qualquer teste e mantenha acesso de console ao gateway. Para instalações existentes, aplique migrations 006–007 após backup; a migration 006 inicia o histórico de sessões e a 007 adiciona idempotência ao importador.


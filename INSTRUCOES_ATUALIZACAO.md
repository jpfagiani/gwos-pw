# Instruções de atualização e validação

## Jeito automático (recomendado)

Envie o `GWOS-v2.0.1-dev.zip` ao servidor e rode como root:

```
# primeira vez: extraia só o script (ou o pacote todo) e execute
unzip -o GWOS-v2.0.1-dev.zip scripts/atualizar.sh -d /root/gwos-upd
bash /root/gwos-upd/scripts/atualizar.sh /caminho/GWOS-v2.0.1-dev.zip
# depois da primeira vez, também funciona:  gwos atualizar /caminho/GWOS-vX.zip
```

O script detecta a instalação, valida o pacote (ZIP e sintaxe PHP/shell, antes de alterar qualquer coisa), faz backup do código, da configuração e do banco, copia o código preservando `.env` e `storage/`, reaplica os módulos de banco e painel (migrations, cron, sudoers), confere se a aplicação carrega e se o painel responde, e **volta ao estado anterior sozinho** se algo falhar. Opções: `--simular` (só mostra o que mudaria), `--sim` (sem confirmação), `--dir /opt/gwos`, `--sem-backup-banco`, `--sem-modulos`. Log: `/var/log/gwos-atualizacao.log`; backups: `/var/lib/gwos/atualizacoes/`. Módulos de rede (DNS, firewall, proxy, hora) não são reinstalados.

## Atualizar manualmente (passo a passo)

Faça primeiro em uma VM de laboratório (ou com snapshot) e mantenha acesso ao console. Os comandos abaixo supõem que o GWOS está em `/opt/gwos`; ajuste se o seu estiver em outro diretório (`readlink -f /usr/local/bin/gwos` mostra o caminho).

1. **Backup** (no servidor, como root):
   ```
   cd /opt/gwos && ./scripts/backup.sh          # ou: gwos backup
   cp -a /opt/gwos /root/gwos-antes-v2.0.0
   ```
   Se for VM, tire também um snapshot.
2. **Enviar o pacote** `GWOS-v2.0.1-dev.zip` para o servidor (scp, WinSCP ou pen drive) e extrair em uma pasta temporária:
   ```
   apt-get install -y unzip
   mkdir /root/gwos-novo && unzip GWOS-v2.0.1-dev.zip -d /root/gwos-novo
   ```
3. **Copiar o código novo por cima, preservando a configuração** (`.env` e `storage/` não são sobrescritos):
   ```
   rsync -a --exclude '.env' --exclude 'storage/' /root/gwos-novo/ /opt/gwos/
   ```
4. **Reaplicar os módulos do banco e do painel.** Isso roda as migrations (inclusive a nova 008), atualiza cron, permissões e sudoers e reinicia o PHP-FPM:
   ```
   cd /opt/gwos/modulos
   bash 10-banco-mariadb/instalar.sh
   bash 60-painel-web/instalar.sh
   ```
   Contas de administrador já personalizadas são preservadas e **nenhuma senha é pedida**. A senha só é pedida se a conta ainda estiver com a senha de fábrica ou não existir (use `GWOS_ADMIN_SENHA` para modo sem terminal).
5. **Conferir:**
   ```
   bash /opt/gwos/modulos/verificar-todos.sh
   ```
   Abra o painel, entre, veja **Diagnóstico** e o **Histórico de tarefas**. Dê 2 a 3 minutos para os coletores (cron) gravarem as primeiras amostras de tráfego.
6. **Teste de fumaça das ações rápidas:** em um PC de teste, use *Liberar temporário* por 5 minutos e confirme que o acesso muda e depois volta. Não teste em equipamentos críticos.

**Voltar atrás:** restaure `/root/gwos-antes-v2.0.0` sobre `/opt/gwos` (ou o snapshot) e rode `bash 60-painel-web/instalar.sh`. A migration 008 só adiciona duas colunas em `ips`; o código antigo as ignora.

---

## Situação desta entrega

Esta cópia contém alterações em PHP, shell, JavaScript e schema. A origem ZIP foi preservada. Não execute os instaladores no servidor de produção antes de revisar a cópia em ambiente de laboratório.

## Preparação

1. Criar snapshot/backup do GWOS e do sistema Debian.
2. Guardar cópia de `.env`, `/etc/gwos/`, `/etc/squid/`, `/etc/bind/`, `/etc/nftables.conf`, `/etc/network/interfaces`, banco MariaDB e regras de sudoers, protegendo segredos e chaves.
3. Confirmar versão do PHP-FPM, extensões usadas, módulos GWOS e rotas publicadas.
4. Copiar o código para uma instalação de laboratório; não copiar `.env` para este ZIP.
5. Executar `php -l` nos arquivos PHP alterados e `node --check public/assets/js/app.js` e no script inline do dashboard.

## Banco

Aplicar migrations em ordem, incluindo `003_remover_credencial_padrao.sql`, `004_operacional_v2.sql`, `005_trafego_direcional.sql`, `006_historico_sessoes.sql`, `007_importacao_squid_idempotente.sql` e `008_liberacao_temporaria.sql`. A migration 005 inclui bytes direcionais, metadados de lease/interface e o limite de sessões do portal; o módulo `10-banco-mariadb` percorre migrations idempotentes quando reaplicado. A migration 003 remove apenas a conta cujo hash é exatamente o legado conhecido. O instalador do painel pede a senha do administrador (digitada por quem instala) ao criar a conta, ou preserva os administradores existentes. Novas instalações não recebem senha fixa no schema.

## Configuração

- Inatividade administrativa padrão: 30 minutos (`session.inatividade`).
- Limite absoluto padrão: 8 horas (`session.duracao_maxima`).
- Cookie de sessão com `HttpOnly`, `SameSite=Lax`; `Secure` depende de `APP_URL` estar em HTTPS.
- O perfil `operador` é somente leitura para rotas mutáveis, exceto logout e troca de senha.
- O worker de operações depende do cron em `/etc/cron.d/gwos`, acesso `www-data` ao diretório `storage/tarefas` e entradas sudoers exatas instaladas pelo módulo web. O diretório `/var/lib/gwos/backups` deve continuar `root:root` e modo `0700`; sessões ficam em `/var/lib/gwos/sessions`.
- A tela Histórico de operações só atualiza os estados enquanto o worker cron estiver ativo. Um estado `não confirmado` precisa de conferência manual no serviço antes de repetir uma ação.
- Backups novos incluem um arquivo lateral `.sha256`; restauração aceita membros nomeados do formato GWOS esperado. Faça um exercício de restauração em laboratório antes de confiar na rotina.
- O CSV de relatórios é limitado a 50.000 linhas por exportação; operador recebe IPs mascarados.

## Homologação em laboratório

Verificar login bom/ruim, conta desativada com sessão ativa, expiração por inatividade, limite absoluto, limite e concorrência de até três sessões finais, GET `/logout` rejeitado, POST logout sem/com CSRF, mutação sem CSRF, restrição de operador, admin e superadmin.

Verificar portal de usuários separado do painel: cadastro, e-mail, senha, sessão, expiração, revogação, IP trocado, limite de três dispositivos simultâneos, falha de serviço e políticas dos grupos. Confirmar que uma credencial final não acessa rotas administrativas. Conferir o redirect HTTP do Squid, helper, autenticação e fail-closed em máquina dedicada.

Verificar que linhas novas no access.log incluem bytes de requisição e resposta; registros legados continuam importáveis e mostram upload sem dados. Conferir ranking e equipamento por período.

Verificar a aba de conexões ativas contra `ss -Htn state established` enquanto clientes abrem conexões ao Squid; sockets não têm dados de destino remoto/volume por fluxo. Simular DNS indisponível, certificado próximo do vencimento, sessão acima do limite e operação falha para conferir alertas e resolução.

Verificar coleta de rede normal, interfaces ausentes, contador reiniciado, falha HTTP e intervalo irregular. Conferir unidade bps, ausência de valores fabricados e que módulos não instalados não aparecem como serviços parados.

Verificar fila com chamadas duplicadas e concorrentes; worker indisponível; comando inválido; timeout; saída não zero; serviço parado após comando; NAT esperado no ruleset; e recuperação de estado `running` órfão. Conferir que usuário web não consegue ler backups e que PHP-FPM consegue ler sessões após reinício.

Criar backup, validar checksum, corromper cópia de teste, tentar membro inesperado/link simbólico e restaurar numa máquina descartável. Confirmar que rede e banco ficam consistentes depois da recuperação.

Revisar visual em 1440, 1024, 768 e 390 px, navegação por teclado, foco, Escape e clique no backdrop.

## Rollback

1. Desabilitar o tráfego administrativo no balanceador/proxy do laboratório.
2. Restaurar snapshot/código anterior e backup de `.env`/arquivos do GWOS.
3. Confirmar login e rotas administrativas somente em laboratório.
4. Não restaure uma credencial fixa. Se voltar a uma versão anterior do código em laboratório, gere uma senha temporária nova pela CLI e obrigue a troca no próximo acesso.
5. Se o teste falhar no host de laboratório, restaurar snapshot antes de testar novamente.

Os scripts de firewall e troca de IP têm snapshot e tentativa de rollback automático no erro. Isso ainda não foi validado em VM; mantenha console/snapshot do hypervisor disponível e exercite cenários de falha numa VM descartável antes de habilitar alterações críticas em produção.


## Migrações do relatório mestre v2.0

Para atualizar uma instalação existente a partir do pacote v2.0-dev, faça backup do banco e execute em ordem as migrations ainda não aplicadas. A migration `006_historico_sessoes.sql` cria o histórico que alimenta a seção “Histórico de sessões encerradas”; ele começa a registrar encerramentos após a atualização e não recupera sessões antigas. A migration `007_importacao_squid_idempotente.sql` adiciona uma chave por linha para impedir duplicação quando o importador reprocessa o log. O código atual também grava o grupo mais específico no momento do login; sessões ativas anteriores à migration não têm esse snapshot. O script `scripts/ativar_acesso_internet.sh` aplica as migrations 002 e 004–008. Não execute novamente migrations antigas que alterem credenciais/configurações sem conferir os avisos de cada arquivo.

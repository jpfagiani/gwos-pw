# Diagnóstico atual do GWOS

Data: 06/11/2026  
Base inspecionada: `gwos-pw-main.zip`  
Estado: implementação local em cópia de entrega, sem instalação no host.

## Arquitetura e funcionalidades existentes

- Aplicação PHP própria com roteador, controladores, views, Bootstrap, Bootstrap Icons e JavaScript local.
- MariaDB opcional para o painel leve; quando presente, guarda administradores, grupos/IPs, domínios, horários, NAT, relatórios, auditoria e dados do portal.
- Módulos de sistema independentes: base/rede, MariaDB, BIND9, dnsmasq, Chrony, nftables, Squid e painel web.
- Login administrativo e portal de internet são fluxos separados. O portal existente usa usuários finais, e-mail de confirmação, sessão associada ao IP e integração do Squid por arquivo de sessões.
- Scripts privilegiados são instalados em Debian pelos instaladores; esta entrega não executa esses scripts.

## Riscos e divergências identificados

1. Logout do administrador era GET e não tinha proteção CSRF.
2. A autenticação era aplicada em muitos controladores, sem uma barreira comum para todas as rotas; perfis não tinham política geral no roteador.
3. Sessões não tinham expiração por inatividade ou limite absoluto no código PHP. Sessões existentes também não eram revalidadas quando uma conta era desativada ou seu perfil alterado.
4. O dashboard tratava interface ausente e erro de leitura de contador como zero; calculava bytes por segundo, embora os rótulos fossem bits por segundo; consultava serviços fixos mesmo se os módulos não estivessem instalados.
5. O layout não continha o controle de menu móvel correspondente ao JavaScript que já existia.
6. Algumas alterações de políticas iniciam scripts privilegiados em segundo plano e respondem antes de confirmar resultado. A auditoria atual não equivale a uma fila de tarefas com timeout, código de saída e verificação posterior.
7. A aplicação do portal cativo, captive detection, reconexão e falha segura só podem ser comprovadas em laboratório Debian com Squid, banco, TLS e clientes reais.

## Fontes do dashboard

| Dado | Fonte atual | Limite |
|---|---|---|
| IP, gateway e WAN padrão | `ip route` | Depende do comando e da configuração local.
| DNS | `/etc/resolv.conf` | Pode refletir stub/local resolver.
| Bytes WAN/LAN | `/sys/class/net/<interface>/statistics/*_bytes` | Contadores cumulativos; ausência/erro agora retorna `null`.
| Serviços | `systemctl is-active` por serviço dos módulos registrados | Não informa ainda estados distintos de não instalado, falha e acesso negado em todas as telas.
| Acessos | `/var/log/squid/access.log` e tabelas do MariaDB | Visibilidade HTTPS se limita ao que o proxy efetivamente registra.
| Métricas de CPU, memória e temperatura | Não havia fonte implementada | Não são apresentadas como se fossem coletadas.

## Limites desta entrega

O ZIP continha uma versão que já implementava parte importante do portal e do sistema modular. A atualização preserva essa base. Mudanças de banco não foram necessárias para os ajustes realizados. As operações privilegiadas, firewall e DNS não foram aplicados neste computador. A validação integrada ainda depende de Debian 13 de laboratório.

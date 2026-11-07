# GWOS — Manual de Instalação e Uso

**Gateway Web OS** — Firewall, DNS, Proxy Squid com controle de acesso por grupos de IPs.

- **IP do gateway:** escolhido no assistente de instalação
- **Painel web:** `http://<IP_DO_GATEWAY>:8080` (porta 8080 por convenção — a 80 fica para o portal de sistemas)
- **Login inicial:** `admin@gwos.local`; a senha aleatória é exibida uma vez no console do instalador.

---

## 1. Instalação do Zero

Para instalar a cópia recebida como ZIP numa máquina virtual, use o roteiro
[`INSTALACAO_VM.md`](INSTALACAO_VM.md). Os passos abaixo descrevem a instalação
por clone Git.

### Pré-requisitos

- Debian 12 ou 13 (instalação mínima)
- Duas interfaces de rede: uma para a LAN, uma para a WAN (internet)
- Acesso root
- Conexão com a internet

### Passo a Passo

```bash
# 1. Instalar git e clonar o repositório
apt install -y git
git clone https://github.com/jpfagiani/gwos.git /opt/gwos
cd /opt/gwos/install

# 2. Executar o instalador
bash install.sh
```

O `install.sh` é um atalho para `modulos/instalar-todos.sh` — a instalação foi
desmembrada em módulos, um por servidor (DNS, hora, firewall, proxy, banco,
painel). Instalar tudo de uma vez dá exatamente o mesmo resultado de antes;
a diferença é que agora dá para instalar um servidor de cada vez. Veja a
[seção 1.2](#12-instalação-por-módulos) e `modulos/README.md`.

O instalador pergunta:
- Interface WAN (saída para internet)
- Interface LAN (rede interna)
- IP do gateway (ex: 10.14.29.10)
- Rede LAN (ex: 10.14.29.0/24)
- **Rede secundária (opcional):** IP alias na LAN para rotear entre duas sub-redes  
  Exemplo: GWOS em `10.14.29.10` + alias `172.14.29.254` para alcançar uma rede antiga/outra unidade em `172.14.29.0/24`
- Configuração da WAN (DHCP ou IP estático)

Ao final, exibe a URL e o login. A senha inicial aleatória aparece uma vez no console do módulo do painel.

### Rede secundária — quando usar

Configure quando o GWOS precisa ser gateway para duas redes diferentes conectadas via switches:

```
Internet → Switch 172.x (outra unidade/legado) → cabo → Switch 10.x → GWOS (10.14.29.10)
```

O instalador cria automaticamente:
- Interface alias `LAN:1` com o IP secundário (ex: `172.14.29.254`)
- Regra nftables para não interceptar tráfego entre as redes
- ACL do Squid para a rede secundária

Nos PCs da rede `172.x`, adicionar rota estática (cmd como admin):
```cmd
route add 10.14.29.0 MASK 255.255.255.0 172.14.29.254 -p
```

> Após a instalação, acesse o painel e **troque a senha** no primeiro login.

### 1.1 Instalação em máquina real (bare metal)

O instalador detecta sozinho quando está numa máquina física
(`systemd-detect-virt`) e, nesse caso, faz automaticamente:

- habilita o componente **non-free-firmware** do Debian e instala o firmware
  das placas de rede (`firmware-linux`, `firmware-realtek`,
  `firmware-misc-nonfree`) — sem isso, placas Realtek/Broadcom comuns em
  mini-PCs podem nem aparecer ou cair sob carga;
- instala o **microcode** da CPU (Intel ou AMD, conforme o processador);
- desativa o **NetworkManager** se existir (ele briga com o ifupdown pelo
  controle das interfaces);
- **fixa o nome de cada interface pelo MAC** (arquivos
  `/etc/systemd/network/70-gwos-*.link`) — sem isso, uma atualização de
  kernel ou uma placa PCI nova pode renomear `enp1s0` → `enp2s0` e derrubar
  rede, firewall e Squid de uma vez.

Checklist antes de instalar:

1. **BIOS/UEFI:** configure **"Restore on AC Power Loss" = Power On** (o
   gateway precisa voltar sozinho após queda de energia) e desative
   suspensão/sleep. Se o boot travar pedindo teclado, desative "halt on
   keyboard error".
2. **Identifique as portas de rede:** com o cabo da internet em uma porta e
   a LAN na outra, use `ip -br link` (quem tem `UP` tem cabo) ou
   `ethtool -p enp1s0 10` (pisca o LED da porta por 10 s). Anote qual nome
   corresponde a qual porta — o instalador vai perguntar.
3. **Se a placa de rede não aparecer nem no instalador do Debian**, use a
   imagem de instalação que já inclui firmware (padrão a partir do
   Debian 12) ou instale com cabo em outra porta e rode o GWOS — ele
   instala os firmwares que faltam.

Migrando da máquina virtual para a física:

```bash
# Na VM (gera /var/lib/gwos/backups/gwos_backup_<data>.tar.gz):
/opt/gwos/scripts/backup.sh

# Na máquina física, após instalar o GWOS do zero:
/opt/gwos/scripts/restaurar_backup.sh /caminho/do/gwos_backup_<data>.tar.gz
```

> Os **nomes das interfaces mudam** entre a VM e a máquina real
> (ex.: `ens18` → `enp1s0`). Instale o GWOS do zero na física respondendo
> as perguntas do instalador — ele gera todas as configurações com os nomes
> certos — e use o backup só para trazer **banco e listas** (grupos, IPs,
> domínios), não os arquivos de rede.

> **Teste com monitor e teclado conectados** antes de colocar a máquina no
> lugar definitivo. Nunca mexa na rede de um gateway remoto sem um console
> de socorro — se algo der errado na troca de IP, é o console físico que
> salva (use `gwos ip`, nunca `systemctl restart networking`).

### 1.2 Instalação por módulos

Cada servidor tem sua própria pasta em `modulos/`, com `instalar.sh`,
`verificar.sh`, `desinstalar.sh` e um `README.md` próprio:

| Módulo | Serviço |
|--------|---------|
| `00-base` | Rede, firmware, `ip_forward`, estado compartilhado |
| `10-banco-mariadb` | Banco de dados |
| `20-dns-bind9` | Servidor DNS com RPZ |
| `25-dns-interno-dnsmasq` | Nomes internos da LAN |
| `30-hora-chrony` | Servidor de hora (NTP) |
| `40-firewall-nftables` | Firewall e NAT |
| `50-proxy-squid` | Proxy HTTP/HTTPS com SSL Bump |
| `60-painel-web` | Painel de administração |

```bash
# Escolher módulo a módulo (recomendado na primeira vez)
bash modulos/instalar-todos.sh --escolher

# Só o servidor DNS, nesta máquina
bash modulos/20-dns-bind9/instalar.sh

# Só os módulos listados
bash modulos/instalar-todos.sh 20-dns-bind9 30-hora-chrony

# Tudo menos a reconfiguração de rede
bash modulos/instalar-todos.sh --pular 00-base

# Diagnóstico de tudo que está instalado
bash modulos/verificar-todos.sh

# Remover um servidor sem tocar nos outros
bash modulos/50-proxy-squid/desinstalar.sh
```

**Quantas placas de rede.** Só o `00-base` e o `40-firewall-nftables` precisam
de duas — são os módulos de gateway, que roteiam de uma interface para a outra.
DNS, nomes internos, hora, proxy, banco e painel funcionam com uma placa só.
Numa máquina de uma interface, o instalador avisa antes de começar e pula os
dois módulos de gateway.

**Como eles funcionam isolados.** Nenhum módulo depende do instalador para
descobrir a rede: quem precisa da LAN, da rede ou do domínio interno lê
`/etc/gwos/gwos.conf`; se o arquivo não existir, ele é criado a partir do que
o sistema já tem — sem tocar em `/etc/network/interfaces`. Só o `00-base`
reconfigura a rede de verdade.

**Como eles se encontram.** Ao terminar, todo módulo roda `gwos-integrar`, que
olha o que existe na máquina e reescreve só os pontos de contato: o firewall
só redireciona 80/443 se houver Squid, o Squid só resolve por `127.0.0.1` se
houver BIND9, o BIND9 só encaminha o domínio interno se houver dnsmasq, e o
chrony libera as redes internas atuais. Por isso a ordem não importa —
instalar o Squid depois do firewall reajusta o firewall sozinho, e remover o
Squid tira os redirecionamentos antes que a LAN fique sem internet.

Só há uma dependência obrigatória: o **painel exige o banco**.

Detalhes completos em [`modulos/README.md`](modulos/README.md).

---

### Convivência com o Samba e o portal de sistemas

Os três projetos da unidade podem dividir a mesma máquina. Cada um atende numa
porta fixa, igual em qualquer unidade:

| Porta | Portal | Repositório | Quem usa |
|---|---|---|---|
| **80** | `portal-sistemas` | `jpfagiani/portal` | todos os usuários — atalhos |
| **8080** | `portal-gateway` | `jpfagiani/gwos` | administração do gateway |
| **8443** | `portal-samba` | `jpfagiani/smb` | administração dos arquivos |

**Instale nesta ordem: GWOS → smb → portal.** O `bootstrap.sh` do Samba lê
`/etc/gwos/modulos.d` para saber o que já existe na máquina. Invertendo a ordem,
ele não encontra nada e o firewall dele bloqueia as portas do GWOS em silêncio.

Quando os dois estão juntos, **o GWOS é o dono** de três recursos, e os
instaladores dos outros projetos recuam sozinhos:

| Recurso | Arquivo | Por quê |
|---|---|---|
| Firewall | `/etc/nftables.conf` | aqui ficam o NAT, o desvio para o Squid e o forçamento do DNS da LAN |
| Hora | `/etc/chrony/conf.d/gwos.conf` | aqui ficam os `allow` que autorizam a LAN a pedir a hora |
| Rede | `/etc/network/interfaces` | dois donos geram duas definições da mesma placa, e o `ifup` falha no boot |

Para mudar qualquer um deles use as ferramentas do GWOS — nunca o instalador do
outro projeto:

```bash
sudo gwos ip <novo-ip>                                    # endereçamento
sudo gwos-definir NTP_SERVIDORES <ip> && sudo gwos-integrar   # fonte de hora
sudo gwos-gerar-nftables                                  # firewall
```

No `bootstrap.sh` do Samba, com o GWOS já instalado: responda **`127.0.0.1`** no
DNS (é o BIND9 local, e sobrevive a uma troca de IP) e **mantenha** o
endereçamento como está. A pergunta do NTP nem aparece — o GWOS já é o dono do
chrony e o valor viria a ser descartado.

---

## 2. Atualizar sem Reinstalar

```bash
gwos update
```

Faz `git pull` e copia os arquivos para o servidor web sem tocar em configurações.

---

## 3. Painel Web

### Acesso

- **URL:** `http://IP_DO_GATEWAY:8080` (porta 8080 por convenção — veja [Convivência com o Samba e o portal de sistemas](#convivência-com-o-samba-e-o-portal-de-sistemas))
- **E-mail:** `admin@gwos.local`
- **Senha:** definida durante a instalação e mostrada uma única vez no console.

### Seções do painel

| Seção | Função |
|-------|--------|
| Dashboard | Acessos recentes do Squid, gráfico de banda RX/TX, estado das interfaces/serviços, carga, memória, disco e uptime |
| Grupos | Criar e gerenciar grupos de IPs (Liberados, Parciais, Bloqueados) |
| IPs | Associar endereços IP a grupos |
| Domínios | Blacklist e whitelist de domínios/sites |
| Horários | Regras de liberação por dia/hora (horário livre, almoço, etc.) |
| NAT 1:1 | Redirecionamento de portas externas para IPs internos |
| DNS | Adicionar nomes internos (ex: `samba.cdpni.local → 10.14.29.11`) |
| Relatórios | Tráfego por equipamento, grupo exato e interface disponível; bloqueios, sessões encerradas, alertas e dispositivos novos; CSV e impressão/PDF |
| Histórico de tarefas | Acompanhar aplicação de firewall, DNS, Squid e NAT solicitada pelo painel |
| Configurações | Parâmetros do sistema |

As alterações privilegiadas de firewall, DNS, proxy, horários e NAT são enfileiradas. O worker cron as executa como `www-data` usando comandos allowlisted; consulte **Histórico de tarefas** para ver o estado. `Falha`, `timeout` e `não confirmado` não significam sucesso. Em caso de resultado não confirmado, confira o serviço/regras no servidor antes de repetir a ação.

Relatórios CSV têm limite de 50.000 linhas por exportação. Para o perfil operador, IPs, MACs, nomes de equipamentos/usuários, e-mails e detalhes de eventos ficam mascarados/restritos nos relatórios. O tráfego é medido no proxy Squid; não representa fluxos que desviam do proxy. O histórico de sessões começa após a migration 006.

---

## 4. Grupos de IPs e Política de Acesso

Cada IP da rede deve ser cadastrado em um grupo para ter acesso à internet.

| Grupo | Comportamento |
|-------|---------------|
| **Liberados** | Acesso irrestrito à internet |
| **Parciais** | Acesso com restrições: blacklist de domínios e horários aplicados |
| **Bloqueados** | Apenas whitelist (sites obrigatórios/governo) |
| *(sem grupo)* | Bloqueado — nenhum acesso |

### Adicionar um IP

1. Painel → **Grupos** → selecione o grupo desejado
2. Clique em **Adicionar IP**
3. Informe o endereço IP e descrição

---

## 4.1 Acesso à Internet com Login (portal cativo)

Quem **não está em nenhum grupo de IP** passa a precisar de e-mail institucional
(`@sap.sp.gov.br`) e senha para navegar. IPs que já estão em grupo continuam
exatamente como antes.

**Como funciona.** O proxy é transparente, então ele não pede senha direto no
navegador. Em vez disso, ao abrir qualquer site sem sessão, o Squid redireciona
para `https://<IP do gateway>:8444/acesso`. Depois do login, o IP daquela máquina
fica liberado até a sessão expirar (padrão: 8 horas). O Squid consulta as sessões
pelo helper `/usr/local/sbin/gwos-squid-sessao`, que lê `/var/lib/gwos/sessions/sessoes.txt`
(o portal grava esse arquivo; o Squid não acessa o banco).

**Ativar num servidor já instalado:**

```bash
sudo bash scripts/ativar_acesso_internet.sh
```

Aplica a migração, instala o helper, troca o `squid.conf` (com backup e
**reversão automática** se o Squid reprovar), gera o site HTTPS do portal e agenda o
importador de logs. Pode rodar de novo sem problema.

**Usuário (primeiro acesso):** informa nome e e-mail → recebe um link no webmail
(`webmail.sap.sp.gov.br`, sempre liberado na whitelist) → no link **cria a senha**
(mín. 8 caracteres, letras e números) → faz login. A senha só nasce depois da
confirmação do e-mail, então digitar o e-mail de outra pessoa não adianta.
**Esqueci minha senha:** mesmo caminho, com link novo (vale 30 min, uso único).
O link do cadastro vale 24 h. Cinco senhas erradas bloqueiam a conta por 15 min.

**E-mail (SMTP).** Configure em *Configurações* as chaves `smtp_host`, `smtp_porta`,
`smtp_seguranca` (`nenhuma`, `starttls` ou `ssl`), `smtp_usuario`, `smtp_senha` e
`smtp_remetente` (padrão: servidor `10.14.5.16`, porta 25, sem autenticação). Teste em
*Acesso com login > Enviar e-mail de teste*. Sem SMTP funcionando ninguém consegue
concluir o cadastro.

**Isentar um computador da senha.** Em *Grupos*, crie um grupo do tipo **Isento de
login** e adicione o IP. Ele navega livremente e **continua registrado** no log.
> O tipo **Liberado** é diferente: desvia do proxy, não pede senha e **não deixa
> registro** de acesso. Para ter log de todos, use *Isento*.

**Registro de acessos.** *Acesso com login > Registro de acessos* mostra data/hora,
IP da máquina, usuário (nome e e-mail) e site, com filtros e exportação em CSV. O
`access.log` do Squid é importado a cada minuto para o banco (retenção de 365 dias;
ajuste com `ACESSOS_RETENCAO_DIAS`). IPs isentos aparecem como "IP isento de login".

**Administração.** *Acesso com login* lista as sessões ativas (botão **Derrubar**) e os
usuários (ativar/desativar/excluir). Desativar um usuário encerra o acesso dele.

**Limitações a conhecer**
- A sessão é **por IP**: num computador compartilhado, quem entrou por último vale
  para todos até sair (botão *Sair* na página de login) ou expirar. Com IP fixo por
  máquina funciona bem; a cada troca de IP (DHCP) é preciso entrar de novo.
- O redirecionamento em HTTPS só abre a página de login sem aviso de certificado nos
  computadores que **confiam na CA do GWOS** (a mesma do SSL Bump). O Windows e o
  Android costumam abrir o login sozinho ao detectar o portal cativo (sonda HTTP).
- A **blacklist de domínios também vale no DNS (RPZ do BIND9)** para todos, inclusive
  usuários logados. Para liberar um site de fato, remova-o da blacklist.
- Porta do portal: `ACESSO_PORTA` (padrão 8444). Se o servidor de arquivos (portal-samba
  usa 8443) rodar na mesma máquina, as portas não conflitam.

## 5. Blacklist e Whitelist de Domínios

### Blacklist (bloquear sites)

Painel → **Domínios** → **Blacklist** → Adicionar domínio

Depois clique em **Aplicar** para atualizar o Squid e o DNS (BIND9 RPZ).

Exemplos:
```
facebook.com
youtube.com
tiktok.com
```

### Whitelist (liberar sempre, mesmo para Bloqueados)

Painel → **Domínios** → **Whitelist** → Adicionar domínio

Usado para sites de governo, sistemas obrigatórios, etc. IPs do grupo Bloqueados conseguem acessar apenas esses sites.

### Aplicar mudanças no Squid

Após adicionar/remover domínios, clique em **Aplicar** no painel.  
Ou via terminal:

```bash
sudo /opt/gwos/scripts/gerar_squid_dominios.sh
```

---

## 6. Horários (Horário Livre)

Permite que IPs do grupo **Parciais** acessem sites da blacklist em horários determinados.

### Criar uma regra de horário livre

1. Painel → **Horários** → **Nova Regra**
2. Nome: ex. `Livre almoço`
3. Ação: `Permitir`
4. Horário: `11:00` até `13:00`
5. Dias: Seg a Sex
6. Grupo: deixar em branco (aplica a todos os Parciais)
7. Salvar → o Squid é atualizado automaticamente

### Horários padrão (pré-configurados na instalação)

| Nome | Horário | Dias |
|------|---------|------|
| Livre almoço (seg-sex) | 11:00–13:00 | Seg–Sex |
| Livre sábado | 08:00–17:00 | Sábado |
| Livre domingo | 08:00–17:00 | Domingo |

### Forçar atualização das regras no Squid

```bash
sudo /opt/gwos/scripts/gerar_squid_acl.sh
```

---

## 7. NAT 1:1 (Redirecionamento de Portas)

Painel → **NAT** → **Adicionar**

Exemplo: redirecionar porta 8080 da internet para o servidor Samba (porta 80):
- IP externo: `0.0.0.0` (qualquer)
- Porta externa: `8080`
- IP interno: `10.14.29.11`
- Porta interna: `80`

Ativar a regra após criar.

---

## 8. DNS Interno

Permite acessar servidores internos por nome em vez de IP.

```bash
# Adicionar
gwos dns add samba 10.14.29.11

# Listar
gwos dns list

# Atualizar IP
gwos dns update samba 10.14.29.12

# Remover
gwos dns del samba
```

Os nomes ficam disponíveis no domínio `.cdpni.local`:
- `samba.cdpni.local → 10.14.29.11`

Propagação em até 60 segundos.

---

## 8.1 Trocar o IP do Gateway

Use **sempre** o comando dedicado — nunca edite `/etc/network/interfaces` na mão:

```bash
# Trocar apenas o IP (mantém a rede atual)
sudo gwos ip 10.14.29.20

# Trocar IP e rede
sudo gwos ip 172.14.29.1 172.14.29.0/24
```

O comando faz, nesta ordem:

1. Valida o IP e confere que ele pertence à rede informada
2. Gera o novo `/etc/network/interfaces` em arquivo temporário e **valida a sintaxe com `ifquery` antes de gravar** (se inválido, nada é alterado)
3. Cria backup do arquivo original (`/etc/network/interfaces.bak.<data>`)
4. **Adiciona** o novo IP na interface antes de remover o antigo — a interface nunca fica sem IP e a sessão não cai
5. Atualiza banco (`ip_gateway`, `rede_lan`), `.env`, BIND9, Squid e regenera o nftables (cada um validado com sua ferramenta antes do reload)
6. Só então remove o IP antigo

O comando **não** executa `systemctl restart networking` — a mudança vale imediatamente via `ip addr` e persiste no reboot pelo arquivo já validado.

Depois da troca, se a **rede** mudou:
- Atualize gateway/DNS nos clientes da LAN
- Atualize hosts do DNS interno: `gwos dns update <host> <novo_ip>`

Se algo der errado, o próprio comando mostra o caminho do backup para restaurar.

---

## 9. Comando `gwos` (CLI)

Ferramenta de administração via terminal. Disponível após a instalação.

```bash
gwos <comando> [opções]
```

### Referência rápida

| Comando | Descrição |
|---------|-----------|
| `gwos status` | Status de todos os serviços |
| `gwos reload all` | Recarrega todos os serviços |
| `gwos reload squid` | Recarrega só o Squid |
| `gwos reload nginx` | Recarrega só o Nginx |
| `gwos diag` | Diagnóstico completo do sistema |
| `gwos ip <novo_ip> [rede]` | Troca o IP do gateway com validação e backup |
| `gwos update` | Atualiza o código via git |
| `gwos log tail [N]` | Últimas N linhas do log do Squid (padrão: 50) |
| `gwos log live` | Monitorar acessos em tempo real |
| `gwos log top [N]` | Top domínios acessados hoje |
| `gwos log erros` | Acessos negados recentes |
| `gwos nat list` | Listar regras NAT |
| `gwos nat add <ext> <int>` | Adicionar regra NAT |
| `gwos nat ativar <id>` | Ativar regra NAT |
| `gwos nat desativar <id>` | Desativar regra NAT |
| `gwos grupo list` | Listar grupos de IP |
| `gwos grupo ips <id>` | Ver IPs de um grupo |
| `gwos grupo add-ip <id> <ip>` | Adicionar IP ao grupo |
| `gwos dominio list` | Listar domínios |
| `gwos dominio add <dom> blacklist` | Adicionar à blacklist |
| `gwos dominio del <dom>` | Remover domínio |
| `gwos dns list` | Listar hosts DNS internos |
| `gwos dns add <host> <ip>` | Adicionar host DNS |
| `gwos dns del <host>` | Remover host DNS |
| `gwos backup criar` | Criar backup agora |
| `gwos backup listar` | Listar backups disponíveis |
| `gwos backup restaurar <arquivo>` | Restaurar backup |

### Gerenciar senha do painel

```bash
# Gerar senha temporária aleatória e exibi-la uma vez no console
gwos senha admin@gwos.local

# Resetar para senha personalizada
gwos senha admin@gwos.local minhaSenha123

# Listar admins cadastrados
gwos senha listar

# Desbloquear conta bloqueada por tentativas excessivas
gwos desbloqueio admin@gwos.local
```

---

## 10. Serviços do Sistema

| Serviço | Função |
|---------|--------|
| `nginx` | Servidor web (painel PHP) |
| `php8.4-fpm` | PHP para o painel |
| `squid` | Proxy HTTP/HTTPS com SSL Bump |
| `named` (BIND9) | DNS com RPZ (bloqueio por domínio) |
| `gwos-dnsmasq` | DNS interno para `.cdpni.local` |
| `nftables` | Firewall e NAT |
| `mariadb` | Banco de dados do painel |
| `chrony` | Servidor de hora (NTP) da LAN |

```bash
# Ver status de todos
gwos status

# Diagnóstico módulo a módulo (mais detalhado que o status)
bash /opt/gwos/modulos/verificar-todos.sh

# Reiniciar serviço individualmente
systemctl restart squid
systemctl restart nginx
systemctl restart named
```

### Arquivos de estado dos módulos

| Caminho | Conteúdo |
|---------|----------|
| `/etc/gwos/gwos.conf` | Interfaces, redes, IP do gateway, domínio interno, portas |
| `/etc/gwos/db.conf` | Credenciais do banco (0600) |
| `/etc/gwos/modulos.d/` | Um arquivo por módulo instalado |
| `/usr/local/sbin/gwos-integrar` | Re-costura as integrações entre os módulos |
| `/usr/local/sbin/gwos-gerar-nftables` | Regera as regras de firewall |
| `/usr/local/sbin/gwos-gerar-dnsmasq` | Regera a configuração do dnsmasq |

---

## 11. SSL Bump (Inspeção HTTPS)

O GWOS inspeciona tráfego HTTPS para aplicar a blacklist em conexões seguras.  
Para evitar alertas de certificado nos navegadores dos clientes:

1. Baixe o certificado CA: `http://IP_GATEWAY:8080/gwos-ca.crt`
2. Instale como "Autoridade Certificadora Confiável" no navegador ou no Windows

---

## 12. Logs e Diagnóstico

```bash
# Diagnóstico completo
gwos diag

# Acessos em tempo real
gwos log live

# Últimos 100 acessos
gwos log tail 100

# Top 20 domínios hoje
gwos log top 20

# Só bloqueados
gwos log erros

# Log bruto do Squid
tail -f /var/log/squid/access.log

# Log do Nginx
tail -50 /var/log/nginx/error.log

# Log do PHP
tail -50 /var/log/php8.4-fpm.log
```

---

## 13. Problemas Comuns

### Painel não abre / ERR_CONNECTION_REFUSED

```bash
systemctl status nginx
systemctl status php8.4-fpm
gwos status
```

### "Sem registros recentes" no dashboard

www-data precisa estar no grupo `proxy` para ler o log do Squid:

```bash
usermod -aG proxy www-data
systemctl restart php8.4-fpm
```

### Site bloqueado fora do horário incorreto

Verificar se as regras de horário foram geradas:

```bash
cat /etc/squid/conf.d/gwos_horarios.conf
```

Se estiver vazio, gerar manualmente:

```bash
sudo /opt/gwos/scripts/gerar_squid_acl.sh
```

### DNS não resolve domínios externos

```bash
systemctl status named
# Testar
dig google.com @127.0.0.1
```

### Conta do painel bloqueada

```bash
gwos desbloqueio admin@gwos.local
gwos senha
```

### Squid não inicia

```bash
squid -k parse          # verifica erros de configuração
journalctl -u squid -n 50
```

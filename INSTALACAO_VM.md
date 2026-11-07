# Instalação do GWOS em uma VM de laboratório

Este roteiro instala o pacote `0.2.0-dev` em uma VM Debian limpa. Ele foi
preparado para laboratório; o pacote ainda não foi homologado em hardware ou
rede de produção.

## 1. Criar a VM

Recomendação para uma VM de teste: Debian 13 minimal, 2 vCPU, 4 GB de RAM e
40 GB de disco. Reserve um snapshot antes da instalação. O instalador pode
reescrever a configuração de rede da VM.

Configure duas placas de rede:

1. **WAN:** NAT do hypervisor, com DHCP e saída para a internet. Ela permite
   baixar os pacotes Debian sem expor a VM diretamente à rede externa.
2. **LAN:** rede host-only/interna, para acessar o painel a partir do host e
   conectar VMs cliente de teste. Escolha uma sub-rede privada sem conflito
   com a rede real; desative o DHCP embutido do host-only se ele conflitar.

Não conecte a LAN da VM a uma rede de produção. O instalador `00-base` grava a
configuração de interfaces da VM. Com uma só placa, o projeto entra em modo
servidor e não instala o firewall de gateway.

## 2. Copiar e extrair o pacote

Anexe o ZIP à VM por ISO, pasta compartilhada ou SCP. No Debian, como `root`:

```bash
apt update
apt install -y unzip
mkdir -p /opt/gwos
unzip -q /caminho/GWOS-v0.2.0-dev.zip -d /opt/gwos
cd /opt/gwos
```

O ZIP contém os diretórios do projeto diretamente na raiz do arquivo (`app/`,
`database/`, `install/`, `modulos/`, `public/` e `scripts/`). Não copie `.env`:
ele é criado pelo instalador com as credenciais geradas na VM.

## 3. Instalar os módulos

Confirme que a VM tem as duas placas esperadas e rode:

```bash
bash install/install.sh
```

O instalador solicita identidade, domínio, WAN, LAN, endereço do gateway LAN e
perfil. Confira com atenção os nomes das interfaces, a sub-rede e o resumo
antes de confirmar cada módulo. Para o painel completo, mantenha selecionados
os módulos `10-banco-mariadb` e `60-painel-web`; para funções de gateway,
mantenha também DNS, firewall e proxy.

Anote a senha inicial mostrada uma única vez pelo instalador do painel. No
primeiro acesso ela exige troca.

## 4. Acessar e conferir

Do host, abra `http://<IP-LAN-DA-VM>:8080`. A porta usa HTTP simples e deve
ficar restrita à rede isolada do laboratório. Para verificar os módulos:

```bash
gwos status
bash /opt/gwos/modulos/verificar-todos.sh
```

No painel, confira **Módulos**, **Histórico de tarefas** e o dashboard. O
worker de tarefas roda pelo cron como `www-data`; se tarefas ficarem na fila,
verifique `/etc/cron.d/gwos`, o serviço cron e os logs locais.

Teste primeiro com uma VM cliente conectada somente à LAN host-only. Use uma
configuração IP estática no cliente para conferir conectividade, regras do
firewall, DNS e Squid. Testes do portal cativo e SSL Bump exigem clientes e
certificado de CA instalados no laboratório.

## 5. Validação antes de avançar

- Execute `php -l` para os arquivos PHP se estiver validando uma cópia de
  desenvolvimento; execute também `bash -n` nos scripts alterados.
- Confira o login, a troca inicial de senha, a sessão, os perfis e CSRF.
- Crie uma tarefa de cada tipo habilitado e confira seu resultado e estado no
  histórico.
- Faça backup e restaure em uma VM descartável antes de confiar na recuperação.
- Verifique regras, DNS, acesso HTTP/HTTPS, comportamento dos grupos e logs.
- Tire um novo snapshot da VM somente depois de guardar a senha inicial,
  registrar os endereços escolhidos e confirmar o acesso pelo console.

Se algum módulo falhar, não conecte a VM a uma rede de produção. Restaure o
snapshot do hypervisor e consulte os registros do instalador e
`TESTES_E_RESULTADOS.md`.

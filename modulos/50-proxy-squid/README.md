# 50-proxy-squid — proxy HTTP/HTTPS com SSL Bump

## Portas

| Porta | Uso |
|-------|-----|
| 3127 | forward-proxy explícito (configurado no navegador) |
| 3128 | HTTP transparente — o firewall redireciona para cá |
| 3129 | HTTPS transparente com SSL Bump |

## CONNECT (proxy explícito) e a porta 8443

Um cliente com o proxy configurado explicitamente (GPO, PAC, campo "Servidor
proxy" nas configurações do Windows) abre HTTPS via `CONNECT`, e o Squid só
autoriza `CONNECT` para portas na ACL `SSL_ports` — **antes** de consultar a
whitelist de domínios. `SSL_ports` cobre `443` e `8443` (a porta do
portal-samba, pela convenção do projeto). Sem a 8443 ali, o portal do Samba
fica inacessível para qualquer cliente que use este Squid como proxy explícito
— cadastrar o domínio na whitelist não ajuda, porque a recusa acontece antes.

Se uma unidade colocar outro serviço HTTPS em porta não padrão, acrescente a
porta em `SSL_ports` (e em `Safe_ports`, para o método normal) neste arquivo.

## O que faz

- Instala `squid-openssl` (cai para `squid` se não houver) e o `sarg` opcional
- Instala `config/squid.conf` em `/etc/squid/squid.conf`
- Cria em `/etc/squid/conf.d/` todas as listas que o `squid.conf` inclui — o
  Squid não sobe se qualquer uma faltar
- Gera a CA do SSL Bump e o banco de certificados dinâmicos, e publica a CA em
  `public/gwos-ca.crt` para download
- Se não houver `security_file_certgen`, comenta as diretivas de SSL e segue
  sem interceptação de HTTPS

Uma CA já existente é **preservada** numa reinstalação — os clientes não
precisam reinstalar o certificado.

## Arquivos gerados por outras peças

| Arquivo | Quem gera |
|---------|-----------|
| `gwos_redes.conf` | `gwos-integrar`, a partir de `/etc/gwos/gwos.conf` |
| `gwos_integracao.conf` | `gwos-integrar` (resolver: BIND9 local ou externo) |
| `gwos_ips_*.txt` | `scripts/aplicar_nftables.sh` (painel) |
| `gwos_whitelist.txt`, `gwos_blacklist.txt`, `gwos_sites_livres.txt` | `scripts/gerar_squid_dominios.sh` |
| `gwos_horarios.conf` | `scripts/gerar_squid_acl.sh` |
| `gwos_tcp_outgoing.conf` | painel (NAT 1:1) |

As ACLs de rede vêm do `gwos_redes.conf` em vez de serem enfiadas no
`squid.conf` com `sed`, como no instalador antigo — por isso cobrem faixas
fora do RFC 1918 e a rede secundária sem edição manual.

## Instalação

```bash
bash instalar.sh
```

## Sozinho

O proxy explícito funciona em `IP_DO_GATEWAY:3127`. Mas sem o painel as listas
de grupos ficam vazias e a última regra, `http_access deny all`, bloqueia todo
mundo. Para usar isolado, libere a LAN:

```bash
sed -i 's/^http_access deny all/http_access allow localnet\nhttp_access deny all/' /etc/squid/squid.conf
squid -k reconfigure
```

## Certificado nos clientes

Instale `http://IP_DO_GATEWAY/gwos-ca.crt` como Autoridade Certificadora
Confiável, senão todo HTTPS interceptado dá alerta de certificado.

## Verificação

```bash
bash verificar.sh
```

Confere o `squid -k parse`, as portas, a validade da CA, a existência de todos
os arquivos incluídos e se o resolver bate com o módulo de DNS instalado.

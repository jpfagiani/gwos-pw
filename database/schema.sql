-- ============================================================
-- GWOS — Gateway DNS Firewall
-- Schema principal — MariaDB 10.6+
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE DATABASE IF NOT EXISTS gwos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gwos;

-- ------------------------------------------------------------
-- Administradores
-- ------------------------------------------------------------
CREATE TABLE admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(100)        NOT NULL,
    email         VARCHAR(150)        NOT NULL UNIQUE,
    senha         VARCHAR(255)        NOT NULL,
    perfil        ENUM('superadmin','admin','operador') NOT NULL DEFAULT 'operador',
    ativo         TINYINT(1)          NOT NULL DEFAULT 1,
    ultimo_login  DATETIME            NULL,
    reset_token   VARCHAR(12)         NULL,
    reset_expira  DATETIME            NULL,
    tentativas    TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME            NULL,
    primeiro_login TINYINT(1)         NOT NULL DEFAULT 1,
    criado_em     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE admin_sessoes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED        NOT NULL,
    token       VARCHAR(64)         NOT NULL UNIQUE,
    ip          VARCHAR(45)         NOT NULL,
    user_agent  VARCHAR(255)        NULL,
    expira_em   DATETIME            NOT NULL,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Auditoria
-- ------------------------------------------------------------
CREATE TABLE auditoria (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED        NULL,
    acao        VARCHAR(100)        NOT NULL,
    tabela      VARCHAR(64)         NULL,
    registro_id INT UNSIGNED        NULL,
    dados_antes JSON                NULL,
    dados_depois JSON               NULL,
    ip          VARCHAR(45)         NOT NULL,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin   (admin_id),
    INDEX idx_criado  (criado_em),
    INDEX idx_acao    (acao)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Grupos de IPs
-- ------------------------------------------------------------
CREATE TABLE ip_grupos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100)        NOT NULL,
    descricao   VARCHAR(255)        NULL,
    tipo        ENUM('liberado','parcial','bloqueado','isento') NOT NULL,
    ativo       TINYINT(1)          NOT NULL DEFAULT 1,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE ips (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grupo_id    INT UNSIGNED        NOT NULL,
    endereco    VARCHAR(18)         NOT NULL,       -- IPv4 ou CIDR
    descricao   VARCHAR(255)        NULL,
    ativo       TINYINT(1)          NOT NULL DEFAULT 1,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grupo_id) REFERENCES ip_grupos(id) ON DELETE CASCADE,
    INDEX idx_grupo (grupo_id),
    INDEX idx_end   (endereco)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Listas de domínios (whitelist / blacklist / categorias)
-- ------------------------------------------------------------
CREATE TABLE categorias (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100)        NOT NULL,
    slug        VARCHAR(100)        NOT NULL UNIQUE,
    descricao   VARCHAR(255)        NULL,
    icone       VARCHAR(50)         NULL DEFAULT 'bi-shield'
) ENGINE=InnoDB;

INSERT INTO categorias (nome, slug, descricao, icone) VALUES
-- Whitelist
('Governo',        'governo',  'Sites .gov.br, .jus.br, .leg.br e orgaos publicos', 'bi-building-fill'),
('Bancos',         'bancos',   'Bancos e instituicoes financeiras brasileiras',       'bi-bank'),
-- Blacklist
('YouTube',        'youtube',  'YouTube e CDNs associadas',               'bi-youtube'),
('Streaming',      'streaming','Netflix, Prime, Disney+, Globoplay e similares', 'bi-play-circle'),
('Redes Sociais',  'social',   'Instagram, TikTok, Facebook, X, Reddit',  'bi-people'),
('Radio e Musica', 'radio',    'Spotify, Deezer, SoundCloud e radios online', 'bi-music-note-beamed'),
('Jogos Online',   'games',    'Steam, Epic, Battlenet e plataformas de games', 'bi-controller'),
('Apostas',        'apostas',  'Sites de aposta e jogos de azar',         'bi-dice-5'),
('Adulto',         'adulto',   'Conteudo adulto',                         'bi-exclamation-triangle');

CREATE TABLE dominios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT UNSIGNED       NULL,
    dominio     VARCHAR(253)        NOT NULL,
    tipo        ENUM('whitelist','blacklist') NOT NULL,
    origem      ENUM('manual','importado') NOT NULL DEFAULT 'manual',
    ativo       TINYINT(1)          NOT NULL DEFAULT 1,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
    INDEX idx_dominio   (dominio),
    INDEX idx_tipo      (tipo),
    INDEX idx_categoria (categoria_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- Seeds: whitelist — Governo e Bancos
-- ------------------------------------------------------------------
SET @cat_gov   = (SELECT id FROM categorias WHERE slug = 'governo');
SET @cat_banco = (SELECT id FROM categorias WHERE slug = 'bancos');
SET @cat_yt    = (SELECT id FROM categorias WHERE slug = 'youtube');
SET @cat_str   = (SELECT id FROM categorias WHERE slug = 'streaming');
SET @cat_soc   = (SELECT id FROM categorias WHERE slug = 'social');
SET @cat_radio = (SELECT id FROM categorias WHERE slug = 'radio');

INSERT INTO dominios (dominio, tipo, categoria_id, origem) VALUES
-- Governo (o dominio pai cobre todos os subdominios no Squid com prefixo ".")
('gov.br',             'whitelist', @cat_gov,   'importado'),
('jus.br',             'whitelist', @cat_gov,   'importado'),
('leg.br',             'whitelist', @cat_gov,   'importado'),
('mp.br',              'whitelist', @cat_gov,   'importado'),
('def.br',             'whitelist', @cat_gov,   'importado'),
-- Bancos
('bb.com.br',          'whitelist', @cat_banco, 'importado'),
('bradesco.com.br',    'whitelist', @cat_banco, 'importado'),
('itau.com.br',        'whitelist', @cat_banco, 'importado'),
('santander.com.br',   'whitelist', @cat_banco, 'importado'),
('nubank.com.br',      'whitelist', @cat_banco, 'importado'),
('inter.co',           'whitelist', @cat_banco, 'importado'),
('bancobrasil.com.br', 'whitelist', @cat_banco, 'importado'),
('safra.com.br',       'whitelist', @cat_banco, 'importado'),
('sicredi.com.br',     'whitelist', @cat_banco, 'importado'),
('sicoob.com.br',      'whitelist', @cat_banco, 'importado'),
('banrisul.com.br',    'whitelist', @cat_banco, 'importado'),
('brb.com.br',         'whitelist', @cat_banco, 'importado'),
('c6bank.com.br',      'whitelist', @cat_banco, 'importado'),
('picpay.com',         'whitelist', @cat_banco, 'importado'),
('mercadopago.com.br', 'whitelist', @cat_banco, 'importado'),
('pagbank.com.br',     'whitelist', @cat_banco, 'importado'),
('neon.com.br',        'whitelist', @cat_banco, 'importado'),
-- YouTube e CDNs
('youtube.com',          'blacklist', @cat_yt,    'importado'),
('youtu.be',             'blacklist', @cat_yt,    'importado'),
('ytimg.com',            'blacklist', @cat_yt,    'importado'),
('googlevideo.com',      'blacklist', @cat_yt,    'importado'),
('ggpht.com',            'blacklist', @cat_yt,    'importado'),
('youtube-nocookie.com', 'blacklist', @cat_yt,    'importado'),
('youtubekids.com',      'blacklist', @cat_yt,    'importado'),
-- Streaming
('netflix.com',          'blacklist', @cat_str,   'importado'),
('nflxvideo.net',        'blacklist', @cat_str,   'importado'),
('primevideo.com',       'blacklist', @cat_str,   'importado'),
('disneyplus.com',       'blacklist', @cat_str,   'importado'),
('hbomax.com',           'blacklist', @cat_str,   'importado'),
('max.com',              'blacklist', @cat_str,   'importado'),
('paramountplus.com',    'blacklist', @cat_str,   'importado'),
('crunchyroll.com',      'blacklist', @cat_str,   'importado'),
('vimeo.com',            'blacklist', @cat_str,   'importado'),
('dailymotion.com',      'blacklist', @cat_str,   'importado'),
('peacocktv.com',        'blacklist', @cat_str,   'importado'),
('twitch.tv',            'blacklist', @cat_str,   'importado'),
('globo.com',            'blacklist', @cat_str,   'importado'),
('telecine.com.br',      'blacklist', @cat_str,   'importado'),
('looke.com.br',         'blacklist', @cat_str,   'importado'),
('mubi.com',             'blacklist', @cat_str,   'importado'),
('discovery.com',        'blacklist', @cat_str,   'importado'),
('apple.com',            'blacklist', @cat_str,   'importado'),
-- Redes Sociais
('facebook.com',         'blacklist', @cat_soc,   'importado'),
('fbcdn.net',            'blacklist', @cat_soc,   'importado'),
('instagram.com',        'blacklist', @cat_soc,   'importado'),
('cdninstagram.com',     'blacklist', @cat_soc,   'importado'),
('twitter.com',          'blacklist', @cat_soc,   'importado'),
('x.com',                'blacklist', @cat_soc,   'importado'),
('t.co',                 'blacklist', @cat_soc,   'importado'),
('twimg.com',            'blacklist', @cat_soc,   'importado'),
('tiktok.com',           'blacklist', @cat_soc,   'importado'),
('tiktokcdn.com',        'blacklist', @cat_soc,   'importado'),
('tiktokv.com',          'blacklist', @cat_soc,   'importado'),
('pinterest.com',        'blacklist', @cat_soc,   'importado'),
('pinimg.com',           'blacklist', @cat_soc,   'importado'),
('snapchat.com',         'blacklist', @cat_soc,   'importado'),
('reddit.com',           'blacklist', @cat_soc,   'importado'),
('redd.it',              'blacklist', @cat_soc,   'importado'),
('tumblr.com',           'blacklist', @cat_soc,   'importado'),
('discord.com',          'blacklist', @cat_soc,   'importado'),
('discordapp.com',       'blacklist', @cat_soc,   'importado'),
('kick.com',             'blacklist', @cat_soc,   'importado'),
-- Radio e Musica
('spotify.com',          'blacklist', @cat_radio, 'importado'),
('scdn.co',              'blacklist', @cat_radio, 'importado'),
('soundcloud.com',       'blacklist', @cat_radio, 'importado'),
('deezer.com',           'blacklist', @cat_radio, 'importado'),
('tidal.com',            'blacklist', @cat_radio, 'importado'),
('iheartradio.com',      'blacklist', @cat_radio, 'importado'),
('pandora.com',          'blacklist', @cat_radio, 'importado'),
('bandcamp.com',         'blacklist', @cat_radio, 'importado'),
('napster.com',          'blacklist', @cat_radio, 'importado'),
('music.amazon.com',     'blacklist', @cat_radio, 'importado'),
-- Rádios online
('radiosaovivo.net',     'blacklist', @cat_radio, 'importado'),
('radios.com.br',        'blacklist', @cat_radio, 'importado'),
('vagalume.com.br',      'blacklist', @cat_radio, 'importado'),
('palcomp3.com.br',      'blacklist', @cat_radio, 'importado'),
('radiosonline.com.br',  'blacklist', @cat_radio, 'importado'),
('tunein.com',           'blacklist', @cat_radio, 'importado'),
('live365.com',          'blacklist', @cat_radio, 'importado'),
('shoutcast.com',        'blacklist', @cat_radio, 'importado'),
('icecast.org',          'blacklist', @cat_radio, 'importado');

-- ------------------------------------------------------------
-- Regras de horário de navegação
-- ------------------------------------------------------------
CREATE TABLE horarios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100)        NOT NULL,
    grupo_id    INT UNSIGNED        NULL,             -- NULL = aplica a todos
    dias_semana VARCHAR(7)          NOT NULL DEFAULT '1111100', -- Dom=0..Sab=6
    hora_inicio TIME                NOT NULL,
    hora_fim    TIME                NOT NULL,
    acao        ENUM('permitir','bloquear') NOT NULL DEFAULT 'bloquear',
    ativo       TINYINT(1)          NOT NULL DEFAULT 1,
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grupo_id) REFERENCES ip_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seeds: horários liberados
-- dias_semana: 7 bits posição 0=Dom, 1=Seg, 2=Ter, 3=Qua, 4=Qui, 5=Sex, 6=Sab
-- Dias úteis (Seg-Sex) = 0111110
INSERT INTO horarios (nome, grupo_id, dias_semana, hora_inicio, hora_fim, acao) VALUES
('Livre manhã cedo (seg-sex)',  NULL, '0111110', '07:00:00', '08:00:00', 'permitir'),
('Livre almoço (seg-sex)',      NULL, '0111110', '11:00:00', '13:00:00', 'permitir'),
('Livre fim de expediente (seg-sex)', NULL, '0111110', '17:00:00', '18:00:00', 'permitir'),
('Livre noite (seg-sex)',       NULL, '0111110', '19:00:00', '23:00:00', 'permitir'),
('Livre sábado',                NULL, '0000001', '00:00:00', '23:59:00', 'permitir'),
('Livre domingo',               NULL, '1000000', '00:00:00', '23:59:00', 'permitir');

-- ------------------------------------------------------------
-- Configurações de NAT e rede
-- ------------------------------------------------------------
CREATE TABLE configuracoes (
    chave       VARCHAR(100)        PRIMARY KEY,
    valor       TEXT                NULL,
    descricao   VARCHAR(255)        NULL,
    atualizado_em DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO configuracoes (chave, valor, descricao) VALUES
('iface_wan',         'eth0',           'Interface WAN'),
('iface_lan',         'eth1',           'Interface LAN'),
('rede_lan',          '192.168.1.0/24', 'Rede local'),
('ip_gateway',        '192.168.1.1',    'IP do gateway na LAN'),
('dns_primario',      '8.8.8.8',     'DNS primário externo'),
('dns_secundario',    '1.1.1.1',     'DNS secundário externo'),
('squid_porta',       '3128',        'Porta do Squid'),
('nat_ativo',         '1',           'NAT habilitado'),
('ntp_servidor',      'pool.ntp.br', 'Servidor NTP'),
('rpz_ativo',         '1',           'BIND9 RPZ ativo'),
('app_nome',          'GWOS',        'Nome do sistema'),
('app_versao',        '1.0.0',       'Versão do sistema'),
('app_timezone',      'America/Sao_Paulo', 'Fuso horário');

-- ------------------------------------------------------------
-- Histórico de alterações de regras (firewall/DNS)
-- ------------------------------------------------------------
CREATE TABLE regras_historico (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED        NULL,
    modulo      ENUM('nftables','bind9','squid','horario') NOT NULL,
    descricao   VARCHAR(255)        NOT NULL,
    conteudo    TEXT                NULL,
    aplicado_em DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_modulo    (modulo),
    INDEX idx_aplicado  (aplicado_em)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Relatórios — cache de dados agregados
-- ------------------------------------------------------------
CREATE TABLE relatorio_diario (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data        DATE                NOT NULL,
    ip_cliente  VARCHAR(45)         NOT NULL,
    dominio     VARCHAR(253)        NOT NULL,
    acessos     INT UNSIGNED        NOT NULL DEFAULT 1,
    bytes       BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    bloqueado   TINYINT(1)          NOT NULL DEFAULT 0,
    UNIQUE KEY uk_registro  (data, ip_cliente, dominio),
    INDEX idx_data          (data),
    INDEX idx_ip            (ip_cliente),
    INDEX idx_dominio       (dominio)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Backups automáticos
-- ------------------------------------------------------------
CREATE TABLE backups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    arquivo     VARCHAR(255)        NOT NULL,
    tamanho     INT UNSIGNED        NOT NULL DEFAULT 0,
    tipo        ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    status      ENUM('ok','erro')   NOT NULL DEFAULT 'ok',
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- NAT 1:1 — mapeamento IP externo ↔ IP interno
-- ------------------------------------------------------------
CREATE TABLE nat_um_para_um (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    descricao   VARCHAR(100)        NOT NULL,
    ip_externo  VARCHAR(18)         NOT NULL,   -- IP público (WAN)
    ip_interno  VARCHAR(18)         NOT NULL,   -- IP privado (LAN)
    ativo       TINYINT(1)          NOT NULL DEFAULT 0,  -- desativado por padrão
    criado_em   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ip_externo (ip_externo),
    UNIQUE KEY uk_ip_interno (ip_interno)
) ENGINE=InnoDB;

-- Administrador inicial é provisionado pelo instalador com senha aleatória
-- de uso único, exibida apenas no console. O schema não contém credencial padrão.


-- ============================================================
-- Acesso à internet com login (migração 002 — incluída para instalações novas)
-- ============================================================

-- ------------------------------------------------------------
-- Usuários da internet (diferentes dos administradores do painel)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios_internet (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(100)     NOT NULL,
    email          VARCHAR(150)     NOT NULL UNIQUE,
    senha          VARCHAR(255)     NOT NULL,
    confirmado_em  DATETIME         NULL,            -- NULL = e-mail ainda não confirmado
    ativo          TINYINT(1)       NOT NULL DEFAULT 1,
    tentativas     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate  DATETIME         NULL,
    ultimo_login   DATETIME         NULL,
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tokens de confirmação de e-mail e de redefinição de senha.
-- Só o hash (SHA-256) é guardado: quem lê o banco não consegue usar o token.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tokens_internet (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED                    NOT NULL,
    tipo        ENUM('confirmar','redefinir')   NOT NULL,
    token_hash  CHAR(64)                        NOT NULL UNIQUE,
    expira_em   DATETIME                        NOT NULL,
    usado_em    DATETIME                        NULL,
    ip          VARCHAR(45)                     NOT NULL,
    criado_em   DATETIME                        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios_internet(id) ON DELETE CASCADE,
    INDEX idx_ip_criado (ip, criado_em)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Sessões: um IP de máquina liberado por um usuário até expirar.
-- O PHP espelha esta tabela em /var/lib/gwos/sessions/sessoes.txt, que o helper do
-- Squid lê (o Squid não precisa de acesso ao banco).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessoes_internet (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED     NOT NULL,
    ip          VARCHAR(45)      NOT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em   DATETIME         NOT NULL,
    grupo_snapshot VARCHAR(255) NULL,
    UNIQUE KEY uk_ip (ip),
    FOREIGN KEY (usuario_id) REFERENCES usuarios_internet(id) ON DELETE CASCADE,
    INDEX idx_expira (expira_em)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Registro de acessos: um registro por requisição do access.log do Squid
-- (data/hora, IP da máquina, e-mail do usuário, site acessado).
-- ------------------------------------------------------------
-- Histórico persistente de sessões do portal, inclusive após remoção de usuário.
CREATE TABLE IF NOT EXISTS sessoes_internet_historico (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT UNSIGNED NULL, nome_usuario VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
 ip VARCHAR(45) NOT NULL, mac VARCHAR(17) NULL, hostname VARCHAR(253) NULL, grupo_snapshot VARCHAR(255) NULL,
 iniciou_em DATETIME NOT NULL, expira_previsto_em DATETIME NOT NULL, encerrou_em DATETIME NOT NULL,
 duracao_segundos INT UNSIGNED NOT NULL DEFAULT 0,
 motivo ENUM('logout_usuario','expirada','revogada_admin','conta_desativada','senha_alterada','substituida','usuario_excluido') NOT NULL,
 INDEX idx_sess_hist_data (encerrou_em), INDEX idx_sess_hist_ip (ip,encerrou_em), INDEX idx_sess_hist_email (email,encerrou_em)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acessos_internet (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ocorrido_em  DATETIME          NOT NULL,
    ip           VARCHAR(45)       NOT NULL,
    email        VARCHAR(150)      NULL,              -- NULL = IP isento de login
    metodo       VARCHAR(16)       NOT NULL,
    dominio      VARCHAR(253)      NOT NULL,
    url          VARCHAR(1000)     NOT NULL,
    status       VARCHAR(40)       NOT NULL,
    bytes        BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    request_bytes BIGINT UNSIGNED  NULL DEFAULT NULL,
    log_key      CHAR(64)          NULL,
    UNIQUE KEY uq_acessos_log_key (log_key),
    INDEX idx_quando (ocorrido_em),
    INDEX idx_ip     (ip, ocorrido_em),
    INDEX idx_email  (email, ocorrido_em),
    INDEX idx_dominio(dominio)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Novo tipo de grupo de IPs: "isento" = navega SEM senha, mas passa pelo
-- proxy e portanto aparece no registro de acessos (ao contrário do
-- "liberado", que desvia do proxy e não deixa log).
-- ------------------------------------------------------------
ALTER TABLE ip_grupos
    MODIFY COLUMN tipo ENUM('liberado','parcial','bloqueado','isento') NOT NULL;

-- ------------------------------------------------------------
-- Configurações do acesso com login e do envio de e-mail
-- ------------------------------------------------------------
INSERT IGNORE INTO configuracoes (chave, valor, descricao) VALUES
('acesso_dominio_email', 'sap.sp.gov.br', 'Único domínio de e-mail aceito no cadastro'),
('acesso_sessao_horas',  '8',             'Duração da sessão após o login (horas)'),
('acesso_max_sessoes_usuario', '3',       'Máximo de dispositivos com sessão de internet simultânea por usuário'),
('alerta_dns_dominio', 'debian.org',     'Domínio usado pelo teste de resolução DNS no alerta periódico'),
('smtp_host',            '10.14.5.16',    'Servidor SMTP para os e-mails do portal'),
('smtp_porta',           '25',            'Porta SMTP'),
('smtp_seguranca',       'nenhuma',       'nenhuma | starttls | ssl'),
('smtp_usuario',         '',              'Usuário SMTP (vazio = sem autenticação)'),
('smtp_senha',           '',              'Senha SMTP'),
('smtp_remetente',       'internet@sap.sp.gov.br', 'Endereço remetente dos e-mails do portal'),
('acesso_portal_url',    '',              'URL base do portal (vazio = detecta pelo IP do gateway)');

-- ------------------------------------------------------------
-- Webmail institucional sempre liberado: o usuário ainda sem login precisa
-- abrir o e-mail para clicar no link de confirmação.
-- ------------------------------------------------------------
INSERT INTO dominios (dominio, tipo, categoria_id, origem)
SELECT 'sap.sp.gov.br', 'whitelist', (SELECT id FROM categorias WHERE slug = 'governo'), 'importado'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dominios WHERE dominio = 'sap.sp.gov.br' AND tipo = 'whitelist');

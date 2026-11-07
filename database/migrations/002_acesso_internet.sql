-- GWOS — Migração 002: acesso à internet com login (portal cativo)
-- Execute no servidor:  mysql -u gwos -p gwos < database/migrations/002_acesso_internet.sql
-- É idempotente: pode rodar mais de uma vez.

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
    UNIQUE KEY uk_ip (ip),
    FOREIGN KEY (usuario_id) REFERENCES usuarios_internet(id) ON DELETE CASCADE,
    INDEX idx_expira (expira_em)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Registro de acessos: um registro por requisição do access.log do Squid
-- (data/hora, IP da máquina, e-mail do usuário, site acessado).
-- ------------------------------------------------------------
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

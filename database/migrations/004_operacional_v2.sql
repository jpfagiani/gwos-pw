-- GWOS v2: dados operacionais adicionais. Reexecutável em MariaDB 10.6+.
ALTER TABLE acessos_internet
  ADD COLUMN IF NOT EXISTS protocolo VARCHAR(16) NULL,
  ADD COLUMN IF NOT EXISTS porta SMALLINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS destino_ip VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS interface_rede VARCHAR(32) NULL,
  ADD INDEX IF NOT EXISTS idx_acessos_protocolo (protocolo, ocorrido_em),
  ADD INDEX IF NOT EXISTS idx_acessos_decisao (status, ocorrido_em);
ALTER TABLE sessoes_internet
  ADD COLUMN IF NOT EXISTS mac VARCHAR(17) NULL,
  ADD COLUMN IF NOT EXISTS hostname VARCHAR(253) NULL;
ALTER TABLE ips
  MODIFY COLUMN endereco VARCHAR(45) NOT NULL,
  ADD COLUMN IF NOT EXISTS expira_em DATETIME NULL,
  ADD INDEX IF NOT EXISTS idx_ips_expira (expira_em);

CREATE TABLE IF NOT EXISTS equipamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL UNIQUE,
  mac VARCHAR(17) NULL,
  hostname VARCHAR(253) NULL,
  observacao VARCHAR(500) NULL,
  origem ENUM('manual','dhcp','vizinho','squid','sessao','desconhecida') NOT NULL DEFAULT 'manual',
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_equipamentos_mac (mac)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alertas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(120) NOT NULL,
  estado ENUM('aberto','reconhecido','resolvido') NOT NULL DEFAULT 'aberto',
  chave_ativa VARCHAR(120) GENERATED ALWAYS AS (IF(estado='resolvido', NULL, chave)) STORED,
  severidade ENUM('info','aviso','critico') NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  mensagem VARCHAR(1000) NOT NULL,
  origem VARCHAR(80) NOT NULL,
  ocorrencias INT UNSIGNED NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reconhecido_por INT UNSIGNED NULL,
  reconhecido_em DATETIME NULL,
  resolvido_em DATETIME NULL,
  UNIQUE KEY uk_alerta_ativo (chave_ativa),
  INDEX idx_alerta_estado (estado, atualizado_em)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS configuracoes_historico (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  operacao_id CHAR(32) NOT NULL,
  admin_id INT UNSIGNED NULL,
  chave VARCHAR(100) NOT NULL,
  valor_anterior TEXT NOT NULL,
  valor_novo TEXT NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_config_hist_op (operacao_id),
  INDEX idx_config_hist_criado (criado_em),
  INDEX idx_config_hist_chave (chave)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metricas_rede (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  interface VARCHAR(32) NOT NULL,
  rx_bytes BIGINT UNSIGNED NOT NULL,
  tx_bytes BIGINT UNSIGNED NOT NULL,
  coletado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_metricas_interface_data (interface,coletado_em)
) ENGINE=InnoDB;

INSERT IGNORE INTO configuracoes (chave, valor, descricao) VALUES
 ('alerta_bloqueios_5min','50','Limite de bloqueios Squid em cinco minutos para alerta'),
 ('alerta_consumo_5min_bytes','104857600','Volume por IP em cinco minutos para alerta de consumo anormal'),
 ('alerta_disco_pct','85','Limite percentual de uso do disco'),
 ('alerta_memoria_pct','92','Limite percentual de uso da memória'),
 ('alerta_backup_horas','30','Horas sem backup antes de alertar');

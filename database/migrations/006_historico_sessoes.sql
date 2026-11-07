-- Histórico persistente de sessões do portal, inclusive após remoção de usuário.
ALTER TABLE sessoes_internet ADD COLUMN IF NOT EXISTS grupo_snapshot VARCHAR(255) NULL;
CREATE TABLE IF NOT EXISTS sessoes_internet_historico (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT UNSIGNED NULL, nome_usuario VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
 ip VARCHAR(45) NOT NULL, mac VARCHAR(17) NULL, hostname VARCHAR(253) NULL, grupo_snapshot VARCHAR(255) NULL,
 iniciou_em DATETIME NOT NULL, expira_previsto_em DATETIME NOT NULL, encerrou_em DATETIME NOT NULL,
 duracao_segundos INT UNSIGNED NOT NULL DEFAULT 0,
 motivo ENUM('logout_usuario','expirada','revogada_admin','conta_desativada','senha_alterada','substituida','usuario_excluido') NOT NULL,
 INDEX idx_sess_hist_data (encerrou_em), INDEX idx_sess_hist_ip (ip,encerrou_em), INDEX idx_sess_hist_email (email,encerrou_em)
) ENGINE=InnoDB;

-- Compatibilidade com a primeira versão publicada da migration 006.
ALTER TABLE sessoes_internet_historico ADD COLUMN IF NOT EXISTS grupo_snapshot VARCHAR(255) NULL;

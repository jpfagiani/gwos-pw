-- Liberação temporária reversível (Relatório Mestre v2.0, seção 6).
-- A liberação temporária suspende as regras exatas anteriores do IP e guarda
-- os ids suspensos em `restaurar_ips`; ao vencer (ou ser revogada) elas voltam.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ips' AND COLUMN_NAME = 'restaurar_ips');
SET @sql := IF(@c = 0, 'ALTER TABLE ips ADD COLUMN restaurar_ips VARCHAR(255) NULL AFTER expira_em', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ips' AND COLUMN_NAME = 'origem_acao');
SET @sql := IF(@c = 0, 'ALTER TABLE ips ADD COLUMN origem_acao VARCHAR(32) NULL AFTER restaurar_ips', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

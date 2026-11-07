-- GWOS — Migração 001: Melhorias de autenticação
-- Execute no servidor: mysql -u gwos -p gwos < migrations/001_auth_melhorias.sql

ALTER TABLE admins
    ADD COLUMN IF NOT EXISTS reset_token   VARCHAR(12)  NULL     AFTER ultimo_login,
    ADD COLUMN IF NOT EXISTS reset_expira  DATETIME     NULL     AFTER reset_token,
    ADD COLUMN IF NOT EXISTS tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_expira,
    ADD COLUMN IF NOT EXISTS bloqueado_ate DATETIME     NULL     AFTER tentativas,
    ADD COLUMN IF NOT EXISTS primeiro_login TINYINT(1)  NOT NULL DEFAULT 0 AFTER bloqueado_ate;

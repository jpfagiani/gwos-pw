-- Chave estável por linha do access.log para evitar duplicação após falha/reprocessamento.
ALTER TABLE acessos_internet
  ADD COLUMN IF NOT EXISTS log_key CHAR(64) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_acessos_log_key (log_key);

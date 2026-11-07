-- Registra bytes de requisição do Squid (%>st), separados dos bytes de
-- resposta (%<st). Valores legados ficam NULL para não estimar upload passado.
ALTER TABLE acessos_internet
  ADD COLUMN IF NOT EXISTS request_bytes BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE equipamentos
  ADD COLUMN IF NOT EXISTS lease_expira_em DATETIME NULL,
  ADD COLUMN IF NOT EXISTS interface_rede VARCHAR(32) NULL;

INSERT IGNORE INTO configuracoes (chave,valor,descricao) VALUES
 ('acesso_max_sessoes_usuario','3','Máximo de dispositivos com sessão de internet simultânea por usuário'),
 ('alerta_dns_dominio','debian.org','Domínio usado pelo teste de resolução DNS no alerta periódico');

CREATE TABLE IF NOT EXISTS fila_expiracao_politicas (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    pendente TINYINT(1) NOT NULL DEFAULT 0,
    tarefa_firewall CHAR(32) NULL,
    tarefa_squid CHAR(32) NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO fila_expiracao_politicas (id) VALUES (1);
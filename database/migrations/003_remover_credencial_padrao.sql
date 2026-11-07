-- Remove somente a conta inicial com o hash de fábrica conhecido.
-- Contas com senha já personalizada permanecem intactas.
-- O módulo 60-painel-web cria a conta inicial com senha aleatória após esta migração.
DELETE FROM admins
WHERE email = 'admin@gwos.local'
  AND senha = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

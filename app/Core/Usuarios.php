<?php

namespace App\Core;

/**
 * GWOS — Origem dos usuários do painel.
 *
 * Com o módulo 10-banco-mariadb instalado, é a tabela 'admins'. Sem ele, o
 * painel roda em modo leve e os usuários vêm de um arquivo JSON — é o que
 * permite ter painel num servidor que só tem DNS, sem subir um MariaDB
 * inteiro para guardar uma senha.
 *
 * A lógica de login (bloqueio por tentativas, primeiro acesso, reset) mora no
 * Auth e é a mesma nos dois casos; aqui só muda de onde os campos vêm.
 */
class Usuarios
{
    // Fica num diretório próprio, e não solto em /etc/gwos: a gravação é
    // atômica (tmp + rename) e criar o .tmp exige permissão de ESCRITA NO
    // DIRETÓRIO. Com o arquivo direto em /etc/gwos (755 root), o www-data
    // lia mas não conseguia gravar — e a troca de senha falhava em silêncio.
    private const DIRETORIO = '/etc/gwos/painel';
    private const ARQUIVO   = '/etc/gwos/painel/usuarios.json';
    private const ARQUIVO_ANTIGO = '/etc/gwos/painel-usuarios.json';

    public static function usandoBanco(): bool
    {
        return Modulos::temBanco();
    }

    public static function buscarPorEmail(string $email): ?array
    {
        $email = trim($email);

        if (self::usandoBanco()) {
            $linha = Database::fetch('SELECT * FROM admins WHERE email = ? AND ativo = 1', [$email]);
            return $linha ?: null;
        }

        foreach (self::lerArquivo() as $usuario) {
            if (strcasecmp($usuario['email'], $email) === 0 && !empty($usuario['ativo'])) {
                return $usuario;
            }
        }
        return null;
    }

    public static function buscarPorId(int $id): ?array
    {
        if (self::usandoBanco()) {
            $linha = Database::fetch('SELECT * FROM admins WHERE id = ?', [$id]);
            return $linha ?: null;
        }

        foreach (self::lerArquivo() as $usuario) {
            if ((int) $usuario['id'] === $id) {
                return $usuario;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $campos
     * @return bool false quando a gravação falhou — quem troca senha PRECISA
     *              saber disso, senão avisa o usuário de um sucesso que não houve.
     */
    public static function atualizar(int $id, array $campos): bool
    {
        if ($campos === []) {
            return true;
        }

        if (self::usandoBanco()) {
            $sets   = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $id;
            Database::execute("UPDATE admins SET {$sets} WHERE id = ?", $params);
            return true;
        }

        $usuarios = self::lerArquivo();
        foreach ($usuarios as &$usuario) {
            if ((int) $usuario['id'] === $id) {
                foreach ($campos as $campo => $valor) {
                    $usuario[$campo] = $valor;
                }
            }
        }
        unset($usuario);
        return self::gravarArquivo($usuarios);
    }

    /** Marca o último login com a hora atual (NOW() no banco). */
    public static function registrarLogin(int $id): bool
    {
        return self::atualizar($id, [
            'tentativas'    => 0,
            'bloqueado_ate' => null,
            'ultimo_login'  => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listar(): array
    {
        if (self::usandoBanco()) {
            return Database::fetchAll('SELECT id, nome, email, perfil, ativo, ultimo_login FROM admins');
        }
        return self::lerArquivo();
    }

    // -----------------------------------------------------------------
    // Arquivo
    // -----------------------------------------------------------------

    private static function lerArquivo(): array
    {
        $caminho = is_readable(self::ARQUIVO) ? self::ARQUIVO
                 : (is_readable(self::ARQUIVO_ANTIGO) ? self::ARQUIVO_ANTIGO : null);
        if ($caminho === null) {
            return [];
        }
        $dados = json_decode((string) @file_get_contents($caminho), true);
        return is_array($dados) ? $dados : [];
    }

    private static function gravarArquivo(array $usuarios): bool
    {
        $json = json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        // Escrita atômica: o arquivo guarda hashes de senha e um corte no meio
        // da gravação (queda de energia, disco cheio) trancaria o painel.
        $temp = self::ARQUIVO . '.tmp';
        if (@file_put_contents($temp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        @chmod($temp, 0660);
        return @rename($temp, self::ARQUIVO);
    }
}

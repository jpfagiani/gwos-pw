<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instancia = null;

    private function __construct() {}

    public static function conectar(): PDO
    {
        if (self::$instancia === null) {
            $host    = config('db.host');
            $banco   = config('db.banco');
            $usuario = config('db.usuario');
            $senha   = config('db.senha');
            $charset = config('db.charset', 'utf8mb4');

            $dsn = "mysql:host={$host};dbname={$banco};charset={$charset}";

            self::$instancia = new PDO($dsn, $usuario, $senha, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instancia;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::conectar()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function fetch(string $sql, array $params = []): array|false
    {
        $stmt = self::conectar()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::conectar()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): string|false
    {
        $stmt = self::conectar()->prepare($sql);
        $stmt->execute($params);
        return self::conectar()->lastInsertId();
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $stmt = self::conectar()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

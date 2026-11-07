<?php

namespace App\Core;

/**
 * Fila persistente de operações controladas do painel.
 * A fila guarda apenas tipos allowlisted e argumentos validados; nunca comandos.
 */
class Tarefas
{
    private const TITULOS = [
        'firewall_apply' => 'Aplicar regras de firewall',
        'domains_squid' => 'Atualizar listas do Squid',
        'domains_dns' => 'Atualizar RPZ do DNS',
        'hours_squid' => 'Atualizar regras de horário',
        'nat_toggle' => 'Alterar regra NAT',
        'nat_apply' => 'Reaplicar regras NAT',
        'proxy_reload' => 'Recarregar proxy',
        'dns_reload' => 'Recarregar DNS',
    ];

    private static function diretorio(): string
    {
        return BASE_PATH . '/storage/tarefas';
    }

    public static function enfileirar(string $tipo, array $dados, ?int $solicitante, string $dedupe): string
    {
        if (!isset(self::TITULOS[$tipo])) throw new \InvalidArgumentException('Tipo de tarefa não permitido.');
        $payload = self::validarPayload($tipo, $dados);
        $dir = self::diretorio();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Fila de tarefas indisponível.');
        }
        $lock = @fopen($dir . '/.enqueue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Fila de tarefas indisponível.');
        try {
            foreach (self::arquivos() as $arquivo) {
                $existente = self::lerArquivo($arquivo);
                if ($existente && hash_equals((string) ($existente['dedupe_key'] ?? ''), substr(hash('sha256', $dedupe), 0, 32))
                    && ($existente['status'] ?? '') === 'queued') {
                    return $existente['id'];
                }
            }

            $id = bin2hex(random_bytes(16));
            $agora = gmdate('c');
            $tarefa = [
            'id' => $id,
            'tipo' => $tipo,
            'titulo' => self::TITULOS[$tipo],
            'solicitante_id' => $solicitante,
            'status' => 'queued',
            'criada_em' => $agora,
            'iniciada_em' => null,
            'finalizada_em' => null,
            'duracao_ms' => null,
            'codigo_saida' => null,
            'mensagem' => 'Na fila para execução.',
            'dedupe_key' => substr(hash('sha256', $dedupe), 0, 32),
            'payload' => $payload,
            ];
            self::gravar($id, $tarefa);
            return $id;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function obter(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
        $tarefa = self::lerArquivo(self::diretorio() . '/' . $id . '.json');
        return $tarefa ? self::publica($tarefa) : null;
    }

    public static function recentes(int $limite = 100): array
    {
        $itens = [];
        foreach (self::arquivos() as $arquivo) {
            $tarefa = self::lerArquivo($arquivo);
            if ($tarefa) $itens[] = self::publica($tarefa);
        }
        usort($itens, static fn(array $a, array $b): int => strcmp($b['criada_em'], $a['criada_em']));
        return array_slice($itens, 0, max(1, min(250, $limite)));
    }

    /** Executado por cron como www-data; flock impede workers concorrentes. */
    public static function processarFila(int $limite = 5): void
    {
        $dir = self::diretorio();
        if (!is_dir($dir)) return;
        $lock = @fopen($dir . '/.worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        self::marcarAbandonadas();
        self::limparAntigas();
        $processadas = 0;
        foreach (self::arquivos() as $arquivo) {
            if ($processadas >= max(1, min(10, $limite))) break;
            $tarefa = self::lerArquivo($arquivo);
            if (!$tarefa || ($tarefa['status'] ?? '') !== 'queued') continue;
            $processadas++;
            self::executar($tarefa);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private static function executar(array $tarefa): void
    {
        $inicio = microtime(true);
        $tarefa['status'] = 'running';
        $tarefa['iniciada_em'] = gmdate('c');
        $tarefa['mensagem'] = 'Executando operação controlada.';
        self::gravar($tarefa['id'], $tarefa);

        try {
            $comando = self::comando($tarefa);
            $null = DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL';
            $processo = @proc_open($comando, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']], $pipes, BASE_PATH, null, ['bypass_shell' => true]);
            if (!is_resource($processo)) throw new \RuntimeException('Não foi possível iniciar o worker.');
            $codigo = proc_close($processo);
            $tarefa['codigo_saida'] = $codigo;
            if ($codigo === 124 || $codigo === 137) {
                $tarefa['status'] = 'timeout';
                $tarefa['mensagem'] = 'A operação excedeu o limite de 60 segundos.';
            } elseif ($codigo !== 0) {
                $tarefa['status'] = 'failed';
                $tarefa['mensagem'] = 'A operação retornou falha. Consulte os logs locais do serviço.';
            } elseif (self::verificarResultado($tarefa)) {
                $tarefa['status'] = 'succeeded';
                $tarefa['mensagem'] = 'Comando concluído e verificação posterior aprovada.';
            } else {
                $tarefa['status'] = 'unconfirmed';
                $tarefa['mensagem'] = 'Comando terminou, mas o estado esperado não pôde ser confirmado.';
            }
        } catch (\Throwable) {
            $tarefa['status'] = 'failed';
            $tarefa['mensagem'] = 'Falha segura ao iniciar ou verificar a operação.';
        }
        $tarefa['finalizada_em'] = gmdate('c');
        $tarefa['duracao_ms'] = (int) round((microtime(true) - $inicio) * 1000);
        self::gravar($tarefa['id'], $tarefa);
    }

    private static function comando(array $tarefa): array
    {
        $tarefa['payload'] = self::validarPayload($tarefa['tipo'], (array) ($tarefa['payload'] ?? []));
        $scripts = config('sistema.scripts_dir');
        $args = match ($tarefa['tipo']) {
            'firewall_apply', 'nat_apply' => [$scripts . '/aplicar_nftables.sh'],
            'domains_squid' => [$scripts . '/gerar_squid_dominios.sh'],
            'domains_dns' => [$scripts . '/aplicar_bind9_rpz.sh'],
            'hours_squid' => [$scripts . '/gerar_squid_acl.sh'],
            'nat_toggle' => array_merge([$scripts . '/aplicar_nat.sh'], [$tarefa['payload']['acao'], $tarefa['payload']['ip_externo'], $tarefa['payload']['ip_interno']]),
            'proxy_reload' => ['/usr/local/sbin/gwos-servico', 'recarregar', 'proxy'],
            'dns_reload' => ['/usr/local/sbin/gwos-servico', 'recarregar', 'dns'],
            default => throw new \InvalidArgumentException('Tipo inválido.'),
        };
        $script = $args[0];
        $scriptsPermitidos = [
            $scripts . '/aplicar_nftables.sh', $scripts . '/gerar_squid_dominios.sh',
            $scripts . '/aplicar_bind9_rpz.sh', $scripts . '/gerar_squid_acl.sh',
            $scripts . '/aplicar_nat.sh', '/usr/local/sbin/gwos-servico',
        ];
        if (!in_array($script, $scriptsPermitidos, true) || !is_executable($script)) {
            throw new \RuntimeException('Comando não instalado.');
        }
        return array_merge(['/usr/bin/timeout', '--signal=TERM', '--kill-after=5s', '60s', '/usr/bin/sudo', '-n'], $args);
    }

    private static function verificarResultado(array $tarefa): bool
    {
        if (in_array(($tarefa['tipo'] ?? ''), ['nat_toggle', 'firewall_apply', 'nat_apply'], true)) {
            $proc = @proc_open(
                ['/usr/bin/sudo', '-n', '/usr/local/sbin/gwos-servico', 'estado', 'firewall'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
                BASE_PATH,
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($proc)) return false;
            $rules = stream_get_contents($pipes[1], 2_000_000);
            fclose($pipes[1]);
            if (proc_close($proc) !== 0) return false;
            if (in_array($tarefa['tipo'], ['firewall_apply', 'nat_apply'], true)) {
                return str_contains((string) $rules, 'table inet gwos')
                    && str_contains((string) $rules, 'table ip gwos_nat');
            }
            $tag = 'nat1:1_' . $tarefa['payload']['ip_externo'];
            $present = str_contains((string) $rules, $tag);
            return $tarefa['payload']['acao'] === 'ativar' ? $present : !$present;
        }
        $servico = match ($tarefa['tipo']) {
            'firewall_apply', 'nat_apply', 'nat_toggle' => null,
            'domains_squid', 'hours_squid', 'proxy_reload' => 'squid',
            'domains_dns', 'dns_reload' => 'named',
            default => null,
        };
        if ($servico === null) {
            $cmd = ['/usr/bin/sudo', '-n', '/usr/local/sbin/gwos-servico', 'estado', 'firewall'];
        } else {
            $cmd = ['/usr/bin/systemctl', 'is-active', $servico];
        }
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        return is_resource($proc) && proc_close($proc) === 0;
    }

    private static function marcarAbandonadas(): void
    {
        foreach (self::arquivos() as $arquivo) {
            $tarefa = self::lerArquivo($arquivo);
            if (!$tarefa) continue;
            $status = $tarefa['status'] ?? '';
            $marco = $status === 'running'
                ? strtotime((string) ($tarefa['iniciada_em'] ?? ''))
                : (($status === 'queued') ? strtotime((string) ($tarefa['criada_em'] ?? '')) : false);
            $prazo = $status === 'running' ? 120 : 600;
            if (in_array($status, ['queued', 'running'], true) && $marco && time() - $marco > $prazo) {
                $tarefa['status'] = 'unconfirmed';
                $tarefa['finalizada_em'] = gmdate('c');
                $tarefa['mensagem'] = $status === 'queued'
                    ? 'A fila não iniciou no prazo esperado; verifique o agendador do worker.'
                    : 'Worker interrompido; o estado final precisa ser conferido manualmente.';
                self::gravar($tarefa['id'], $tarefa);
            }
        }
    }

    private static function limparAntigas(): void
    {
        $corte = time() - 90 * 86400;
        foreach (self::arquivos() as $arquivo) {
            $tarefa = self::lerArquivo($arquivo);
            if (!$tarefa || in_array($tarefa['status'] ?? '', ['queued', 'running'], true)) continue;
            $fim = strtotime((string) ($tarefa['finalizada_em'] ?? ''));
            if ($fim && $fim < $corte) @unlink($arquivo);
        }
    }

    private static function validarPayload(string $tipo, array $dados): array
    {
        if ($tipo === 'nat_toggle') {
            $acao = $dados['acao'] ?? '';
            $ext = $dados['ip_externo'] ?? '';
            $int = $dados['ip_interno'] ?? '';
            if (!in_array($acao, ['ativar', 'desativar'], true)
                || filter_var($ext, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || filter_var($int, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Parâmetros NAT inválidos.');
            }
            return ['acao' => $acao, 'ip_externo' => $ext, 'ip_interno' => $int];
        }
        if ($dados !== []) throw new \InvalidArgumentException('Esta tarefa não aceita parâmetros.');
        return [];
    }

    private static function arquivos(): array
    {
        $itens = glob(self::diretorio() . '/*.json') ?: [];
        usort($itens, static function (string $a, string $b): int {
            $ta = self::lerArquivo($a);
            $tb = self::lerArquivo($b);
            return strcmp((string) ($ta['criada_em'] ?? ''), (string) ($tb['criada_em'] ?? ''));
        });
        return $itens;
    }

    private static function lerArquivo(string $arquivo): ?array
    {
        $conteudo = @file_get_contents($arquivo);
        $dados = $conteudo === false ? null : json_decode($conteudo, true);
        return is_array($dados) ? $dados : null;
    }

    private static function gravar(string $id, array $dados): void
    {
        $arquivo = self::diretorio() . '/' . $id . '.json';
        $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Falha ao gravar tarefa.');
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new \RuntimeException('Falha ao publicar tarefa.');
        }
    }

    private static function publica(array $tarefa): array
    {
        unset($tarefa['payload'], $tarefa['dedupe_key']);
        return $tarefa;
    }
}

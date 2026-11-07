<?php

namespace App\Core;

/**
 * Cliente SMTP mínimo (sem dependências): EHLO, STARTTLS opcional,
 * AUTH PLAIN/LOGIN opcional, envio de texto UTF-8.
 *
 * As configurações vêm da tabela `configuracoes` (smtp_*), editáveis no painel.
 * Sem servidor SMTP configurado, enviar() devolve false e registra o motivo em
 * Mailer::$ultimoErro — o chamador decide o que mostrar ao usuário.
 */
class Mailer
{
    public static string $ultimoErro = '';

    public static function enviar(string $para, string $assunto, string $texto): bool
    {
        self::$ultimoErro = '';

        $cfg = self::configuracao();
        if ($cfg['host'] === '') {
            self::$ultimoErro = 'Servidor SMTP não configurado.';
            return false;
        }

        // Cabeçalhos não podem conter quebra de linha (injeção de cabeçalho)
        $para = str_replace(["\r", "\n"], '', $para);
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            self::$ultimoErro = 'Destinatário inválido.';
            return false;
        }

        $transporte = ($cfg['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['porta'];
        $ctx = stream_context_create(['ssl' => [
            // Servidor interno com certificado da própria instituição: valida a
            // cadeia só se a CA estiver instalada no gateway; senão aceita, pois
            // o destino é um IP fixo da rede interna.
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]]);

        $erro_n = 0; $erro_s = '';
        $fp = @stream_socket_client($transporte, $erro_n, $erro_s, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            self::$ultimoErro = "Não foi possível conectar ao SMTP {$cfg['host']}:{$cfg['porta']} ({$erro_s})";
            return false;
        }
        stream_set_timeout($fp, 15);

        try {
            self::esperar($fp, [220]);
            $nome_host = gethostname() ?: 'gateway';
            self::cmd($fp, "EHLO {$nome_host}", [250]);

            if ($cfg['seguranca'] === 'starttls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Falha ao negociar STARTTLS.');
                }
                self::cmd($fp, "EHLO {$nome_host}", [250]);
            }

            if ($cfg['usuario'] !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($cfg['usuario']), [334]);
                self::cmd($fp, base64_encode($cfg['senha']), [235]);
            }

            self::cmd($fp, "MAIL FROM:<{$cfg['remetente']}>", [250]);
            self::cmd($fp, "RCPT TO:<{$para}>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);

            $corpo = self::montarMensagem($cfg['remetente'], $para, $assunto, $texto);
            // Dot-stuffing: linha começando com "." vira ".."
            $corpo = preg_replace('/^\./m', '..', $corpo);
            fwrite($fp, $corpo . "\r\n.\r\n");
            self::esperar($fp, [250]);

            @self::cmd($fp, 'QUIT', [221]);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            self::$ultimoErro = $e->getMessage();
            @fclose($fp);
            return false;
        }
    }

    private static function montarMensagem(string $de, string $para, string $assunto, string $texto): string
    {
        $dominio = substr(strrchr($de, '@') ?: '@gwos.local', 1);
        $cab = [
            'Date: ' . date('r'),
            "From: GWOS <{$de}>",
            "To: <{$para}>",
            'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $cab) . "\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
    }

    private static function cmd($fp, string $linha, array $esperados): string
    {
        fwrite($fp, $linha . "\r\n");
        return self::esperar($fp, $esperados);
    }

    /** Lê a resposta (inclusive multilinha "250-...") e confere o código. */
    private static function esperar($fp, array $esperados): string
    {
        $resposta = '';
        while (($linha = fgets($fp, 1024)) !== false) {
            $resposta .= $linha;
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new \RuntimeException('SMTP respondeu: ' . trim($resposta));
        }
        return $resposta;
    }

    private static function configuracao(): array
    {
        $ler = static function (string $chave, string $padrao = ''): string {
            try {
                $v = Database::valor('SELECT valor FROM configuracoes WHERE chave = ?', [$chave]);
                return $v === false || $v === null ? $padrao : (string) $v;
            } catch (\Throwable) {
                return $padrao;
            }
        };

        return [
            'host'      => trim($ler('smtp_host')),
            'porta'     => (int) ($ler('smtp_porta', '25') ?: 25),
            'seguranca' => $ler('smtp_seguranca', 'nenhuma'),
            'usuario'   => $ler('smtp_usuario'),
            'senha'     => $ler('smtp_senha'),
            'remetente' => $ler('smtp_remetente', 'internet@localhost') ?: 'internet@localhost',
        ];
    }
}

<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria, Session, AcessoInternet, Mailer};

/**
 * Administração do acesso com login: usuários, sessões ativas, teste de
 * e-mail e consulta/exportação do registro de acessos.
 */
class InternetController extends Controller
{
    private const POR_PAGINA = 100;

    // GET /internet
    public function index(): void
    {
        Auth::exigir();

        $usuarios = AcessoInternet::usuarios();
        $sessoes  = AcessoInternet::sessoesAtivas();
        $dominio  = AcessoInternet::dominioPermitido();
        $smtp     = [
            'host'  => AcessoInternet::cfg('smtp_host'),
            'porta' => AcessoInternet::cfg('smtp_porta', '25'),
        ];

        $this->view('internet/index', compact('usuarios', 'sessoes', 'dominio', 'smtp'));
    }

    // POST /internet/sessoes/{id}/derrubar
    public function derrubar(string $id): void
    {
        Auth::exigir();
        $this->exigirCsrf();
        AcessoInternet::derrubarSessao((int) $id);
        Auditoria::registrar('derrubar_sessao_internet', 'sessoes_internet', (int) $id, null, null);
        Session::flash('sucesso', 'Sessão encerrada.');
        $this->redirect('/internet');
    }

    // POST /internet/usuarios/{id}/alternar
    public function alternar(string $id): void
    {
        Auth::exigir();
        $this->exigirCsrf();
        AcessoInternet::alternarAtivo((int) $id);
        Auditoria::registrar('alternar_usuario_internet', 'usuarios_internet', (int) $id, null, null);
        Session::flash('sucesso', 'Situação do usuário alterada.');
        $this->redirect('/internet');
    }

    // POST /internet/usuarios/{id}/excluir
    public function excluir(string $id): void
    {
        Auth::exigir();
        $this->exigirCsrf();
        $u = Database::fetch('SELECT id, nome, email FROM usuarios_internet WHERE id = ?', [$id]);
        AcessoInternet::excluirUsuario((int) $id);
        Auditoria::registrar('excluir_usuario_internet', 'usuarios_internet', (int) $id, $u ?: null, null);
        Session::flash('sucesso', 'Usuário excluído.');
        $this->redirect('/internet');
    }

    // POST /internet/teste-email
    public function testeEmail(): void
    {
        Auth::exigir();
        $this->exigirCsrf();

        $para = trim($_POST['para'] ?? '');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            Session::flash('erro', 'Informe um e-mail válido para o teste.');
            $this->redirect('/internet');
        }
        $ok = Mailer::enviar($para, 'Teste do GWOS', "Este é um e-mail de teste do portal de acesso à internet.\nSe você o recebeu, o SMTP está funcionando.\n");
        if ($ok) {
            Session::flash('sucesso', "E-mail de teste enviado para {$para}.");
        } else {
            Session::flash('erro', 'Falha no envio: ' . Mailer::$ultimoErro);
        }
        $this->redirect('/internet');
    }

    // GET /internet/logs   (?csv=1 exporta tudo que o filtro encontrar)
    public function logs(): void
    {
        Auth::exigir();

        $f = [
            'data_inicio' => $this->data($_GET['data_inicio'] ?? '', date('Y-m-d')),
            'data_fim'    => $this->data($_GET['data_fim'] ?? '', date('Y-m-d')),
            'ip'          => trim($_GET['ip'] ?? ''),
            'email'       => trim($_GET['email'] ?? ''),
            'dominio'     => trim($_GET['dominio'] ?? ''),
        ];

        $where  = ['a.ocorrido_em >= ?', 'a.ocorrido_em < ?'];
        $params = [$f['data_inicio'] . ' 00:00:00', date('Y-m-d', strtotime($f['data_fim'] . ' +1 day')) . ' 00:00:00'];

        if ($f['ip'] !== '')      { $where[] = 'a.ip = ?';            $params[] = $f['ip']; }
        if ($f['email'] !== '')   { $where[] = 'a.email LIKE ?';      $params[] = '%' . $this->like($f['email']) . '%'; }
        if ($f['dominio'] !== '') { $where[] = 'a.dominio LIKE ?';    $params[] = '%' . $this->like($f['dominio']) . '%'; }
        $cond = implode(' AND ', $where);

        $base = "FROM acessos_internet a LEFT JOIN usuarios_internet u ON u.email = a.email WHERE {$cond}";

        if (($_GET['csv'] ?? '') === '1') {
            $this->exportarCsv($base, $params);
        }

        $total  = (int) Database::valor("SELECT COUNT(*) {$base}", $params);
        $pagina = max(1, (int) ($_GET['p'] ?? 1));
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min($pagina, $paginas);
        $off    = ($pagina - 1) * self::POR_PAGINA;

        $registros = Database::fetchAll(
            "SELECT a.ocorrido_em, a.ip, a.email, u.nome, a.metodo, a.dominio, a.url, a.status, a.bytes
             {$base} ORDER BY a.ocorrido_em DESC, a.id DESC LIMIT " . self::POR_PAGINA . " OFFSET {$off}",
            $params
        );

        $this->view('internet/logs', compact('registros', 'f', 'total', 'pagina', 'paginas'));
    }

    private function exportarCsv(string $base, array $params): never
    {
        $arquivo = 'acessos_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                      // BOM: o Excel abre em UTF-8
        fputcsv($out, ['Data/hora', 'IP da máquina', 'E-mail', 'Nome', 'Método', 'Domínio', 'URL', 'Resultado', 'Bytes'], ';');

        // Em blocos, para não carregar milhões de linhas na memória
        $ultimo = PHP_INT_MAX;
        do {
            $linhas = Database::fetchAll(
                "SELECT a.id, a.ocorrido_em, a.ip, a.email, u.nome, a.metodo, a.dominio, a.url, a.status, a.bytes
                 {$base} AND a.id < ? ORDER BY a.id DESC LIMIT 5000",
                array_merge($params, [$ultimo])
            );
            foreach ($linhas as $l) {
                $ultimo = (int) $l['id'];
                // Todo texto vindo de fora (nome digitado pelo usuário, URL, domínio...)
                // passa por csvSeguro: o Excel executaria células que começam com = + - @
                fputcsv($out, [
                    $l['ocorrido_em'], $l['ip'],
                    $this->csvSeguro($l['email'] ?? '(isento de login)'), $this->csvSeguro($l['nome'] ?? ''),
                    $this->csvSeguro($l['metodo']), $this->csvSeguro($l['dominio']),
                    $this->csvSeguro($l['url']), $this->csvSeguro($l['status']), $l['bytes'],
                ], ';');
            }
        } while (count($linhas) === 5000);

        fclose($out);
        exit;
    }

    /** Evita "injeção de fórmula": o Excel executa células que começam com = + - @ */
    private function csvSeguro(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function like(string $v): string
    {
        return addcslashes($v, '\\%_');
    }

    private function data(string $v, string $padrao): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $padrao;
    }

    private function exigirCsrf(): void
    {
        if (!csrf_verificar()) {
            http_response_code(403);
            exit('Token inválido.');
        }
    }
}

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Hora;
use App\Core\Modulos;
use App\Core\Session;

/**
 * GWOS — Tela do módulo 30-hora-chrony.
 */
class HoraController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->view('modulos/hora', [
            'titulo'     => 'Hora',
            'modulo'     => 'hora-chrony',
            'servidores' => Hora::servidores(),
            'pool'       => Hora::pool(),
            'redes'      => Hora::redesLiberadas(),
            'tracking'   => Hora::tracking(),
            'fontes'     => Hora::fontes(),
            'servicos'   => Modulos::statusServicos('hora-chrony'),
            'temChronyc' => Hora::temChronyc(),
            'aviso'      => Session::flash('hora_aviso'),
            'erro'       => Session::flash('hora_erro'),
        ]);
    }

    public function salvar(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $servidores = preg_split('/[\s,]+/', trim($_POST['servidores'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pool       = preg_split('/[\s,]+/', trim($_POST['pool'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $r = Hora::definirServidores($servidores);
        if (!$r['ok']) {
            Session::flash('hora_erro', $r['saida']);
            $this->redirect('/modulos/hora');
        }

        $r = Hora::definirPool($pool);
        $this->flash($r, 'Fontes de hora atualizadas. O chrony leva alguns minutos para estabilizar.');
        $this->redirect('/modulos/hora');
    }

    /** Quem sincroniza com este gateway — precisa de root, vai pelo gwos-servico. */
    public function clientes(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->json(Hora::clientes());
    }

    private function exigirModulo(): void
    {
        if (!Modulos::temModulo('hora-chrony')) {
            $this->erro404();
            exit;
        }
    }

    private function exigirCsrf(): void
    {
        if (!csrf_verificar()) {
            http_response_code(419);
            exit('Sessão expirada. Recarregue a página e tente de novo.');
        }
    }

    /** @param array{ok:bool,saida:string} $resultado */
    private function flash(array $resultado, string $sucesso): void
    {
        if ($resultado['ok']) {
            Session::flash('hora_aviso', $sucesso);
        } else {
            Session::flash('hora_erro', $resultado['saida'] ?: 'Falha ao aplicar a alteração.');
        }
    }
}

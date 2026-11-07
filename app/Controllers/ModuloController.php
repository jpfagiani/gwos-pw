<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Estado;
use App\Core\Modulos;

/**
 * GWOS — Visão geral dos módulos instalados nesta máquina.
 *
 * Não guarda lista própria: lê /etc/gwos/modulos.d a cada carregamento. Um
 * módulo instalado pelo terminal aparece aqui na próxima página, sem cadastro
 * e sem reiniciar nada.
 */
class ModuloController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $instalados = Modulos::instalados();

        // Serviços de cada módulo, com o estado atual
        $status = [];
        foreach ($instalados as $marcador => $_) {
            $status[$marcador] = Modulos::statusServicos($marcador);
        }

        $this->view('modulos/index', [
            'titulo'     => 'Módulos',
            'modulo'     => 'modulos',
            'instalados' => $instalados,
            'ausentes'   => Modulos::naoInstalados(),
            'status'     => $status,
            'estado'     => Estado::tudo(),
            'temEstado'  => Estado::existe(),
            'temBanco'   => Modulos::temBanco(),
        ]);
    }
}

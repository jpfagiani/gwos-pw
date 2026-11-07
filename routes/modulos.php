<?php
/**
 * GWOS — Rotas das telas de módulo.
 *
 * A visão geral existe sempre. As telas de cada servidor entram aqui à medida
 * que são construídas, e só aparecem no menu quando 'tela' => true no catálogo
 * de App\Core\Modulos — assim o menu nunca mostra link quebrado.
 */

$router->get('/modulos', 'ModuloController@index');

// --- 20-dns-bind9 ---------------------------------------------------------
// As rotas existem sempre; o DnsController devolve 404 se o módulo não estiver
// instalado, e o menu só mostra o link quando ele está.
$router->get ('/modulos/dns',                    'DnsController@index');
$router->post('/modulos/dns/forwarders',         'DnsController@salvarForwarders');
$router->post('/modulos/dns/zonas',              'DnsController@adicionarZona');
$router->post('/modulos/dns/zonas/{dominio}/remover', 'DnsController@removerZona');
$router->get ('/modulos/dns/testar',             'DnsController@testar');
$router->get ('/modulos/dns/testar-forwarders',  'DnsController@testarForwarders');

// --- 25-dns-interno-dnsmasq -----------------------------------------------
$router->get ('/modulos/nomes',                    'NomesController@index');
$router->post('/modulos/nomes',                    'NomesController@adicionar');
$router->post('/modulos/nomes/{host}/atualizar',   'NomesController@atualizar');
$router->post('/modulos/nomes/{host}/remover',     'NomesController@remover');

// --- 30-hora-chrony -------------------------------------------------------
$router->get ('/modulos/hora',           'HoraController@index');
$router->post('/modulos/hora',           'HoraController@salvar');
$router->get ('/modulos/hora/clientes',  'HoraController@clientes');

// --- 40-firewall-nftables -------------------------------------------------
$router->get ('/modulos/firewall',          'FirewallController@index');
$router->post('/modulos/firewall/regerar',  'FirewallController@regerar');

// --- 50-proxy-squid -------------------------------------------------------
$router->get ('/modulos/proxy',             'ProxyController@index');
$router->post('/modulos/proxy/portas',      'ProxyController@salvarPortas');
$router->post('/modulos/proxy/recarregar',  'ProxyController@recarregar');
$router->get ('/modulos/proxy/verificar',   'ProxyController@verificar');

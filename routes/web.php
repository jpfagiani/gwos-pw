<?php

$router->get('/login', 'AuthController@formulario');
$router->post('/login', 'AuthController@autenticar');
$router->post('/logout', 'AuthController@sair');

$router->get('/', 'DashboardController@index');
$router->get('/dashboard/info', 'DashboardController@info');
$router->get('/monitoramento', 'OperacionalController@monitoramento');
$router->get('/ranking', 'OperacionalController@ranking');
$router->get('/equipamentos', 'OperacionalController@equipamentos');
$router->get('/equipamentos/ficha', 'OperacionalController@fichaEquipamento');
$router->get('/controle-acesso', 'OperacionalController@controleAcesso');
$router->get('/alertas', 'OperacionalController@alertas');
$router->post('/alertas/{id}/reconhecer', 'OperacionalController@reconhecer');
$router->post('/alertas/{id}/resolver', 'OperacionalController@resolverAlerta');
$router->get('/agenda', 'OperacionalController@agenda');
$router->get('/agenda/simulador', 'OperacionalController@simular');

$router->get('/grupos', 'GrupoController@index');
$router->post('/grupos', 'GrupoController@criar');
$router->delete('/grupos/{id}', 'GrupoController@remover');

$router->get('/grupos/{id}/ips', 'GrupoController@ips');
$router->post('/grupos/{id}/ips', 'GrupoController@adicionarIp');
$router->delete('/ips/{id}', 'GrupoController@removerIp');
$router->post('/grupos/aplicar', 'GrupoController@aplicar');

$router->get('/dominios', 'DominioController@index');
$router->post('/dominios', 'DominioController@criar');
$router->delete('/dominios/{id}', 'DominioController@remover');
$router->post('/dominios/aplicar', 'DominioController@aplicar');

$router->get('/horarios', 'HorarioController@index');
$router->post('/horarios', 'HorarioController@criar');
$router->delete('/horarios/{id}', 'HorarioController@remover');

$router->get('/nat', 'NatController@index');
$router->post('/nat', 'NatController@criar');
$router->delete('/nat/{id}', 'NatController@remover');
$router->post('/nat/{id}/toggle', 'NatController@toggle');
$router->post('/nat/aplicar', 'NatController@aplicarTodas');

$router->get('/relatorios/exportar', 'RelatorioController@exportar');
$router->get('/relatorios/auditoria', 'RelatorioController@auditoria');
$router->get('/relatorios', 'RelatorioController@index');
$router->get('/tarefas', 'TarefasController@index');
$router->get('/tarefas/lista', 'TarefasController@lista');
$router->get('/tarefas/{id}', 'TarefasController@obter');

$router->get('/configuracoes', 'ConfigController@index');
$router->post('/configuracoes', 'ConfigController@salvar');
$router->post('/configuracoes/historico/{id}/reverter', 'ConfigController@reverter');

$router->get('/senha/trocar',  'SenhaController@formularioTrocar');
$router->post('/senha/trocar', 'SenhaController@trocar');
$router->get('/senha/reset',   'SenhaController@formularioReset');
$router->post('/senha/reset',  'SenhaController@reset');
$router->get('/senha/gerar',   'SenhaController@formularioGerar');
$router->post('/senha/gerar',  'SenhaController@gerar');

// ── Acesso à internet com login ──────────────────────────────────────────────
// Portal público (o Squid redireciona para cá quem não tem sessão)
$router->get('/acesso',            'AcessoController@formulario');
$router->post('/acesso',           'AcessoController@entrar');
$router->post('/acesso/sair',      'AcessoController@sair');
$router->get('/acesso/cadastro',   'AcessoController@formularioCadastro');
$router->post('/acesso/cadastro',  'AcessoController@cadastrar');
$router->get('/acesso/esqueci',    'AcessoController@formularioEsqueci');
$router->post('/acesso/esqueci',   'AcessoController@esqueci');
$router->get('/acesso/senha',      'AcessoController@formularioSenha');
$router->post('/acesso/senha',     'AcessoController@definirSenha');

// Administração (exige login de administrador)
$router->get('/internet',                           'InternetController@index');
$router->get('/internet/logs',                      'InternetController@logs');
$router->post('/internet/teste-email',              'InternetController@testeEmail');
$router->post('/internet/sessoes/{id}/derrubar',    'InternetController@derrubar');
$router->post('/internet/usuarios/{id}/alternar',   'InternetController@alternar');
$router->post('/internet/usuarios/{id}/excluir',    'InternetController@excluir');

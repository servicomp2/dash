<?php
/** @var \Bramus\Router\Router $router */

$router->get('/admin/login', 'Web\AuthController@login');
$router->post('/admin/auth/login', 'Web\AuthController@authenticate');
$router->post('/admin/auth/session', 'Web\AuthController@session');
$router->get('/admin/logout', 'Web\AuthController@logout');

$router->get('/admin', 'Web\DashboardController@index');


















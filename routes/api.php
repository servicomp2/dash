<?php
/** @var \Bramus\Router\Router $router */

$router->mount('/api/v1', function() use ($router) {

    // --- ZONA DE MÓDULOS AUTOMÁTICOS ---
    // El CLI escribirá los 'require_once' aquí debajo automáticamente.
    // No borres esta sección.

        $router->post('/auth/request-code', 'Api\AuthController@requestCode');
    $router->post('/auth/verify', 'Api\AuthController@verifyCode');

    
    // Auth de Usuarios Finales (App)
    $router->post('/user/auth/request-code', 'Api\UserAuthController@requestCode');
    $router->post('/user/auth/verify', 'Api\UserAuthController@verifyCode');

        $router->post('/auth/login', 'Api\AuthController@login');

    
    // Auth de Usuarios Finales (App)
    $router->post('/user/auth/login', 'Api\UserAuthController@login');

});
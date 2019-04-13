<?php
date_default_timezone_set('America/New_York');
ini_set("memory_limit", "-1");
ini_set('display_errors', 1);

set_time_limit(0);
error_reporting(E_ALL);
$time = time();

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

define('ROOT', dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR);
define('LIB', ROOT.'lib'.DIRECTORY_SEPARATOR);
define('COMPOSER', ROOT.'vendor'.DIRECTORY_SEPARATOR);

require COMPOSER.'autoload.php';
require ROOT.'bootstrap.php';

$app = new \Slim\App(['settings' => ['displayErrorDetails' => 1]]);

$app->add(function ($req, $res, $next) use ($allowed) {
    $response = $next($req, $res);
    $response =  $response
    ->withHeader('Access-Control-Allow-Origin', getenv('APP_URL'))
    ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
    ->withHeader('Access-Control-Allow-Credentials', 'true');
    if (in_array($_SERVER['HTTP_HOST'], $allowed)) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $_SERVER['HTTP_HOST']);
    }
    return $response;
});

$app->options('/{routes:.+}', function ($request, $response, $args) {
    $route = $request->getAttribute('route');
    if (strpos($route->getPattern(), '/webhook') === 0) {
        $response = $response->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin')
        ->withHeader('Access-Control-Allow-Methods', 'POST')
        ->withHeader('Access-Control-Allow-Credentials', 'false');
    }

    return $response;
});

$router = Container::get('Router');
foreach ($router->listRoutes() as $route) {
    $app->map([$route['verb']], $route['route'], function ($request, $response, $args) use ($router, $app) {
        try {
            $pattern = $request->getAttribute('route');
            $route = $router->getRoute($request->getMethod(), $pattern->getPattern());
            return $route->fire($request, $response, $args, $app);
        } catch (NotFoundException $e) {
            return $response->withStatus(404);
        } catch (RequiredException $e) {
            return $response->withStatus(400, $e->getMessage());
        } catch (AuthenticationException $e) {
            return $response->withStatus(403, $e->getMessage());
        } catch (ValidationFailed $e) {
            error_log($e);
            return $response->withStatus(417, $e->getMessage());
        } catch (\Exception $e) {
            error_log($e);
            return $response->withStatus(500, 'Sorry, something happened on our end');
        }
    });
}

// Catch-all route to serve a 404 Not Found page if none of the routes match
// NOTE: make sure this route is defined last
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($req, $res) {
    $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
    return $handler($req, $res);
});

$app->run();

$now = time();
$time = $now - $time;
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$code = http_response_code();
error_log("{$time} seconds: {$code} {$method} {$uri}");

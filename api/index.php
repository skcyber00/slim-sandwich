<?php
require_once  __DIR__ . '/../src/vendor/autoload.php';
$error = require_once __DIR__ . '/../src/error/error.php';
$settings = require_once  __DIR__ . '/../src/conf/settings.php';

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \lbs\control\Categoriescontroller as Categoriescontroller;



// initialisation connection
$config = parse_ini_file('../src/conf/lbs.db.conf.ini');
$db = new Illuminate\Database\Capsule\Manager();
$db->addConnection( $config );
$db->setAsGlobal();
$db->bootEloquent();




// on ajoute ici tous les service que l'apli aura besoin à différents endroit
// principe du conteneur de dépendance : important
$app_config = array_merge($settings,$error);
$app = new \Slim\App( new \Slim\Container($app_config));


// Chargements de tous les templates twig
//$tmpl = $twig->loadTemplate('index.twig');

// require de toutes mes routes
require_once __DIR__.'/../src/route/routes.php';




$app->run();

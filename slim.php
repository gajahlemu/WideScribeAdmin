<?php

/* WIDESCRIBE API
 * Endpoint for receiving REST calls to VXL interfaces.
 * URL calls can be made to these routes to get JSON data for all user related calls
 * To test the api for operation use get route vxlpay.appspot.com/test
 */

// External libraries
require 'vendor/autoload.php';

require_once __DIR__ . '/serv/Partner.php';

// ENVIRONMENT VARIABLES
require_once __DIR__ . '/environment.php';


// PREPARATIONS
$conn = VXLgate::getConn();
$account = null;
$partner = null;
$debug = true;


$app = new \Slim\Slim(array(
    'debug' => true
        ));

$_SERVER['SERVER_PORT'] = 80;
// ROUTES

require_once __DIR__ . '/routes/wordpressRoutes.php';

/*
  $app->after(function (Request $request,  $response = null) {

  VXLgate::log('Performing this after the response' . json_encode($response), '');

  if($response){
  $response->headers->set('Access-Control-Allow-Origin', $request->headers->get('origin'));
  //  $response->headers->set('Access-Control-Allow-Credentials', 'true');
  //  $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
  //  $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type');
  //  $response->headers->set('Access-Control-Request-Headers', 'Origin, Content-Type');
  }


  });
 * 
 */
$app->run();
?>
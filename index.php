<?php
require 'vendor/autoload.php';
require_once 'classes/Server.php';

// Conf
require_once('config.php');

 // Create oAuth2 Server
$server = new Server($config);

// Dependency container
$configuration = [
    'settings' => [
        'displayErrorDetails' => true,
    ],
    'server' => $server,
    'db' => $server->getStorage()
];
$container = new \Slim\Container($configuration);

// Create and configure Slim app
$app = new \Slim\App($container);

// oAuthMiddleware : check resource requests
$oAuthMiddleware = function ($request, $response, $next) {
    if (!$this->server->verifyResourceRequest($this->server->getRequest())) {
        $this->server->getResponse()->send();
        die; // Important, otherwise Slim overrides HTTP headers
    }

    $response = $next($request, $response);

    return $response;
};


// Homepage
$app->get('/', function ($request, $response, $args) {
    return $response->write("homepage");
});


// Token request
$app->post('/token', function ($request, $response, $args) {
    // Handle a request for an OAuth2.0 Access Token and send the response to the client
    $this->server->handleTokenRequest($this->server->getRequest())->send();
    die; // Important, otherwise Slim overrides HTTP headers
});


// Sync request
$app->post('/sync', function ($request, $response, $args) {
    $token = $this->server->getAccessTokenData($this->server->getRequest());

    $deviceNotes = $this->db->getDeviceNotesFromJson($request->getParam('notes'));
    $devices = $this->db->getDevices($token['user_id']);
    $cloudNotes = $this->db->getNotesToSync($token['user_id'], $token['device_id']);
    $this->db->insertNotesFromDevice($deviceNotes);
    $this->db->setToSyncForOtherDevices($devices, $deviceNotes, $token['device_id']);
    $this->db->setSyncOKForDevice($cloudNotes, $token['device_id']);
    $this->db->cleanNotes($token['device_id']);

    return $response->withJson($cloudNotes);
})->add($oAuthMiddleware);


// Run app
$app->run();

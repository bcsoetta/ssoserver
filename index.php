<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once 'DB/Database.php';
require_once 'MySSOServer.php';

// by bowie
// this function auto convert the hypenated words into camel case
// so we can directly call server's method without lookup table
function convertHypenWordsToCamelCase($word) {
    $c = explode('-', $word);
    return array_reduce($c, function ($accum, $e) use ($c) {
        return $accum . ($e == $c[0] ? $e : ucfirst($e));
    });
}

// instantiate SSO Server
$ssoServer = new MySSOServer();

// grab command by checking $_REQUEST globals, defaulting to null
$command = isset($_REQUEST['command']) ? convertHypenWordsToCamelCase($_REQUEST['command']) : null;

// convert to real method name if possible
// $command = array_key_exists($command, $commandMap) ? $commandMap[$command] : $command;
// directly converted above, so we do nothing here

// continue as usual
if (!$command || !method_exists($ssoServer, $command)) {
    header("HTTP/1.1 404 Not Found");
    header('Content-type: application/json; charset=UTF-8');
    
    echo json_encode(['server status' => 'ON', 'error' => 'Unknown command: ' . $command], JSON_PRETTY_PRINT);
    exit();
} else {
    // echo "Executing command: $command";
    $result = $ssoServer->$command();
}




?>




<?php
include "vendor/autoload.php";
error_reporting(E_ALL);
use Inbenta\SmoochConnector\SmoochConnector;

//Instance new SmoochConnector
$appPath=__DIR__.'/';
$app = new SmoochConnector($appPath);

$handle = $app->handleRequest();

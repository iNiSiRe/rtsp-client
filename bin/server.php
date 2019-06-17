<?php

$loader = require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$client = new \RTSP\Client($loop);

$client->connect('rtsp://@192.168.31.214:554/onvif1');

$loop->run();
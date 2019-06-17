<?php

namespace RTSP\Protocol;


class Request
{
    public $method;

    public $uri;

    public $version = '1.0';

    public $headers = [];

    public function build()
    {
        $packet = sprintf('%s %s RTSP/%s', $this->method, $this->uri, $this->version) . "\r\n";
        foreach ($this->headers as $name => $value) {
            $packet .= $name . ': ' . $value . "\r\n";
        }
        $packet .= "\r\n";

        return $packet;
    }
}
<?php

namespace RTSP;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use RTSP\Protocol\Request;
use RTSP\Protocol\Response;

class Client
{
    /**
     * @var LoopInterface
     */
    private $loop;

    private $cseq = 1;

    /**
     * @var ConnectionInterface
     */
    private $connection;

    private $realm;

    private $nonce;

    private $user;

    private $password;

    private $uri;

    private $session;

    private $awaits = [];

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function connect($uri)
    {
        $connector = new \React\Socket\Connector($this->loop);

        $components = parse_url($uri);
        $connectUri = $components['host'] . ':' . $components['port'];

        echo $connectUri . PHP_EOL;

        $this->uri = $uri;
        $this->user = $components['user'];
        $this->password = $components['password'];

        $connector->connect($connectUri)->then(function (ConnectionInterface $connection) use ($uri) {

            try {

                $this->connection = $connection;

                $this->connection->on('data', [$this, 'onReceive']);

                return $this->options($uri);

            } catch (\Throwable $exception) {

                echo sprintf(
                        '[throwable] %s %s in %s:%s',
                        get_class($exception),
                        $exception->getMessage(),
                        $exception->getFile(),
                        $exception->getLine()
                    ) . PHP_EOL;

            }

        })->then(function ($response) use ($uri) {

            var_dump($response);

            return $this->describe($uri);

        })->then(function (Response $response) use ($uri) {

            if ($response->code == 401) {
                $header = $response->headers['www-authenticate'] ?? "";
                $vars = $this->parseVars(str_replace('Digest ', '', $header));

                $this->realm = $vars['realm'];
                $this->nonce = $vars['nonce'];

                return $this->describe($uri, true);
            } else {
                return $this->describe($uri, false);
            }

        })->then(function (Response $response) {

            return $this->setup($this->uri . '/track1', true);

        })->then(function (Response $response) {

            $this->session = explode(';', $response->headers['session'])[0];

            return $this->play($this->uri);

        })->then(function (Response $response) {

//            var_dump($response);

        });
    }

    private function parseVars($data)
    {
        $vars = [];
        $raw = explode(',', $data);
        foreach ($raw as $variable) {
            list ($name, $value) = explode('=', $variable);
            $vars[trim($name)] = trim($value, ' "');
        }

        return $vars;
    }

    private function send(Request $request)
    {
        echo '--- output ----' . PHP_EOL;

        $build = $request->build();
        echo $build;

        $deferred = new Deferred();
        $cseq = $request->headers['cseq'];
        $this->awaits[$cseq] = $deferred;

        $this->connection->write($build);

        return $deferred->promise();
    }

    public function onReceive($data)
    {
        if (substr($data, 0, 4) === 'RTSP') {

            echo '--- input ----' . PHP_EOL;

            echo $data;

            $blocks = explode("\r\n\r\n", $data);
            $response = $this->parseResponse($blocks[0]);
            $cseq = $response->headers['Cseq'];

            $deferred = $this->awaits[$cseq] ?? null;

            if ($deferred !== null) {
                unset($this->awaits[$cseq]);
                echo 'resolve' . PHP_EOL;
                $deferred->resolve($response);
            }
        } else {

            echo '--- RTP packet ----' . PHP_EOL;

            $header = substr($data, 0, 12);

            for ($i = 0; $i < strlen($header); $i++) {
                $x = ord($header[$i]);
                echo sprintf("%'.08b", $x) . ' ';

                if ($i % 4 == 0) {
                    echo PHP_EOL;
                }

            }

            echo '--- END RTP packet ---' . PHP_EOL;

        }
    }

    private function buildRequest($method, $uri, $headers)
    {
        $request = new Request();

        $request->method = $method;
        $request->uri = $uri;
        $request->headers = $headers;

        return $request;
    }

    private function buildDigest($user, $pwd, $realm, $method, $uri, $nonce)
    {
        $h1 = md5("$user:$realm:$pwd");
        $h2 = md5("$method:$uri");

        return md5("$h1:$nonce:$h2");
    }


    private function parseHeaders($rawHeaders)
    {
        $headers = [];

        foreach ($rawHeaders as $header) {
            list ($name, $value) = explode(':', $header);
            $headers[strtolower($name)] = trim($value);
        }

        return $headers;
    }

    private function parseStatus($data)
    {
        if (preg_match('#^RTSP\/1.0 (\d+) ([\w\s]+)$#', $data, $matches)) {

            $code = $matches[1];
            $text = $matches[2];

            return [$code, $text];

        } else {

            throw new \RuntimeException('Bad response status: ' . $data);

        }
    }

    private function parseResponse($data)
    {
        $data = str_replace("\r", "", $data);
        $rawHeaders = explode("\n", $data);
        $rawStatus = array_shift($rawHeaders);
        $status = $this->parseStatus($rawStatus);
        $headers = $this->parseHeaders($rawHeaders);

        $response = new Response();
        $response->code = $status[0];
        $response->text = $status[1];
        $response->headers = $headers;

        return $response;
    }

    private function options($uri)
    {
        $headers = [
            'CSeq' => $this->cseq++,
            'User-Agent' => 'custom'
        ];

        return $this->send($this->buildRequest('OPTIONS', $uri, $headers));
    }


    private function buildAuthorizationHeader($method)
    {
        $digest = $this->buildDigest(
            $this->user,
            $this->password,
            $this->realm,
            $method,
            $this->uri,
            $this->nonce
        );

        return sprintf(
            'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s"',
            $this->user,
            $this->realm,
            $this->nonce,
            $this->uri,
            $digest
        );
    }

    private function describe($uri, $authorization = false)
    {
        $headers = [];

        $headers['CSeq'] = $this->cseq++;

        if ($authorization) {
            $headers['Authorization'] = $this->buildAuthorizationHeader('DESCRIBE');
        }

        $headers['User-Agent'] = 'custom';
        $headers['Accept'] = 'application/sdp';

        return $this->send($this->buildRequest('DESCRIBE', $uri, $headers));
    }

    private function setup($uri, $authorization = false)
    {
        /**
        SETUP rtsp://127.0.0.1:8000/onvif1/track1 RTSP/1.0
        CSeq: 5
        Authorization: Digest username="admin", realm="HIipCamera", nonce="52202ebcf1eb097bbda57d4314d7a062", uri="rtsp://127.0.0.1:8000/onvif1", response="20c607218e4f5a24f4d8c3f2b6740c7b"
        User-Agent: LibVLC/3.0.4 (LIVE555 Streaming Media v2018.02.18)
        Transport: RTP/AVP;unicast;client_port=41580-41581
        */

        $headers = [
            'CSeq' => $this->cseq++,
            'User-Agent' => 'custom',
//            'Transport' => 'RTP/AVP;unicast;client_port=40300-40301'
            'Transport' => 'RTP/AVP/TCP;interleaved=0-1'
        ];

        if ($authorization) {
            $headers['Authorization'] = $this->buildAuthorizationHeader('SETUP');
        }

        return $this->send($this->buildRequest('SETUP', $uri, $headers));
    }

    private function play($uri, $authorization = true)
    {
        $headers = [
            'CSeq' => $this->cseq++,
            'User-Agent' => 'custom',
            'Session' => $this->session,
            'Range' => 'npt=0.000-'
        ];

        if ($authorization) {
            $headers['Authorization'] = $this->buildAuthorizationHeader('PLAY');
        }

        return $this->send($this->buildRequest('PLAY', $uri, $headers));
    }
}
<?php

declare(strict_types=1);

namespace Apn\AmiClient\Scripts;

final class SocketRuntimePreflight
{
    /**
     * @return array{
     *   timestamp_utc:string,
     *   check:string,
     *   status:string,
     *   classification:string,
     *   signature:string
     * }
     */
    public static function run(string $host = '127.0.0.1'): array
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);

        if (is_resource($server)) {
            fclose($server);
            return [
                'timestamp_utc' => gmdate('c'),
                'check' => 'tcp_bind_ephemeral',
                'status' => 'PASS',
                'classification' => 'RUNTIME_OK',
                'signature' => 'socket_bind_ok',
            ];
        }

        $signature = sprintf(
            'stream_socket_server(): Unable to connect to tcp://%s:0 (%s)',
            $host,
            $errstr !== '' ? $errstr : 'unknown'
        );

        return [
            'timestamp_utc' => gmdate('c'),
            'check' => 'tcp_bind_ephemeral',
            'status' => 'FAIL',
            'classification' => EnvironmentFailureClassifier::classify($signature),
            'signature' => $signature,
        ];
    }
}


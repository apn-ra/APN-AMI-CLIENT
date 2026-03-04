<?php

declare(strict_types=1);

namespace Apn\AmiClient\Scripts;

final class EnvironmentFailureClassifier
{
    private const array SANDBOX_PATTERNS = [
        '/could not find driver/i',
        '/PDOException:\s*could not find driver/i',
        '/SQLSTATE\[08006\]/i',
        '/SQLSTATE\[HY000\].*connection/i',
        '/Connection refused.*(DB|database)?/i',
        '/No such file or directory.*SQLite/i',
        '/RedisException/i',
        '/Class\s+Redis\s+not\s+found/i',
        '/NOAUTH Authentication required/i',
        '/php_network_getaddresses/i',
        '/Call to undefined function\s+pg_/i',
        '/Undefined extension/i',
        '/Class .* not found from extension/i',
        '/Temporary failure in name resolution/i',
        '/Operation timed out/i',
        '/SSL:\s*Connection reset/i',
        '/stream_socket_server\(\): Unable to connect to tcp:\/\/127\.0\.0\.1:0 \(Success\)/i',
        '/Could not start mock server:\s*Success/i',
        '/Unable to start fake AMI server:\s*Success \(0\)/i',
    ];

    public static function classify(string $output): string
    {
        foreach (self::SANDBOX_PATTERNS as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return 'SANDBOX_ENVIRONMENT';
            }
        }

        return 'ACTIONABLE_DEFECT';
    }
}

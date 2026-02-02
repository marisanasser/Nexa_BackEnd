<?php

declare(strict_types=1);

namespace App\Http\Controllers\Base;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class HealthCheckController extends Controller
{
    /**
     * Check the health of the application and its dependencies.
     *
     * @return JsonResponse
     */
    public function __invoke()
    {
        $status = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'mail' => $this->checkMail(),
            ],
        ];

        // Determine overall status
        if ('ok' !== $status['services']['database']['status']
            || 'ok' !== $status['services']['cache']['status']
            || 'ok' !== $status['services']['mail']['status']) {
            $status['status'] = 'error';

            return response()->json($status, 503);
        }

        return response()->json($status);
    }

    private function checkDatabase()
    {
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'ok',
                'latency_ms' => $latency,
            ];
        } catch (Exception $e) {
            $default = config('database.default');
            $cfg = config("database.connections.$default");
            $host = is_array($cfg) ? ($cfg['host'] ?? null) : null;
            $port = is_array($cfg) ? ($cfg['port'] ?? null) : null;
            $database = is_array($cfg) ? ($cfg['database'] ?? null) : null;
            $username = is_array($cfg) ? ($cfg['username'] ?? null) : null;
            $driver = is_array($cfg) ? ($cfg['driver'] ?? null) : null;
            $url = env('DATABASE_URL');
            $missing = [];
            foreach (['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_SSLMODE','DATABASE_URL'] as $k) {
                $v = env($k);
                if ($v === null || $v === '') {
                    $missing[] = $k;
                }
            }
            return [
                'status' => 'error',
                'message' => 'Could not connect to the database',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'config_host' => $host,
                'config_port' => $port,
                'config_database' => $database,
                'config_username' => $username ? '***' : null,
                'config_driver' => $driver,
                'connection_name' => $default,
                'env_database_url' => $url,
                'missing_env' => $missing,
            ];
        }
    }

    private function checkCache()
    {
        try {
            $startTime = microtime(true);
            Cache::store()->get('health_check'); // Simple read operation
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'ok',
                'driver' => config('cache.default'),
                'latency_ms' => $latency,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Could not connect to cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }

    private function checkMail()
    {
        try {
            $default = config('mail.default');
            $mailers = config('mail.mailers');
            $fromAddress = config('mail.from.address');
            $fromName = config('mail.from.name');

            $sesConfigured = (bool) (env('AWS_ACCESS_KEY_ID') && env('AWS_SECRET_ACCESS_KEY'));
            $smtpConfigured = (bool) (env('MAIL_HOST') && env('MAIL_USERNAME') && env('MAIL_PASSWORD'));

            $availableMailers = array_keys(is_array($mailers) ? $mailers : []);
            $usesSes = in_array('ses', $availableMailers, true);
            $usesSmtp = in_array('smtp', $availableMailers, true);

            $missingEnv = [];
            if ($default === 'ses' || $usesSes) {
                foreach (['AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_DEFAULT_REGION'] as $k) {
                    if (!env($k)) {
                        $missingEnv[] = $k;
                    }
                }
            }
            if ($default === 'smtp' || $usesSmtp) {
                foreach (['MAIL_HOST','MAIL_PORT','MAIL_ENCRYPTION','MAIL_USERNAME','MAIL_PASSWORD'] as $k) {
                    if (!env($k)) {
                        $missingEnv[] = $k;
                    }
                }
            }
            foreach (['MAIL_FROM_ADDRESS','MAIL_FROM_NAME'] as $k) {
                if (!env($k)) {
                    $missingEnv[] = $k;
                }
            }

            return [
                'status' => 'ok',
                'default' => $default,
                'from' => [
                    'address' => $fromAddress,
                    'name' => $fromName,
                ],
                'providers' => [
                    'ses' => [
                        'configured' => $sesConfigured,
                        'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION')),
                    ],
                    'smtp' => [
                        'configured' => $smtpConfigured,
                        'host' => env('MAIL_HOST'),
                        'port' => env('MAIL_PORT'),
                        'encryption' => env('MAIL_ENCRYPTION'),
                        'username_set' => (bool) env('MAIL_USERNAME'),
                    ],
                    'log' => [
                        'configured' => true,
                    ],
                ],
                'missing_env' => array_values(array_unique($missingEnv)),
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Could not read mail configuration',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
}

<?php

namespace Omni\BillingEngine\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

/**
 * Best-effort: make the billing channel's log FILE group/world-writable right
 * after it is opened, so a queue WORKER running as a different user than the
 * process that first created the file (e.g. a root cron dispatcher) can still
 * append its outcome lines.
 *
 * Why this is needed: a Laravel daily/single channel file is created 0644 owned
 * by whoever writes first. When the dispatcher runs as root and the workers run
 * as another user, the workers' SUCCESS/DECLINE/SKIP/DEAD lines are silently
 * dropped — which is exactly why the billing log showed only "claimed and
 * dispatched" lines and none of the per-member outcomes.
 *
 * Idempotent (only chmods when the mode differs) and never throws — a
 * permissions hiccup must not break a charge.
 */
final class LogFilePermissions
{
    public static function ensureWritable(LoggerInterface $logger, int $mode): void
    {
        try {
            $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;

            if (!method_exists($monolog, 'getHandlers')) {
                return;
            }

            foreach ($monolog->getHandlers() as $handler) {
                if (!$handler instanceof StreamHandler) {
                    continue;
                }

                $url = $handler->getUrl();

                // Skip php://stderr, php://stdout, and any non-file stream.
                if (!is_string($url) || $url === '' || str_contains($url, '://')) {
                    continue;
                }

                if (is_file($url) && (fileperms($url) & 0777) !== $mode) {
                    @chmod($url, $mode);
                }
            }
        } catch (\Throwable $e) {
            // never let logging permissions break billing
        }
    }
}

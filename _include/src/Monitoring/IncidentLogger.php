<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** Enriches Symfony's existing fatal-error log and keeps its renderer intact. */
final class IncidentLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $logger, private readonly FailureDiagnostics $diagnostics)
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable && \in_array($level, [LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY], true)) {
            try {
                $context['incident_id'] = $this->diagnostics->record($exception);
            } catch (\Throwable) {
                // Recording diagnostics must not replace the original failure.
            }
        }

        $this->logger->log($level, $message, $context);
    }
}

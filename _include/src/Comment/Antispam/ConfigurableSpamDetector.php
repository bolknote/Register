<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Psr\Log\LoggerInterface;
use Register\Core\Comment\AkismetProxy;
use Register\Core\Comment\SpamDetectorComment;
use Register\Core\Comment\SpamDetectorInterface;
use Register\Core\Comment\SpamDetectorReport;
use Register\Core\Config\StringProxy;

final readonly class ConfigurableSpamDetector implements SpamDetectorInterface
{
    public const string MODE_AKISMET = 'akismet';

    public const string MODE_SHADOW = 'shadow';

    public const string MODE_LOCAL = 'local';

    public function __construct(
        private LocalSpamDetector        $localDetector,
        private AkismetProxy             $akismetProxy,
        private SpamAssessmentStoreInterface $assessmentRepository,
        private StringProxy              $mode,
        private LoggerInterface          $logger,
    ) {
    }

    #[\Override]
    public function getReport(SpamDetectorComment $comment, string $clientIp): SpamDetectorReport
    {
        $mode = mb_strtolower(trim($this->mode->get()));

        if ($mode === self::MODE_AKISMET) {
            $akismetReport = $this->akismetProxy->getReport($comment, $clientIp);
            if ($akismetReport->status !== SpamDetectorReport::STATUS_DISABLED) {
                return $akismetReport;
            }

            $this->logger->warning('Akismet is disabled in Akismet mode; the local verdict was used.');

            return $this->localDetector->getReport($comment, $clientIp);
        }

        $localReport = $this->localDetector->getReport($comment, $clientIp);
        if ($mode !== self::MODE_SHADOW) {
            if ($mode !== self::MODE_LOCAL) {
                $this->logger->warning('Unknown antispam mode; local mode was used.', ['mode' => $mode]);
            }

            return $localReport;
        }

        $akismetReport = $this->akismetProxy->getReport($comment, $clientIp);
        $assessmentId  = $localReport->getAssessmentId();
        if ($assessmentId !== null) {
            try {
                $this->assessmentRepository->setShadowStatus($assessmentId, $akismetReport->status);
            } catch (\Throwable $throwable) {
                $this->logger->error('Unable to store the Akismet shadow verdict.', ['exception' => $throwable]);
            }
        }

        if ($akismetReport->status === SpamDetectorReport::STATUS_DISABLED) {
            $this->logger->warning('Akismet is disabled in shadow mode; the local verdict was used.');

            return $localReport;
        }

        return $akismetReport->withAssessmentFrom($localReport);
    }
}

<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamMetricsRepository
{
    public const int REPORT_WINDOW = 30 * 24 * 60 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @return array<string, int>
     * @throws DbLayerException
     */
    public function summarize(?int $now = null): array
    {
        $now ??= time();
        $localSpam = "status IN ('spam', 'blatant')";
        $shadowSpam = "shadow_status IN ('spam', 'blatant')";
        $localAvailable = "status IN ('ham', 'spam', 'blatant')";
        $shadowAvailable = "shadow_status IN ('ham', 'spam', 'blatant')";
        $comparisonAvailable = "$localAvailable AND $shadowAvailable";

        $row = $this->dbLayer
            ->select(
                'COUNT(*) AS total',
                "SUM(CASE WHEN $localSpam THEN 1 ELSE 0 END) AS local_positive",
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed",
                "SUM(CASE WHEN $localAvailable AND moderator_label = 'ham' THEN 1 ELSE 0 END) AS labelled_ham",
                "SUM(CASE WHEN $localAvailable AND moderator_label = 'spam' THEN 1 ELSE 0 END) AS labelled_spam",
                "SUM(CASE WHEN moderator_label = 'ham' AND $localSpam THEN 1 ELSE 0 END) AS local_false_positive",
                "SUM(CASE WHEN moderator_label = 'spam' AND status = 'ham' THEN 1 ELSE 0 END) AS local_false_negative",
                "SUM(CASE WHEN $comparisonAvailable THEN 1 ELSE 0 END) AS shadow_total",
                "SUM(CASE WHEN $comparisonAvailable AND ((status = 'ham' AND shadow_status = 'ham') OR ($localSpam AND $shadowSpam)) THEN 1 ELSE 0 END) AS shadow_agreement",
                "SUM(CASE WHEN $shadowAvailable AND moderator_label = 'ham' THEN 1 ELSE 0 END) AS shadow_labelled_ham",
                "SUM(CASE WHEN $shadowAvailable AND moderator_label = 'spam' THEN 1 ELSE 0 END) AS shadow_labelled_spam",
                "SUM(CASE WHEN $shadowAvailable AND moderator_label = 'ham' AND $shadowSpam THEN 1 ELSE 0 END) AS shadow_false_positive",
                "SUM(CASE WHEN $shadowAvailable AND moderator_label = 'spam' AND shadow_status = 'ham' THEN 1 ELSE 0 END) AS shadow_false_negative",
            )
            ->from('spam_assessments')
            ->where("source = 'local'")
            ->andWhere('created_at >= :since')->setParameter('since', $now - self::REPORT_WINDOW)
            ->execute()
            ->fetchAssoc()
        ;

        $keys = [
            'total',
            'local_positive',
            'failed',
            'labelled_ham',
            'labelled_spam',
            'local_false_positive',
            'local_false_negative',
            'shadow_total',
            'shadow_agreement',
            'shadow_labelled_ham',
            'shadow_labelled_spam',
            'shadow_false_positive',
            'shadow_false_negative',
        ];

        $summary = [];
        foreach ($keys as $key) {
            $summary[$key] = $row === false ? 0 : (int)($row[$key] ?? 0);
        }

        return $summary;
    }
}

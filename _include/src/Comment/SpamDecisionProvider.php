<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment;

readonly class SpamDecisionProvider implements SpamDecisionProviderInterface
{
    public function __construct(private SpamDetectorInterface $detector)
    {
    }

    #[\Override]
    public function getVerdict(SpamDetectorComment $comment, string $clientIp): SpamDecision
    {
        $report    = $this->detector->getReport($comment, $clientIp);
        $linkCount = $this->linkCount($comment->text);
        $hasHtml   = $this->hasHtmlTags($comment->text);

        $rejectLinks     = $linkCount > 0 && !$report->isHam();
        $rejectSpam      = $report->isBlatant();
        $forceModeration = $report->isHam() && ($linkCount > 0 || $hasHtml);

        return new SpamDecision($report, $rejectLinks, $rejectSpam, $forceModeration);
    }

    private function linkCount(string $text): int
    {
        $count = preg_match_all('#(https?://\S{2,}?)(?=[\s),\'><\]]|&lt;|&gt;|[.;:](?:\s|$)|$)#u', $text);

        return $count !== false ? $count : 0;
    }

    private function hasHtmlTags(string $text): bool
    {
        return preg_match('#</?[a-z][^>]*>#i', $text) === 1;
    }
}

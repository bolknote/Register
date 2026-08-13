<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Comment;

use Codeception\Test\Unit;
use S2\Cms\Comment\SpamDecision;
use S2\Cms\Comment\SpamDetectorReport;

final class SpamDecisionTest extends Unit
{
    public function testDetectorFailureAlwaysRequiresModeration(): void
    {
        $decision = new SpamDecision(SpamDetectorReport::failed(), false, false, false);

        self::assertTrue($decision->shouldModerate(false));
        self::assertTrue($decision->shouldModerate(true));
    }

    public function testDisabledDetectorFollowsPremoderationSetting(): void
    {
        $decision = new SpamDecision(SpamDetectorReport::disabled(), false, false, false);

        self::assertFalse($decision->shouldModerate(false));
        self::assertTrue($decision->shouldModerate(true));
    }

    public function testHighSoftRiskIsQuarantinedInsteadOfRejected(): void
    {
        $decision = new SpamDecision(SpamDetectorReport::blatant(hardReject: false), false, false, false);

        self::assertFalse($decision->shouldRejectAsSpam());
        self::assertTrue($decision->shouldModerate(false));
    }
}

<?php

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;

final class CommentViewTest extends Unit
{
    public function testUnknownSpamSignalsNeverExposeInternalNames(): void
    {
        $html = $this->renderReasons(json_encode([
            'new_signal_not_yet_translated' => 10,
            'trained_text_model' => 7,
            'rule_15' => 6,
            'fourth_signal' => 5,
        ], JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString('new_signal_not_yet_translated', $html);
        self::assertStringNotContainsString('Spam reason ', $html);
        self::assertStringContainsString('Additional signal', $html);
        self::assertStringContainsString('Looks like marked spam', $html);
        self::assertStringContainsString('Manual rule #15', $html);
        self::assertStringContainsString('+5', $html);
    }

    public function testMissingOrMalformedAssessmentHasNoTechnicalPlaceholder(): void
    {
        foreach ([null, '', 'invalid-json', '{}'] as $value) {
            self::assertSame('—', trim($this->renderReasons($value)));
        }
    }

    private function renderReasons(?string $value): string
    {
        $renderer = new TemplateRenderer(new Translator([
            'Other spam signal' => 'Additional signal',
            'Spam reason trained_text_model' => 'Looks like marked spam',
            'Manual rule' => 'Manual rule',
        ], 'en'));

        return $renderer->render(
            \dirname(__DIR__, 4) . '/_admin/templates/comment/view-spam-reasons.php',
            ['value' => $value],
        );
    }
}

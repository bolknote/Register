<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentId;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ReactionRenderer
{
    public function __construct(
        private ReactionRepository  $repository,
        private TranslatorInterface $translator,
        private string              $basePath,
    ) {
    }

    public function render(ContentId $contentId): string
    {
        return $this->renderState($contentId, $this->repository->state($contentId->value));
    }

    /**
     * @param list<ContentId> $contentIds
     * @return array<string, string>
     */
    public function renderMany(array $contentIds): array
    {
        $indexed = [];
        foreach ($contentIds as $contentId) {
            $indexed[(string)$contentId] = $contentId;
        }

        $states   = $this->repository->states(array_values(array_map(
            static fn(ContentId $contentId): int => $contentId->value,
            $indexed,
        )));
        $rendered = [];
        foreach ($indexed as $key => $contentId) {
            $rendered[$key] = $this->renderState($contentId, $states[$contentId->value]);
        }

        return $rendered;
    }

    private function renderState(ContentId $contentId, ReactionState $state): string
    {
        $pickerId = 'register-reaction-picker-' . $contentId->type->value . '-' . $contentId->value;
        $endpoint = rtrim($this->basePath, '/') . '/_reactions/' . $contentId->type->value . '/' . $contentId->value;
        $chips    = '';
        $picker   = '';

        foreach (ReactionType::cases() as $reaction) {
            $count   = $state->counts[$reaction->value];
            $label   = $this->translator->trans($reaction->labelKey());
            $visible = $reaction === ReactionType::LIKE || $count > 0;
            $chips .= sprintf(
                '<button class="register-reaction-chip%s" type="button" data-reaction="%s" data-count="%d" aria-pressed="false" title="%s"%s>' .
                '<span class="register-reaction-emoji" aria-hidden="true">%s</span>' .
                '<span class="register-reaction-count"%s>%d</span>' .
                '<span class="register-visually-hidden">%s</span></button>',
                $visible ? ' is-visible' : '',
                $reaction->value,
                $count,
                s2_htmlencode($label),
                $visible ? '' : ' hidden',
                $reaction->emoji(),
                $count > 0 ? '' : ' hidden',
                $count,
                s2_htmlencode($label),
            );
            $picker .= sprintf(
                '<button class="register-reaction-choice" type="button" role="menuitemradio" aria-checked="false" data-picker-reaction="%s" title="%s" aria-label="%s"><span aria-hidden="true">%s</span></button>',
                $reaction->value,
                s2_htmlencode($label),
                s2_htmlencode($label),
                $reaction->emoji(),
            );
        }

        $groupLabel  = s2_htmlencode($this->translator->trans('reaction.group'));
        $chooseLabel = s2_htmlencode($this->translator->trans('reaction.choose'));
        $saved       = s2_htmlencode($this->translator->trans('reaction.saved'));
        $error       = s2_htmlencode($this->translator->trans('reaction.error'));

        return sprintf(
            '<div class="register-reactions" data-register-reactions data-endpoint="%s" data-message-saved="%s" data-message-error="%s">' .
            '<div class="register-reaction-toolbar" role="group" aria-label="%s">%s' .
            '<button class="register-reaction-add" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="%s" title="%s" aria-label="%s">' .
            '<span class="register-reaction-add-glyph" aria-hidden="true"><span class="register-reaction-add-emoji">😊</span><span class="register-reaction-add-plus">+</span></span></button>' .
            '</div>' .
            '<div class="register-reaction-picker" id="%s" role="menu" aria-label="%s" hidden>%s</div>' .
            '<p class="register-reaction-status register-visually-hidden" aria-live="polite"></p>' .
            '</div>',
            s2_htmlencode($endpoint),
            $saved,
            $error,
            $groupLabel,
            $chips,
            $pickerId,
            $chooseLabel,
            $chooseLabel,
            $pickerId,
            $chooseLabel,
            $picker,
        );
    }
}

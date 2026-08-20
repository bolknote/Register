<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard\Form;

use S2\AdminYard\Form\Textarea;

class HtmlTextarea extends Textarea
{
    #[\Override]
    public function getHtml(?string $id = null): string
    {
        $escapedFileName = htmlspecialchars($this->fieldName, ENT_QUOTES, 'UTF-8');
        $escapedValue    = htmlspecialchars($this->value, ENT_QUOTES, 'UTF-8');
        $idAttr          = $id !== null ? ' id="' . $id . '"' : '';
        return <<<HTML
<textarea name="{$escapedFileName}"{$idAttr}>{$escapedValue}</textarea>
HTML;
    }

    public function getHtmlWithWrapper(callable $trans, string $id, string $label): string
    {
        $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="toolbar html-toolbar" id="{$id}-toolbar" role="toolbar" aria-label="{$escapedLabel}">
    <span class="toolbar-group toolbar-history">
        <button type="button" class="undo" data-editor-action="undo" title="{$trans('Undo')}" aria-label="{$trans('Undo')}"></button>
        <button type="button" class="redo" data-editor-action="redo" title="{$trans('Redo')}" aria-label="{$trans('Redo')}"></button>
    </span>
    <span class="toolbar-group">
        <button type="button" class="b" title="{$trans('Bold')}" aria-label="{$trans('Bold')}"></button>
        <button type="button" class="i" title="{$trans('Italic')}" aria-label="{$trans('Italic')}"></button>
        <button type="button" class="strike" title="{$trans('Strike')}" aria-label="{$trans('Strike')}"></button>
    </span>
    <span class="toolbar-group">
        <button type="button" class="h2" title="{$trans('Header 2')}" aria-label="{$trans('Header 2')}"></button>
        <button type="button" class="h3" title="{$trans('Header 3')}" aria-label="{$trans('Header 3')}"></button>
        <button type="button" class="h4" title="{$trans('Header 4')}" aria-label="{$trans('Header 4')}"></button>
    </span>
    <span class="toolbar-group">
        <button type="button" class="a" title="{$trans('Link')}" aria-label="{$trans('Link')}"></button>
        <button type="button" class="img" title="{$trans('Media')}" aria-label="{$trans('Media')}"></button>
    </span>
    <span class="toolbar-group">
        <button type="button" class="quote" title="{$trans('Quote')}" aria-label="{$trans('Quote')}"></button>
        <button type="button" class="ul" title="{$trans('UL')}" aria-label="{$trans('UL')}"></button>
        <button type="button" class="ol" title="{$trans('OL')}" aria-label="{$trans('OL')}"></button>
        <button type="button" class="code" title="{$trans('CODE')}" aria-label="{$trans('CODE')}"></button>
    </span>
    <details class="toolbar-more">
        <summary>{$trans('More formatting')}</summary>
        <span class="toolbar-more-menu">
            <button type="button" class="big" title="{$trans('BIG')}" aria-label="{$trans('BIG')}"></button>
            <button type="button" class="small" title="{$trans('SMALL')}" aria-label="{$trans('SMALL')}"></button>
            <button type="button" class="sup" title="{$trans('SUP')}" aria-label="{$trans('SUP')}"></button>
            <button type="button" class="sub" title="{$trans('SUB')}" aria-label="{$trans('SUB')}"></button>
            <button type="button" class="nobr" title="{$trans('NOBR')}" aria-label="{$trans('NOBR')}"></button>
            <span class="separator"></span>
            <button type="button" class="left" title="{$trans('Left')}" aria-label="{$trans('Left')}"></button>
            <button type="button" class="center" title="{$trans('Center')}" aria-label="{$trans('Center')}"></button>
            <button type="button" class="right" title="{$trans('Right')}" aria-label="{$trans('Right')}"></button>
            <button type="button" class="justify" title="{$trans('Justify')}" aria-label="{$trans('Justify')}"></button>
            <span class="separator"></span>
            <button type="button" class="li" title="{$trans('LI')}" aria-label="{$trans('LI')}"></button>
            <button type="button" class="pre" title="{$trans('PRE')}" aria-label="{$trans('PRE')}"></button>
            <button type="button" class="parag" title="{$trans('Paragraphs info')}" aria-label="{$trans('Paragraphs info')}"></button>
        </span>
    </details>
    <button type="button" class="fullscreen" title="{$trans('Fullscreen')}" aria-label="{$trans('Fullscreen')}"></button>
</div>
<div class="html-textarea-with-preview-wrapper">
    <div class="html-textarea-wrapper">
<label class="visually-hidden" for="{$id}">{$escapedLabel}</label>
{$this->getHtml($id)}
    </div>
    <div class="html-preview-wrapper">
        <iframe src="" frameborder="0" class="preview-frame" id="$id-preview-frame" name="$id-preview-frame"></iframe>
    </div>
</div>
HTML;
    }
}

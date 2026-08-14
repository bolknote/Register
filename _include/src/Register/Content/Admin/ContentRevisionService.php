<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

final readonly class ContentRevisionService
{
    /**
     * @param array<string, mixed> $submittedData
     * @param array<string, mixed> $storedData
     * @param non-empty-list<string> $trackedFields
     */
    public function resolve(array $submittedData, array $storedData, array $trackedFields): ?ContentRevision
    {
        $contentChanged = false;
        foreach ($trackedFields as $field) {
            $storedField = 'column_' . $field;
            if (!\array_key_exists($field, $submittedData) || !\array_key_exists($storedField, $storedData)) {
                throw new \LogicException(sprintf('Revision field "%s" is missing.', $field));
            }

            if ($submittedData[$field] !== $storedData[$storedField]) {
                $contentChanged = true;
            }
        }

        if (!\array_key_exists('revision', $submittedData) || !\array_key_exists('column_revision', $storedData)) {
            throw new \LogicException('Revision value is missing.');
        }

        $submittedRevision = $this->normalizeRevision($submittedData['revision']);
        $storedRevision    = $this->normalizeRevision($storedData['column_revision']);

        if ($contentChanged && $submittedRevision !== $storedRevision) {
            return null;
        }

        return new ContentRevision(
            $contentChanged,
            $contentChanged ? (string)((int)$storedRevision + 1) : $storedRevision,
        );
    }

    private function normalizeRevision(mixed $revision): string
    {
        if (\is_int($revision) && $revision >= 0) {
            return (string)$revision;
        }

        if (\is_string($revision) && ctype_digit($revision)) {
            return $revision;
        }

        throw new \LogicException('Revision must be a non-negative integer.');
    }
}

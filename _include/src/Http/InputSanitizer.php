<?php

declare(strict_types = 1);

namespace Register\Core\Http;

final class InputSanitizer
{
    /** @param list<string> $characters */
    public static function removeCharacters(mixed &$data, array $characters): void
    {
        if (is_array($data)) {
            foreach (array_keys($data) as $key) {
                self::removeCharacters($data[$key], $characters);
            }

            return;
        }

        if (is_string($data)) {
            $data = str_replace($characters, '', $data);
        }
    }
}

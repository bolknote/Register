<?php
/**
 * @copyright 2024 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   AdminYard
 */

declare(strict_types = 1);

namespace Register\AdminYard\Form;

use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Helper\RandomHelper;
use Register\AdminYard\SettingStorage\SettingStorageInterface;

/**
 * Security-compatible replacement for the upstream class.
 *
 * AdminYard currently derives tokens with SHA-1. Keeping the same public API
 * here lets every AdminYard form use a keyed SHA-256 MAC without maintaining a
 * fork of the rest of the package.
 */
readonly class FormParams
{
    /**
     * @param array<string, FieldConfig> $fields
     * @param array<string, scalar>      $primaryKey
     */
    public function __construct(
        public string                   $entityName,
        public array                    $fields,
        private SettingStorageInterface $settingStorage,
        private string                  $action,
        private array                   $primaryKey = [],
    ) {
    }

    public function getCsrfToken(): string
    {
        if (!$this->settingStorage->has('main_csrf_token')) {
            $mainToken = RandomHelper::getRandomHexString32();
            $this->settingStorage->set('main_csrf_token', $mainToken);
        } else {
            $mainToken = $this->settingStorage->get('main_csrf_token');
        }

        if (!\is_string($mainToken) || $mainToken === '') {
            throw new \UnexpectedValueException('Invalid main CSRF token.');
        }

        return hash_hmac('sha256', serialize([
            $this->entityName,
            $this->action,
            array_keys($this->fields),
            $this->primaryKey,
        ]), $mainToken);
    }
}

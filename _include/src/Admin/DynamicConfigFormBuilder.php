<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin;

use Register\Ai\AiSettings;
use Register\Module\Analytics\Manifest as AnalyticsManifest;
use Register\Schema\SchemaManager;
use S2\AdminYard\Config\DbColumnFieldType;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Database\TypeTransformer;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Validator\Length;
use S2\AdminYard\Validator\Regex;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gathers information about S2 configuration parameters and transforms the list of configuration parameters,
 * read by the AdminYard library from the database, into a set of mini-forms for editing these parameters.
 */
class DynamicConfigFormBuilder
{
    /**
     * @var array<string, string>|null
     */
    public ?array $paramTypes = null;

    /**
     * S2 parameters. Extensions may add their own parameters via DynamicConfigFormExtenderInterface instances.
     */
    private const array PARAM_TYPES = [
        'Site config'        => 'title',
        'S2_SITE_NAME'       => 'string',
        'S2_WEBMASTER'       => 'string',
        'S2_WEBMASTER_EMAIL' => 'email',
        'S2_START_YEAR'      => 'int',
        'S2_LANGUAGE'        => 'language',
        'S2_STYLE'           => 'style',

        'Comments config'     => 'title',
        'S2_SHOW_COMMENTS'    => 'boolean',
        'S2_ENABLED_COMMENTS' => 'boolean',
        'S2_ANTISPAM_MODE'    => 'antispam_mode',
        'S2_ANTISPAM_SECRET'  => 'hidden',
        'S2_ANTISPAM_SPAM_SCORE' => 'int',
        'S2_ANTISPAM_BLATANT_SCORE' => 'int',
        'S2_AKISMET_KEY'      => 'secret',
        'S2_PREMODERATION'    => 'boolean',

        'Navigation config' => 'title',
        'S2_USE_HIERARCHY'  => 'boolean',
        'S2_MAX_ITEMS'      => 'int',
        'S2_FAVORITE_URL'   => 'string',
        'S2_TAGS_URL'       => 'string',

        'AI config'                         => 'title',
        AiSettings::PROVIDER_CONFIG_KEY     => 'ai_provider',
        AiSettings::API_KEY_CONFIG_KEY      => 'secret',
        AiSettings::MODEL_CONFIG_KEY        => 'string',

        'Admin config'     => 'title',
        'S2_ADMIN_COLOR'   => 'color',
        'S2_ADMIN_NEW_POS' => 'boolean',
        'S2_ADMIN_CUT'     => 'boolean',
        'S2_LOGIN_TIMEOUT' => 'hidden',
        'S2_LAST_MAINTENANCE' => 'hidden',
        SchemaManager::CONFIG_KEY => 'hidden',
        AnalyticsManifest::SALT_CONFIG_KEY => 'hidden',
    ];

    /**
     * @var DynamicConfigFormExtenderInterface[]
     */
    private readonly array $dynamicConfigFormExtenders;

    public function __construct(
        private readonly PermissionChecker       $permissionChecker,
        private readonly TranslatorInterface     $translator,
        private readonly TypeTransformer         $typeTransformer,
        private readonly FormFactory             $formFactory,
        private readonly TemplateRenderer        $templateRenderer,
        private readonly ResourceProvider        $resourceProvider,
        private readonly RequestStack            $requestStack,
        private readonly SettingStorageInterface $settingStorage,
        private readonly DynamicConfigProvider   $dynamicConfigProvider,
        DynamicConfigFormExtenderInterface       ...$dynamicConfigFormExtenders
    ) {
        $this->dynamicConfigFormExtenders = $dynamicConfigFormExtenders;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<int, mixed> $rows
     */
    public function transformConfigTable(string $entityName, array &$header, array &$rows): void
    {
        $paramTypes = $this->getParamTypes();
        foreach ($paramTypes as $paramName => $paramType) {
            if ($paramType === 'title') {
                $rows[] = [
                    'cells' => [
                        'name'  => ['content' => $paramName, 'type' => 'config-title'],
                        'value' => ['content' => '', 'type' => 'string'],
                        'help'  => ['content' => '', 'type' => 'string'],
                    ]
                ];
            }
        }

        $orderArray = array_flip(array_keys($paramTypes));
        usort($rows, static fn(array $row1, array $row2): int => ($orderArray[$row1['cells']['name']['content']] ?? PHP_INT_MAX) <=> ($orderArray[$row2['cells']['name']['content']] ?? PHP_INT_MAX));

        $valFieldName = 'value';
        foreach ($rows as $rowIndex => &$row) {
            $paramName = $row['cells']['name']['content'];
            if (!\is_string($paramName)) {
                unset($rows[$rowIndex]);
                continue;
            }

            if (($paramTypes[$paramName] ?? null) === 'title') {
                $row['cells']['name']['content'] = $this->translator->trans($paramName);
                $row['config'] = [
                    'section_id' => $this->sectionId($paramName),
                ];
                continue;
            }

            // Configuration rows not explicitly declared by the product or a module are internal state.
            // Exposing them as editable text fields made cache generations and schema markers look like
            // ordinary user settings.
            if (!array_key_exists($paramName, $paramTypes) || $paramTypes[$paramName] === 'hidden') {
                unset($rows[$rowIndex]);
                continue;
            }

            $paramType      = $paramTypes[$paramName];
            $field          = $this->createDynamicFieldConfig($paramName);
            $translatedName = $this->translator->trans($paramName);
            $translatedHelp = $this->translator->trans($paramName . '_help');
            $formId         = 'id-config-' . md5(serialize($row['primary_key']) . $valFieldName);
            $controlId      = $formId . '-control';
            $helpId         = $formId . '-help';
            $statusId       = $formId . '-status';
            $storedValue    = (string)$row['cells']['value']['content'];
            $appliedValue   = $this->appliedValue($paramName);
            $isApplied      = $appliedValue === null || $this->valuesMatch($paramType, $storedValue, $appliedValue);

            $row['config'] = [
                'key'         => $paramName,
                'control_id'  => $controlId,
                'help_id'     => $helpId,
                'status_id'   => $statusId,
                'editable'    => $field->inlineEdit,
                'is_applied'  => $isApplied,
                'dependency'  => $this->dependency($paramName),
            ];

            if ($field->inlineEdit) {
                if (!$field->type instanceof DbColumnFieldType) {
                    throw new \LogicException('Inline configuration fields must be backed by a database column.');
                }

                $form = $this->formFactory->createEntityForm(new FormParams(
                    $entityName,
                    [$valFieldName => $field],
                    $this->settingStorage,
                    'patch',
                    $row['primary_key'],
                ));
                $form->fillFromArray([
                    $valFieldName => $paramType === 'secret'
                        ? ''
                        : $this->typeTransformer->normalizedFromDb($row['cells']['value']['content'], $field->type->dataType),
                ]);

                $row['cells']['value']['content'] = $this->templateRenderer->render($field->inlineFormTemplate, [
                    'value'      => $row['cells']['value']['content'],
                    'form'       => $form,
                    'entityName' => $entityName,
                    'fieldName'  => $valFieldName,
                    'primaryKey' => $row['primary_key'],
                    'formId'     => $formId,
                    'controlId'  => $controlId,
                    'helpId'     => $helpId,
                    'statusId'   => $statusId,
                    'isApplied'  => $isApplied,
                ]);
            } elseif ($paramType === 'secret') {
                $row['cells']['value']['content'] = $this->translator->trans(
                    trim($storedValue) !== '' ? 'Secret configured' : 'Secret not configured',
                );
            }

            $row['cells']['name']['content'] = $translatedName;
            $row['cells']['help']            = [
                'content' => $translatedHelp,
                'type'    => FieldConfig::DATA_TYPE_STRING,
            ];
        }

        unset($row);

        $header['help'] = $this->translator->trans('Help');
    }

    public function getValueFieldConfig(): FieldConfig
    {
        $request = $this->requestStack->getMainRequest();
        if ($request instanceof \Symfony\Component\HttpFoundation\Request && $request->query->get('action') === 'patch' && $request->query->get('field') === 'value') {
            // Polymorphic field config for form processing in AdminYard.
            // Datatype and control are selected based on the parameter name.
            return $this->createDynamicFieldConfig($request->query->getString('name'));
        }

        // Fake field config for AdminYard on the list screen.
        // Real field configs will be generated in self::transformConfigTable().
        return new FieldConfig(name: 'value');
    }

    public function isSecretParameter(string $paramName): bool
    {
        return ($this->getParamTypes()[$paramName] ?? null) === 'secret';
    }

    private function createDynamicFieldConfig(string $paramName): FieldConfig
    {
        $inlineEdit = $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS);

        return match ($this->getParamTypes()[$paramName] ?? 'string') {
            'string' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'input',
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'email' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'input',
                validators: [
                    (static function (): \S2\AdminYard\Validator\Regex {
                        $validator          = new Regex('/^(([^<>()[\]\\.,;:\s@"\']+(\.[^<>()[\]\\.,;:\s@"\']+)*)|("[^"\']+"))@((\[\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\])|(([a-zA-Z\d\-]+\.)+[a-zA-Z]{2,}))$/');
                        $validator->message = 'Invalid webmaster email';
                        return $validator;
                    })(),
                    new Length(max: 80),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'int' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                control: 'int_input',
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'boolean' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'color' => new FieldConfig(
                'value',
                control: 'color_input',
                options: ['#eeeeee', '#f5e6e6', '#f5ece6', '#f5f0e6', '#edf5e6', '#e6f5ed', '#e6f3f5', '#e6edf5', '#e8e6f5', '#ede6f5'],
                validators: [
                    (static function (): Regex {
                        $validator = new Regex(AdminThemeStylesheet::COLOR_PATTERN);
                        $validator->message = 'Invalid admin color';

                        return $validator;
                    })(),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'language' => new FieldConfig(
                'value',
                control: 'select',
                options: $this->resourceProvider->readLanguageOptions($this->translator->getLocale()),
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'style' => new FieldConfig(
                'value',
                control: 'select',
                options: $this->resourceProvider->readStyleOptions($this->translator->getLocale()),
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'antispam_mode' => new FieldConfig(
                'value',
                control: 'select',
                options: [
                    'local'   => $this->translator->trans('Local filter'),
                    'shadow'  => $this->translator->trans('Shadow comparison'),
                    'akismet' => 'Akismet',
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'ai_provider' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'select',
                options: [
                    AiSettings::PROVIDER_DISABLED => $this->translator->trans('AI disabled'),
                    AiSettings::PROVIDER_GEMINI   => 'Gemini',
                    AiSettings::PROVIDER_GROQ     => 'Groq',
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/ai-provider-inline.php.inc',
            ),
            'secret' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'password',
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/secret-inline.php.inc',
            ),
            default => throw new \LogicException(\sprintf('Unsupported dynamic configuration field type for "%s".', $paramName)),
        };
    }

    /**
     * @return array<mixed>
     */
    private function getParamTypes(): array
    {
        return $this->paramTypes ?? array_merge(
            self::PARAM_TYPES,
            ...array_map(
                static fn(DynamicConfigFormExtenderInterface $extender): array => $extender->getExtraParamTypes(),
                $this->dynamicConfigFormExtenders
            )
        );
    }

    private function sectionId(string $title): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));

        return 'settings-' . ($slug !== '' ? $slug : substr(md5($title), 0, 10));
    }

    private function appliedValue(string $paramName): ?string
    {
        try {
            return (string)$this->dynamicConfigProvider->get($paramName);
        } catch (\LogicException) {
            return null;
        }
    }

    private function valuesMatch(string $paramType, string $storedValue, string $appliedValue): bool
    {
        if ($paramType === 'secret') {
            return (trim($storedValue) !== '') === (trim($appliedValue) !== '');
        }

        return $storedValue === $appliedValue;
    }

    /**
     * @return array{key: string, values: list<string>}|null
     */
    private function dependency(string $paramName): ?array
    {
        return match ($paramName) {
            AiSettings::API_KEY_CONFIG_KEY,
            AiSettings::MODEL_CONFIG_KEY => [
                'key'    => AiSettings::PROVIDER_CONFIG_KEY,
                'values' => [AiSettings::PROVIDER_GEMINI, AiSettings::PROVIDER_GROQ],
            ],
            'S2_ANTISPAM_SPAM_SCORE',
            'S2_ANTISPAM_BLATANT_SCORE' => [
                'key'    => 'S2_ANTISPAM_MODE',
                'values' => ['local', 'shadow'],
            ],
            'S2_AKISMET_KEY' => [
                'key'    => 'S2_ANTISPAM_MODE',
                'values' => ['shadow', 'akismet'],
            ],
            default => null,
        };
    }
}

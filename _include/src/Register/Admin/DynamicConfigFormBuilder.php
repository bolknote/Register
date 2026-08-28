<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin;

use Register\Ai\AiSettings;
use Register\Auth\PublicAuthSettings;
use Register\Module\Analytics\Manifest as AnalyticsManifest;
use Register\Schema\SchemaManager;
use Register\AdminYard\Config\DbColumnFieldType;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Validator\Length;
use Register\AdminYard\Validator\Regex;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Core\Mail\MailSettings;
use Register\Admin\Validator\IntegerRange;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gathers information about Register configuration parameters and transforms the list of configuration parameters,
 * read by the AdminYard library from the database, into a set of mini-forms for editing these parameters.
 */
class DynamicConfigFormBuilder
{
    /**
     * @var array<string, string>|null
     */
    public ?array $paramTypes = null;

    /**
     * Register parameters. Extensions may add their own parameters via DynamicConfigFormExtenderInterface instances.
     */
    private const array PARAM_TYPES = [
        'Site config'        => 'title',
        'REGISTER_SITE_NAME'       => 'string',
        'REGISTER_SITE_TAGLINE'    => 'string',
        'REGISTER_SOCIAL_IMAGE'    => 'string',
        'REGISTER_WEBMASTER'       => 'string',
        'REGISTER_WEBMASTER_EMAIL' => 'email',
        'REGISTER_START_YEAR'      => 'int',
        'REGISTER_LANGUAGE'        => 'language',
        'REGISTER_STYLE'           => 'style',

        'Mail config'                         => 'title',
        MailSettings::TRANSPORT_CONFIG_KEY    => 'mail_transport',
        MailSettings::FROM_NAME_CONFIG_KEY    => 'string',
        MailSettings::FROM_EMAIL_CONFIG_KEY   => 'email',
        MailSettings::ENVELOPE_EMAIL_CONFIG_KEY => 'optional_email',
        MailSettings::REPLY_TO_CONFIG_KEY     => 'optional_email',
        MailSettings::SMTP_HOST_CONFIG_KEY    => 'string',
        MailSettings::SMTP_PORT_CONFIG_KEY    => 'smtp_port',
        MailSettings::SMTP_ENCRYPTION_CONFIG_KEY => 'mail_encryption',
        MailSettings::SMTP_USERNAME_CONFIG_KEY => 'string',
        MailSettings::SMTP_PASSWORD_CONFIG_KEY => 'secret',
        MailSettings::TIMEOUT_CONFIG_KEY      => 'mail_timeout',
        MailSettings::PHP_ENVELOPE_CONFIG_KEY => 'boolean',
        MailSettings::DKIM_SELECTOR_CONFIG_KEY => 'string',
        MailSettings::DKIM_DOMAIN_CONFIG_KEY  => 'string',
        MailSettings::DKIM_PRIVATE_KEY_CONFIG_KEY => 'secret',

        'Syndication config' => 'title',
        FeedSettings::ITEM_LIMIT_CONFIG_KEY => 'feed_limit',

        'Comments config'     => 'title',
        'REGISTER_SHOW_COMMENTS'    => 'boolean',
        'REGISTER_ENABLED_COMMENTS' => 'boolean',
        'REGISTER_ANTISPAM_MODE'    => 'antispam_mode',
        'REGISTER_ANTISPAM_SECRET'  => 'hidden',
        'REGISTER_ANTISPAM_SPAM_SCORE' => 'int',
        'REGISTER_ANTISPAM_BLATANT_SCORE' => 'int',
        'REGISTER_AKISMET_KEY'      => 'secret',
        'REGISTER_PREMODERATION'    => 'boolean',

        'Navigation config' => 'title',
        'REGISTER_USE_HIERARCHY'  => 'boolean',
        'REGISTER_MAX_ITEMS'      => 'int',
        'REGISTER_FAVORITE_URL'   => 'string',
        'REGISTER_TAGS_URL'       => 'string',

        'AI config'                         => 'title',
        AiSettings::PROVIDER_CONFIG_KEY     => 'ai_provider',
        AiSettings::API_KEY_CONFIG_KEY      => 'secret',
        AiSettings::FOLDER_ID_CONFIG_KEY    => 'string',
        AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => 'string',
        AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => 'gigachat_scope',
        AiSettings::MODEL_CONFIG_KEY        => 'string',
        AiSettings::AUTO_ALT_CONFIG_KEY     => 'boolean',
        AiSettings::AUTO_METADATA_CONFIG_KEY => 'boolean',

        'Authentication config'                         => 'title',
        PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY    => 'boolean',
        PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY     => 'string',
        PublicAuthSettings::YANDEX_CLIENT_ID_CONFIG_KEY => 'string',
        PublicAuthSettings::YANDEX_CLIENT_SECRET_CONFIG_KEY => 'secret',

        'Admin config'     => 'title',
        'REGISTER_ADMIN_COLOR'   => 'color',
        'REGISTER_ADMIN_NEW_POS' => 'boolean',
        'REGISTER_ADMIN_CUT'     => 'boolean',
        'REGISTER_LOGIN_TIMEOUT' => 'hidden',
        'REGISTER_LAST_MAINTENANCE' => 'hidden',
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
        private readonly UrlBuilder              $urlBuilder,
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
                'callback_urls' => $this->oauthCallbackUrls($paramName),
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
                    (static function (): \Register\AdminYard\Validator\Regex {
                        $validator          = new Regex('/^(([^<>()[\]\\.,;:\s@"\']+(\.[^<>()[\]\\.,;:\s@"\']+)*)|("[^"\']+"))@((\[\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\])|(([a-zA-Z\d\-]+\.)+[a-zA-Z]{2,}))$/');
                        $validator->message = 'Invalid email address';
                        return $validator;
                    })(),
                    new Length(max: 254),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'optional_email' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'input',
                validators: [
                    (static function (): \Register\AdminYard\Validator\Regex {
                        $validator = new Regex('/^(?:|(([^<>()[\]\\.,;:\s@"\']+(\.[^<>()[\]\\.,;:\s@"\']+)*)|("[^"\']+"))@((\[\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\])|(([a-zA-Z\d\-]+\.)+[a-zA-Z]{2,})))$/');
                        $validator->message = 'Invalid email address';
                        return $validator;
                    })(),
                    new Length(max: 254),
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
            'feed_limit' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                control: 'int_input',
                validators: [new IntegerRange(FeedSettings::MIN_ITEM_LIMIT, FeedSettings::MAX_ITEM_LIMIT)],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'smtp_port' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                control: 'int_input',
                validators: [new IntegerRange(1, 65535)],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'mail_timeout' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                control: 'int_input',
                validators: [new IntegerRange(1, 30)],
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
                    AiSettings::PROVIDER_OPENROUTER => 'OpenRouter',
                    AiSettings::PROVIDER_MISTRAL  => 'Mistral',
                    AiSettings::PROVIDER_CLOUDFLARE => 'Cloudflare Workers AI',
                    AiSettings::PROVIDER_YANDEX   => 'Yandex AI Studio',
                    AiSettings::PROVIDER_GIGACHAT => 'GigaChat',
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/ai-provider-inline.php.inc',
            ),
            'mail_transport' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'select',
                options: [
                    MailSettings::TRANSPORT_AUTO => $this->translator->trans('Mail transport auto'),
                    MailSettings::TRANSPORT_SMTP => 'SMTP',
                    MailSettings::TRANSPORT_PHP_MAIL => 'PHP mail()',
                    MailSettings::TRANSPORT_DISABLED => $this->translator->trans('Mail disabled'),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'mail_encryption' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'select',
                options: [
                    MailSettings::ENCRYPTION_STARTTLS => 'STARTTLS',
                    MailSettings::ENCRYPTION_TLS => 'TLS (SMTPS)',
                    MailSettings::ENCRYPTION_NONE => $this->translator->trans('No encryption'),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
            ),
            'secret' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'password',
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/secret-inline.php.inc',
            ),
            'gigachat_scope' => new FieldConfig(
                'value',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_STRING),
                control: 'select',
                options: [
                    AiSettings::GIGACHAT_SCOPE_PERSONAL => $this->translator->trans('GigaChat personal scope'),
                    AiSettings::GIGACHAT_SCOPE_BUSINESS => $this->translator->trans('GigaChat business scope'),
                    AiSettings::GIGACHAT_SCOPE_CORPORATE => $this->translator->trans('GigaChat corporate scope'),
                ],
                inlineEdit: $inlineEdit,
                inlineFormTemplate: '_admin/templates/config/inline.php.inc',
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

    /** @return list<string> */
    private function oauthCallbackUrls(string $paramName): array
    {
        $providers = match ($paramName) {
            PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY => ['vk', 'mail_ru', 'ok_ru'],
            PublicAuthSettings::YANDEX_CLIENT_ID_CONFIG_KEY => ['yandex'],
            default => [],
        };

        return array_map(
            fn(string $provider): string => html_entity_decode(
                $this->urlBuilder->absLink('/auth/oauth/' . $provider . '/callback'),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ),
            $providers,
        );
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
            AiSettings::MODEL_CONFIG_KEY,
            AiSettings::AUTO_ALT_CONFIG_KEY,
            AiSettings::AUTO_METADATA_CONFIG_KEY => [
                'key'    => AiSettings::PROVIDER_CONFIG_KEY,
                'values' => [
                    AiSettings::PROVIDER_GEMINI,
                    AiSettings::PROVIDER_GROQ,
                    AiSettings::PROVIDER_OPENROUTER,
                    AiSettings::PROVIDER_MISTRAL,
                    AiSettings::PROVIDER_CLOUDFLARE,
                    AiSettings::PROVIDER_YANDEX,
                    AiSettings::PROVIDER_GIGACHAT,
                ],
            ],
            AiSettings::FOLDER_ID_CONFIG_KEY => [
                'key'    => AiSettings::PROVIDER_CONFIG_KEY,
                'values' => [AiSettings::PROVIDER_YANDEX],
            ],
            AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => [
                'key'    => AiSettings::PROVIDER_CONFIG_KEY,
                'values' => [AiSettings::PROVIDER_CLOUDFLARE],
            ],
            AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => [
                'key'    => AiSettings::PROVIDER_CONFIG_KEY,
                'values' => [AiSettings::PROVIDER_GIGACHAT],
            ],
            'REGISTER_ANTISPAM_SPAM_SCORE',
            'REGISTER_ANTISPAM_BLATANT_SCORE' => [
                'key'    => 'REGISTER_ANTISPAM_MODE',
                'values' => ['local', 'shadow'],
            ],
            'REGISTER_AKISMET_KEY' => [
                'key'    => 'REGISTER_ANTISPAM_MODE',
                'values' => ['shadow', 'akismet'],
            ],
            MailSettings::SMTP_HOST_CONFIG_KEY,
            MailSettings::SMTP_PORT_CONFIG_KEY,
            MailSettings::SMTP_ENCRYPTION_CONFIG_KEY,
            MailSettings::SMTP_USERNAME_CONFIG_KEY,
            MailSettings::SMTP_PASSWORD_CONFIG_KEY,
            MailSettings::TIMEOUT_CONFIG_KEY => [
                'key'    => MailSettings::TRANSPORT_CONFIG_KEY,
                'values' => [MailSettings::TRANSPORT_AUTO, MailSettings::TRANSPORT_SMTP],
            ],
            MailSettings::PHP_ENVELOPE_CONFIG_KEY => [
                'key'    => MailSettings::TRANSPORT_CONFIG_KEY,
                'values' => [MailSettings::TRANSPORT_AUTO, MailSettings::TRANSPORT_PHP_MAIL],
            ],
            default => null,
        };
    }
}

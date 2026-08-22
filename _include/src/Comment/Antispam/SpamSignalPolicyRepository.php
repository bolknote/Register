<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamSignalPolicyRepository
{
    /** @var array<string, int> */
    public const array DEFAULT_WEIGHTS = [
        'links_one'                  => 10,
        'links_two'                  => 20,
        'links_many'                 => 35,
        'multiple_domains'           => 10,
        'short_link_comment'         => 15,
        'formatting_controls'        => 20,
        'long_repetition'            => 10,
        'sentence_like_latin_transliteration' => 40,
        'trained_text_model'         => 45,
        'missing_user_agent'         => 5,
        'missing_referrer'           => 3,
        'very_fast_submission'       => 20,
        'confirmed_spam_duplicate'   => 100,
        'possible_spam_duplicate'    => 50,
        'known_ham_text'             => -25,
        'email_reputation'           => 30,
        'trusted_email'              => -20,
        'ip_reputation'              => 25,
        'trusted_ip'                 => -10,
        'domain_reputation_confirmed' => 40,
        'domain_reputation_possible' => 15,
        'trusted_domain'             => -10,
    ];

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * A null weight means that the signal is disabled.
     *
     * @return array<string, int|null>
     * @throws DbLayerException
     */
    public function getWeights(): array
    {
        $weights = $this->getDefaultWeights();
        $rows = $this->dbLayer
            ->select('signal_code', 'weight', 'enabled')
            ->from('spam_signal_policies')
            ->execute()
            ->fetchAssocAll()
        ;

        foreach ($rows as $row) {
            $signal = (string)$row['signal_code'];
            if (!\array_key_exists($signal, self::DEFAULT_WEIGHTS)) {
                continue;
            }

            $weights[$signal] = (bool)$row['enabled']
                ? max(-100, min(100, (int)$row['weight']))
                : null;
        }

        return $weights;
    }

    /** @return array<string, int|null> */
    private function getDefaultWeights(): array
    {
        return self::DEFAULT_WEIGHTS;
    }
}

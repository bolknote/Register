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

final readonly class SpamRuleRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @param list<string> $domains
     * @throws DbLayerException
     */
    public function evaluate(string $text, string $email, array $domains): SpamRuleResult
    {
        $rules = $this->dbLayer
            ->select('id', 'type', 'pattern', 'weight', 'action')
            ->from('spam_rules')
            ->where('enabled = 1')
            ->andWhere('(expires_at IS NULL OR expires_at = 0 OR expires_at >= :now)')->setParameter('now', time())
            ->execute()
            ->fetchAssocAll()
        ;

        $score     = 0;
        $reasons   = [];
        $hardBlock = false;
        $emailDomain = strrchr(mb_strtolower($email), '@');
        $emailDomain = $emailDomain === false ? '' : substr($emailDomain, 1);

        foreach ($rules as $rule) {
            $pattern = trim(mb_strtolower((string)$rule['pattern']));
            if ($pattern === '') {
                continue;
            }

            $matched = match ($rule['type']) {
                'domain' => \in_array(trim($pattern, '.'), $domains, true),
                'email_domain' => $emailDomain === trim($pattern, '.'),
                'phrase' => str_contains(mb_strtolower($text), $pattern),
                default => false,
            };
            if (!$matched) {
                continue;
            }

            $weight = (int)$rule['weight'];
            $score += $weight;
            $reasons['rule_' . (int)$rule['id']] = $weight;
            $hardBlock = $hardBlock || $rule['action'] === 'block';
        }

        return new SpamRuleResult($score, $reasons, $hardBlock);
    }
}

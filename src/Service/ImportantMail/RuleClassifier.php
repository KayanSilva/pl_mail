<?php

declare(strict_types=1);

namespace App\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;

/**
 * Pure rule-based classifier that decides whether an incoming message is
 * high-priority without hitting any language model or network endpoint.
 *
 * Precedence: Family > Government > Bill.
 */
final readonly class RuleClassifier
{
    /**
     * @param list<string> $govDomains      suffixes to match against host (e.g. 'gov.br')
     * @param list<string> $govSenderWords  substrings to look for in the from address
     * @param list<string> $billWords       substrings in subject indicating a bill/charge
     * @param list<string> $billSenderWords substrings in from address indicating bank/utility
     */
    public function __construct(
        private array $govDomains,
        private array $govSenderWords,
        private array $billWords,
        private array $billSenderWords,
    ) {
    }

    public function classify(
        string $fromAddress,
        ?string $subject,
        ?string $bodyText,
        bool $isFamily,
    ): ?ImportanceKind {
        if ($isFamily) {
            return ImportanceKind::Family;
        }

        $fromClean    = mb_strtolower(trim($fromAddress));
        $subjectClean = null !== $subject ? mb_strtolower($subject) : '';

        // 1. Government: host ends with @gov.br or .gov.br, or known sender word
        $host = '';
        $at   = strrpos($fromClean, '@');
        if (false !== $at) {
            $host = substr($fromClean, $at + 1);
        }

        foreach ($this->govDomains as $domain) {
            $domainClean = mb_strtolower(trim($domain));
            if ($host === $domainClean || str_ends_with($host, '.' . $domainClean)) {
                return ImportanceKind::Government;
            }
        }

        foreach ($this->govSenderWords as $word) {
            if (str_contains($fromClean, mb_strtolower($word))) {
                return ImportanceKind::Government;
            }
        }

        // 2. Bill: bank/utility in sender OR bill keyword in subject
        foreach ($this->billSenderWords as $word) {
            if (str_contains($fromClean, mb_strtolower($word))) {
                return ImportanceKind::Bill;
            }
        }

        if ('' !== $subjectClean) {
            foreach ($this->billWords as $word) {
                if (str_contains($subjectClean, mb_strtolower($word))) {
                    return ImportanceKind::Bill;
                }
            }
        }

        // Body alone never promotes to Bill (avoids false-positive newsletters)
        return null;
    }
}

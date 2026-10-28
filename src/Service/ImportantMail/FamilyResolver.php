<?php

declare(strict_types=1);

namespace App\Service\ImportantMail;

use App\Domain\Interface\FamilyResolverInterface;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Maps family members from the user's contacts by looking for Google Contact
 * tags (e.g. "Family", "Família").
 *
 * A contact must be tagged with one of the configured names (case-insensitive)
 * and have at least one email address to contribute to the family list.
 */
final readonly class FamilyResolver implements FamilyResolverInterface
{
    /** @var list<string> normalized (lowercase) tag names */
    private array $normalizedTags;

    /**
     * @param list<string> $familyTags tag names to treat as family
     */
    public function __construct(
        private ContactRepository $contacts,
        array $familyTags = ['Family', 'Família'],
    ) {
        $this->normalizedTags = array_map(
            static fn (string $t): string => mb_strtolower(trim($t)),
            $familyTags,
        );
    }

    /**
     * Set of lowercased emails belonging to family contacts.
     *
     * @return array<string, true> email => true
     */
    public function emailsFor(User $user): array
    {
        $emails = [];

        foreach ($this->loadFamilyContacts($user) as $contact) {
            /** @var ContactEmail $email */
            foreach ($contact->emails as $email) {
                $clean = mb_strtolower(trim($email->email));
                if ('' !== $clean) {
                    $emails[$clean] = true;
                }
            }
        }

        return $emails;
    }

    /**
     * Whether the user has at least one contact tagged as family
     * (regardless of whether that contact has an email stored).
     *
     * Used by the 06h digest to warn if the tag was misnamed or no contact
     * was tagged, avoiding silent false negatives.
     */
    public function hasAnyFamilyContact(User $user): bool
    {
        return [] !== $this->loadFamilyContacts($user);
    }

    /**
     * @return list<Contact>
     */
    private function loadFamilyContacts(User $user): array
    {
        // ContactRepository::findForUser loads contacts for the user. We filter
        // in PHP because tags live in a JSON column across different drivers.
        $all = $this->contacts->findForUser(
            user: $user,
            search: null,
            tag: null,
            sort: 'name',
            limit: 5000,
            offset: 0,
        );

        $family = [];

        foreach ($all as $contact) {
            foreach ($contact->tags as $tag) {
                if (\in_array(mb_strtolower(trim($tag)), $this->normalizedTags, true)) {
                    $family[] = $contact;
                    break;
                }
            }
        }

        return $family;
    }
}

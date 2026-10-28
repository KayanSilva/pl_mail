<?php

declare(strict_types=1);

namespace App\Tests\Service\ImportantMail;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\ImportantMail\FamilyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Resolves family members by inspecting contacts bearing specific tags
 * (e.g. "Family", "Família") synced from Google Contacts.
 */
final class FamilyResolverTest extends TestCase
{
    public function testContactWithFamilyTagContributesAllItsEmailsLowercased(): void
    {
        $user        = new User();
        $user->email = 'kayan@example.com';

        $contact1       = Contact::create($user, 'Mãe');
        $contact1->tags = ['Family', 'Starred'];
        $this->addEmail($contact1, 'MAE@gmail.com');
        $this->addEmail($contact1, 'mae.trabalho@empresa.com');

        $contact2       = Contact::create($user, 'Irmão');
        $contact2->tags = ['Família'];
        $this->addEmail($contact2, 'irmao@hotmail.com');

        $contact3       = Contact::create($user, 'Chefe');
        $contact3->tags = ['Work'];
        $this->addEmail($contact3, 'chefe@empresa.com');

        $repo = $this->createMock(ContactRepository::class);
        $repo->method('findForUser')->willReturn([$contact1, $contact2, $contact3]);

        $resolver = new FamilyResolver($repo, ['Family', 'Família']);

        $emails = $resolver->emailsFor($user);

        self::assertTrue($resolver->hasAnyFamilyContact($user));
        self::assertArrayHasKey('mae@gmail.com', $emails);
        self::assertArrayHasKey('mae.trabalho@empresa.com', $emails);
        self::assertArrayHasKey('irmao@hotmail.com', $emails);
        self::assertArrayNotHasKey('chefe@empresa.com', $emails);
    }

    public function testTagMatchIsCaseInsensitive(): void
    {
        $user        = new User();
        $user->email = 'kayan@example.com';

        $contact       = Contact::create($user, 'Pai');
        $contact->tags = ['family']; // lower case
        $this->addEmail($contact, 'pai@uol.com.br');

        $repo = $this->createMock(ContactRepository::class);
        $repo->method('findForUser')->willReturn([$contact]);

        $resolver = new FamilyResolver($repo, ['Family']);

        self::assertTrue($resolver->hasAnyFamilyContact($user));
        self::assertArrayHasKey('pai@uol.com.br', $resolver->emailsFor($user));
    }

    public function testContactWithoutTagOrWithoutEmailDoesNotContribute(): void
    {
        $user        = new User();
        $user->email = 'kayan@example.com';

        $withoutEmail       = Contact::create($user, 'Primo');
        $withoutEmail->tags = ['Family']; // tagged, but no email stored

        $withoutTag = Contact::create($user, 'Vizinho');
        $withoutTag->tags = [];
        $this->addEmail($withoutTag, 'vizinho@gmail.com');

        $repo = $this->createMock(ContactRepository::class);
        $repo->method('findForUser')->willReturn([$withoutEmail, $withoutTag]);

        $resolver = new FamilyResolver($repo, ['Family']);

        self::assertTrue($resolver->hasAnyFamilyContact($user)); // contact exists
        self::assertSame([], $resolver->emailsFor($user));       // but no email
    }

    public function testNoFamilyContactReportsFalse(): void
    {
        $user        = new User();
        $user->email = 'kayan@example.com';

        $contact       = Contact::create($user, 'Dentista');
        $contact->tags = ['Saude'];

        $repo = $this->createMock(ContactRepository::class);
        $repo->method('findForUser')->willReturn([$contact]);

        $resolver = new FamilyResolver($repo, ['Family', 'Família']);

        self::assertFalse($resolver->hasAnyFamilyContact($user));
        self::assertSame([], $resolver->emailsFor($user));
    }

    private function addEmail(Contact $contact, string $address): void
    {
        $email = new ContactEmail();
        $email->email = $address;
        $contact->emails->add($email);
    }
}

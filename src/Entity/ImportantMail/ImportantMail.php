<?php

declare(strict_types=1);

namespace App\Entity\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Entity\Mail\Message;
use App\Repository\ImportantMail\ImportantMailRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Persisted record of an incoming message that was classified as high-priority
 * (government, bill, or family).
 *
 * One row per message (unique on message_id). The notified_at column records
 * whether the immediate Telegram alert has already been dispatched, avoiding
 * duplicate sends while keeping the item pending for the 06h morning digest
 * until the message is marked seen and archived.
 */
#[ORM\Entity(repositoryClass: ImportantMailRepository::class)]
#[ORM\Table(name: 'important_mail')]
class ImportantMail
{
    public const string SOURCE_RULES = 'rules';
    public const string SOURCE_MODEL = 'model';

    private const array VALID_SOURCES = [self::SOURCE_RULES, self::SOURCE_MODEL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\OneToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', unique: true, nullable: false, onDelete: 'CASCADE')]
    public private(set) Message $message;

    #[ORM\Column(length: 32, enumType: ImportanceKind::class)]
    public private(set) ImportanceKind $kind;

    #[ORM\Column(length: 16)]
    public private(set) string $source;

    #[ORM\Column(type: Types::TEXT)]
    public private(set) string $reason;

    #[ORM\Column(name: 'classified_at')]
    public private(set) DateTimeImmutable $classifiedAt;

    #[ORM\Column(name: 'notified_at', nullable: true)]
    public private(set) ?DateTimeImmutable $notifiedAt = null;

    public static function create(
        Message $message,
        ImportanceKind $kind,
        string $source,
        string $reason,
        ?DateTimeImmutable $classifiedAt = null,
    ): self {
        if (!\in_array($source, self::VALID_SOURCES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid source "%s"; must be one of: %s',
                $source,
                implode(', ', self::VALID_SOURCES),
            ));
        }

        $record               = new self();
        $record->message      = $message;
        $record->kind         = $kind;
        $record->source       = $source;
        $record->reason       = $reason;
        $record->classifiedAt = $classifiedAt ?? new DateTimeImmutable();

        return $record;
    }

    public function markNotified(DateTimeImmutable $at): void
    {
        if (null === $this->notifiedAt) {
            $this->notifiedAt = $at;
        }
    }
}

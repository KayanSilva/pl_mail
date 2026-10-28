<?php

declare(strict_types=1);

namespace App\Repository\ImportantMail;

use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportantMail>
 */
class ImportantMailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportantMail::class);
    }

    public function findByMessage(Message $message): ?ImportantMail
    {
        return $this->findOneBy(['message' => $message]);
    }

    /**
     * All important messages that still require reading or action.
     *
     * A message is "resolved" only when it has been SEEN and taken out of
     * the Inbox (or trashed/spammed). Seen-but-still-in-inbox is pending;
     * archived-without-being-seen is also pending.
     *
     * @return list<ImportantMail>
     */
    public function findUnresolvedFor(User $user): array
    {
        /** @var list<ImportantMail> $all */
        $all = $this->createQueryBuilder('im')
            ->join('im.message', 'm')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('im.classifiedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $unresolved = [];

        foreach ($all as $item) {
            $msg = $item->message;

            // Trashed or spammed messages are considered closed
            if ($msg->isSetAside()) {
                continue;
            }

            // Resolved = seen AND archived (no longer in Inbox)
            $isSeen     = null !== $msg->seenAt;
            $isInInbox  = $msg->isInInbox();
            $isResolved = $isSeen && !$isInInbox;

            if (!$isResolved) {
                $unresolved[] = $item;
            }
        }

        return $unresolved;
    }

    /**
     * Important messages that have not been notified yet (notifiedAt IS NULL)
     * and are still active in Inbox and unseen.
     *
     * @return list<ImportantMail>
     */
    public function findUnnotifiedFor(User $user): array
    {
        /** @var list<ImportantMail> $all */
        $all = $this->createQueryBuilder('im')
            ->join('im.message', 'm')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('im.notifiedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('im.classifiedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $unnotified = [];
        foreach ($all as $item) {
            $msg = $item->message;
            if ($msg->isSetAside() || null !== $msg->seenAt || !$msg->isInInbox()) {
                continue;
            }
            $unnotified[] = $item;
        }

        return $unnotified;
    }
}

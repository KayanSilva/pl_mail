<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * High-priority mail flagged for immediate Telegram alerts and 06h morning digest.
 *
 * One row per message (FK with CASCADE delete).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create important_mail table for Telegram alerts and morning digest';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE important_mail (
            id SERIAL PRIMARY KEY,
            message_id INT NOT NULL,
            kind VARCHAR(32) NOT NULL,
            source VARCHAR(16) NOT NULL,
            reason TEXT NOT NULL,
            classified_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT uniq_important_mail_message UNIQUE (message_id),
            CONSTRAINT fk_important_mail_message FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_important_mail_classified_at ON important_mail (classified_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE important_mail');
    }
}

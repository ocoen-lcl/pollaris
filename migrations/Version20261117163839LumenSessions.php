<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117163839LumenSessions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lumen Care: session thresholds, confirmations and participant emails';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE answer ADD confirmation_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE poll ADD confirm_threshold INT DEFAULT NULL');
        $this->addSql('ALTER TABLE poll ADD session_group VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE proposal ADD confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE proposal ADD threshold_notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE proposal ADD blocked_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE594721DA661F8 FOREIGN KEY (blocked_by_id) REFERENCES proposal (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BFE594721DA661F8 ON proposal (blocked_by_id)');
        $this->addSql('ALTER TABLE vote ADD author_email VARCHAR(255) DEFAULT NULL');
        // Lumen Care: existing polls are training sessions of the same group.
        $this->addSql("UPDATE poll SET confirm_threshold = 4, session_group = 'formation'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE answer DROP confirmation_sent_at');
        $this->addSql('ALTER TABLE poll DROP confirm_threshold');
        $this->addSql('ALTER TABLE poll DROP session_group');
        $this->addSql('ALTER TABLE proposal DROP CONSTRAINT FK_BFE594721DA661F8');
        $this->addSql('DROP INDEX IDX_BFE594721DA661F8');
        $this->addSql('ALTER TABLE proposal DROP confirmed_at');
        $this->addSql('ALTER TABLE proposal DROP threshold_notified_at');
        $this->addSql('ALTER TABLE proposal DROP blocked_by_id');
        $this->addSql('ALTER TABLE vote DROP author_email');
    }
}

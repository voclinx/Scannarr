<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227184726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE watcher_logs (id UUID NOT NULL, level VARCHAR(10) NOT NULL, message TEXT NOT NULL, context JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, watcher_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D7F34AB3C300AB5D ON watcher_logs (watcher_id)');
        $this->addSql('CREATE INDEX idx_watcher_logs_watcher_created ON watcher_logs (watcher_id, created_at)');
        $this->addSql('CREATE INDEX idx_watcher_logs_level ON watcher_logs (level)');
        $this->addSql('CREATE TABLE watchers (id UUID NOT NULL, watcher_id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, auth_token VARCHAR(64) DEFAULT NULL, hostname VARCHAR(255) DEFAULT NULL, version VARCHAR(50) DEFAULT NULL, config JSON NOT NULL, config_hash VARCHAR(64) NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C4DCAF2EC300AB5D ON watchers (watcher_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C4DCAF2E9315F04E ON watchers (auth_token)');
        $this->addSql('ALTER TABLE watcher_logs ADD CONSTRAINT FK_D7F34AB3C300AB5D FOREIGN KEY (watcher_id) REFERENCES watchers (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE watcher_logs DROP CONSTRAINT FK_D7F34AB3C300AB5D');
        $this->addSql('DROP TABLE watcher_logs');
        $this->addSql('DROP TABLE watchers');
    }
}

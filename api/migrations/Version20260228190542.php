<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260228190542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inode and device_id columns to media_files with composite index';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_files ADD inode BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_files ADD device_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_media_files_inode ON media_files (device_id, inode)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_media_files_inode');
        $this->addSql('ALTER TABLE media_files DROP inode');
        $this->addSql('ALTER TABLE media_files DROP device_id');
    }
}

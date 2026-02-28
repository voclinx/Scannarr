<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260228040923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduled_deletion_items DROP CONSTRAINT fk_d9ebd6948f93b6fc');
        $this->addSql('ALTER TABLE scheduled_deletion_items ALTER movie_id DROP NOT NULL');
        $this->addSql('ALTER TABLE scheduled_deletion_items ADD CONSTRAINT FK_D9EBD6948F93B6FC FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduled_deletion_items DROP CONSTRAINT FK_D9EBD6948F93B6FC');
        $this->addSql('ALTER TABLE scheduled_deletion_items ALTER movie_id SET NOT NULL');
        $this->addSql('ALTER TABLE scheduled_deletion_items ADD CONSTRAINT fk_d9ebd6948f93b6fc FOREIGN KEY (movie_id) REFERENCES movies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}

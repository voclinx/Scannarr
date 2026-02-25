<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225001833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE volumes ALTER total_space_bytes TYPE BIGINT');
        $this->addSql('ALTER TABLE volumes ALTER used_space_bytes TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE volumes ALTER total_space_bytes TYPE INT');
        $this->addSql('ALTER TABLE volumes ALTER used_space_bytes TYPE INT');
    }
}

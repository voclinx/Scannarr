<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the 3 system deletion presets: Conservateur, Modéré, Agressif.
 */
final class Version20260227162443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed system deletion presets (Conservateur, Modéré, Agressif)';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Conservateur preset
        $this->addSql("INSERT INTO deletion_presets (id, name, is_system, is_default, criteria, filters, created_at, updated_at, created_by_id) VALUES (
            'a2000000-0000-0000-0000-000000000001',
            'Conservateur',
            true,
            false,
            '{\"ratio\":{\"enabled\":true,\"threshold\":0.5,\"weight\":20,\"operator\":\"below\"},\"seed_time\":{\"enabled\":true,\"threshold_days\":365,\"weight\":15,\"operator\":\"above\"},\"file_size\":{\"enabled\":false,\"threshold_gb\":50,\"weight\":5,\"operator\":\"above\"},\"orphan_qbit\":{\"enabled\":true,\"weight\":20},\"cross_seed\":{\"enabled\":true,\"weight\":-20,\"per_tracker\":true}}',
            '{\"seeding_status\":\"all\",\"exclude_protected\":true,\"min_score\":0,\"max_results\":null}',
            '$now',
            '$now',
            NULL
        )");

        // Modéré preset (default)
        $this->addSql("INSERT INTO deletion_presets (id, name, is_system, is_default, criteria, filters, created_at, updated_at, created_by_id) VALUES (
            'a2000000-0000-0000-0000-000000000002',
            'Modéré',
            true,
            true,
            '{\"ratio\":{\"enabled\":true,\"threshold\":1.0,\"weight\":30,\"operator\":\"below\"},\"seed_time\":{\"enabled\":true,\"threshold_days\":180,\"weight\":20,\"operator\":\"above\"},\"file_size\":{\"enabled\":true,\"threshold_gb\":40,\"weight\":10,\"operator\":\"above\"},\"orphan_qbit\":{\"enabled\":true,\"weight\":25},\"cross_seed\":{\"enabled\":true,\"weight\":-15,\"per_tracker\":true}}',
            '{\"seeding_status\":\"all\",\"exclude_protected\":true,\"min_score\":0,\"max_results\":null}',
            '$now',
            '$now',
            NULL
        )");

        // Agressif preset
        $this->addSql("INSERT INTO deletion_presets (id, name, is_system, is_default, criteria, filters, created_at, updated_at, created_by_id) VALUES (
            'a2000000-0000-0000-0000-000000000003',
            'Agressif',
            true,
            false,
            '{\"ratio\":{\"enabled\":true,\"threshold\":2.0,\"weight\":35,\"operator\":\"below\"},\"seed_time\":{\"enabled\":true,\"threshold_days\":90,\"weight\":25,\"operator\":\"above\"},\"file_size\":{\"enabled\":true,\"threshold_gb\":20,\"weight\":15,\"operator\":\"above\"},\"orphan_qbit\":{\"enabled\":true,\"weight\":30},\"cross_seed\":{\"enabled\":true,\"weight\":-10,\"per_tracker\":true}}',
            '{\"seeding_status\":\"all\",\"exclude_protected\":true,\"min_score\":0,\"max_results\":null}',
            '$now',
            '$now',
            NULL
        )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM deletion_presets WHERE id IN ('a2000000-0000-0000-0000-000000000001', 'a2000000-0000-0000-0000-000000000002', 'a2000000-0000-0000-0000-000000000003')");
    }
}

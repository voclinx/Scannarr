<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227093432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_logs (id UUID NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) DEFAULT NULL, entity_id UUID DEFAULT NULL, details JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_activity_logs_user ON activity_logs (user_id)');
        $this->addSql('CREATE INDEX idx_activity_logs_action ON activity_logs (action)');
        $this->addSql('CREATE INDEX idx_activity_logs_created ON activity_logs (created_at)');
        $this->addSql('CREATE TABLE deletion_presets (id UUID NOT NULL, name VARCHAR(255) NOT NULL, is_system BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, criteria JSON NOT NULL, filters JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BF81EEF2B03A8386 ON deletion_presets (created_by_id)');
        $this->addSql('CREATE TABLE media_files (id UUID NOT NULL, file_path VARCHAR(1000) NOT NULL, file_name VARCHAR(500) NOT NULL, file_size_bytes BIGINT NOT NULL, hardlink_count INT NOT NULL, resolution VARCHAR(20) DEFAULT NULL, codec VARCHAR(50) DEFAULT NULL, quality VARCHAR(50) DEFAULT NULL, is_linked_radarr BOOLEAN NOT NULL, is_linked_media_player BOOLEAN NOT NULL, file_hash VARCHAR(64) DEFAULT NULL, partial_hash VARCHAR(128) DEFAULT NULL, is_protected BOOLEAN NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, volume_id UUID NOT NULL, radarr_instance_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_192C84E887C14B6B ON media_files (radarr_instance_id)');
        $this->addSql('CREATE INDEX idx_media_files_volume ON media_files (volume_id)');
        $this->addSql('CREATE INDEX idx_media_files_radarr ON media_files (is_linked_radarr)');
        $this->addSql('CREATE INDEX idx_media_files_name ON media_files (file_name)');
        $this->addSql('CREATE INDEX idx_media_files_partial_hash ON media_files (partial_hash)');
        $this->addSql('CREATE UNIQUE INDEX unique_volume_file_path ON media_files (volume_id, file_path)');
        $this->addSql('CREATE TABLE media_player_instances (id UUID NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, url VARCHAR(500) NOT NULL, token VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE movie_files (id UUID NOT NULL, matched_by VARCHAR(30) NOT NULL, confidence NUMERIC(3, 2) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, movie_id UUID NOT NULL, media_file_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_78775AC58F93B6FC ON movie_files (movie_id)');
        $this->addSql('CREATE INDEX IDX_78775AC5F21CFF25 ON movie_files (media_file_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_movie_media_file ON movie_files (movie_id, media_file_id)');
        $this->addSql('CREATE TABLE movies (id UUID NOT NULL, tmdb_id INT DEFAULT NULL, radarr_id INT DEFAULT NULL, title VARCHAR(500) NOT NULL, original_title VARCHAR(500) DEFAULT NULL, year INT DEFAULT NULL, synopsis TEXT DEFAULT NULL, poster_url VARCHAR(1000) DEFAULT NULL, backdrop_url VARCHAR(1000) DEFAULT NULL, genres VARCHAR(500) DEFAULT NULL, rating NUMERIC(3, 1) DEFAULT NULL, runtime_minutes INT DEFAULT NULL, radarr_monitored BOOLEAN DEFAULT NULL, radarr_has_file BOOLEAN DEFAULT NULL, is_protected BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, radarr_instance_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C61EED3055BCC5E5 ON movies (tmdb_id)');
        $this->addSql('CREATE INDEX IDX_C61EED3087C14B6B ON movies (radarr_instance_id)');
        $this->addSql('CREATE INDEX idx_movies_tmdb ON movies (tmdb_id)');
        $this->addSql('CREATE INDEX idx_movies_title ON movies (title)');
        $this->addSql('CREATE TABLE radarr_instances (id UUID NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, api_key VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL, root_folders JSON DEFAULT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BACE7E1C74F2195 ON refresh_tokens (refresh_token)');
        $this->addSql('CREATE TABLE scheduled_deletion_items (id UUID NOT NULL, media_file_ids JSON NOT NULL, status VARCHAR(20) NOT NULL, error_message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scheduled_deletion_id UUID NOT NULL, movie_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D9EBD694427CE889 ON scheduled_deletion_items (scheduled_deletion_id)');
        $this->addSql('CREATE INDEX IDX_D9EBD6948F93B6FC ON scheduled_deletion_items (movie_id)');
        $this->addSql('CREATE TABLE scheduled_deletions (id UUID NOT NULL, scheduled_date DATE NOT NULL, execution_time TIME(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(20) NOT NULL, delete_physical_files BOOLEAN NOT NULL, delete_radarr_reference BOOLEAN NOT NULL, delete_media_player_reference BOOLEAN NOT NULL, disable_radarr_auto_search BOOLEAN NOT NULL, reminder_days_before INT DEFAULT NULL, reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, execution_report JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_463ADC92B03A8386 ON scheduled_deletions (created_by_id)');
        $this->addSql('CREATE TABLE settings (id UUID NOT NULL, setting_key VARCHAR(100) NOT NULL, setting_value TEXT DEFAULT NULL, setting_type VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E545A0C55FA1E697 ON settings (setting_key)');
        $this->addSql('CREATE TABLE torrent_stats (id UUID NOT NULL, torrent_hash VARCHAR(100) NOT NULL, torrent_name VARCHAR(500) DEFAULT NULL, tracker_domain VARCHAR(255) DEFAULT NULL, ratio NUMERIC(10, 4) NOT NULL, seed_time_seconds BIGINT NOT NULL, uploaded_bytes BIGINT NOT NULL, downloaded_bytes BIGINT NOT NULL, size_bytes BIGINT NOT NULL, status VARCHAR(30) NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, qbit_content_path VARCHAR(1000) DEFAULT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, media_file_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_torrent_stats_media_file ON torrent_stats (media_file_id)');
        $this->addSql('CREATE INDEX idx_torrent_stats_tracker ON torrent_stats (tracker_domain)');
        $this->addSql('CREATE UNIQUE INDEX uniq_torrent_hash ON torrent_stats (torrent_hash)');
        $this->addSql('CREATE TABLE torrent_stats_history (id UUID NOT NULL, ratio NUMERIC(10, 4) DEFAULT NULL, uploaded_bytes BIGINT DEFAULT NULL, seed_time_seconds BIGINT DEFAULT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, torrent_stat_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_torrent_history_stats ON torrent_stats_history (torrent_stat_id)');
        $this->addSql('CREATE INDEX idx_torrent_history_date ON torrent_stats_history (recorded_at)');
        $this->addSql('CREATE TABLE tracker_rules (id UUID NOT NULL, tracker_domain VARCHAR(255) NOT NULL, min_seed_time_hours INT NOT NULL, min_ratio NUMERIC(10, 4) NOT NULL, is_auto_detected BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tracker_domain ON tracker_rules (tracker_domain)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');
        $this->addSql('CREATE TABLE volumes (id UUID NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(500) NOT NULL, host_path VARCHAR(500) NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, total_space_bytes BIGINT DEFAULT NULL, used_space_bytes BIGINT DEFAULT NULL, last_scan_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7ADCAA15B548B0F ON volumes (path)');
        $this->addSql('ALTER TABLE activity_logs ADD CONSTRAINT FK_F34B1DCEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE deletion_presets ADD CONSTRAINT FK_BF81EEF2B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_files ADD CONSTRAINT FK_192C84E88FD80EEA FOREIGN KEY (volume_id) REFERENCES volumes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_files ADD CONSTRAINT FK_192C84E887C14B6B FOREIGN KEY (radarr_instance_id) REFERENCES radarr_instances (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE movie_files ADD CONSTRAINT FK_78775AC58F93B6FC FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE movie_files ADD CONSTRAINT FK_78775AC5F21CFF25 FOREIGN KEY (media_file_id) REFERENCES media_files (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE movies ADD CONSTRAINT FK_C61EED3087C14B6B FOREIGN KEY (radarr_instance_id) REFERENCES radarr_instances (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scheduled_deletion_items ADD CONSTRAINT FK_D9EBD694427CE889 FOREIGN KEY (scheduled_deletion_id) REFERENCES scheduled_deletions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scheduled_deletion_items ADD CONSTRAINT FK_D9EBD6948F93B6FC FOREIGN KEY (movie_id) REFERENCES movies (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scheduled_deletions ADD CONSTRAINT FK_463ADC92B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE torrent_stats ADD CONSTRAINT FK_DC02B5B1F21CFF25 FOREIGN KEY (media_file_id) REFERENCES media_files (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE torrent_stats_history ADD CONSTRAINT FK_3A2E145BC8BBDC2C FOREIGN KEY (torrent_stat_id) REFERENCES torrent_stats (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_logs DROP CONSTRAINT FK_F34B1DCEA76ED395');
        $this->addSql('ALTER TABLE deletion_presets DROP CONSTRAINT FK_BF81EEF2B03A8386');
        $this->addSql('ALTER TABLE media_files DROP CONSTRAINT FK_192C84E88FD80EEA');
        $this->addSql('ALTER TABLE media_files DROP CONSTRAINT FK_192C84E887C14B6B');
        $this->addSql('ALTER TABLE movie_files DROP CONSTRAINT FK_78775AC58F93B6FC');
        $this->addSql('ALTER TABLE movie_files DROP CONSTRAINT FK_78775AC5F21CFF25');
        $this->addSql('ALTER TABLE movies DROP CONSTRAINT FK_C61EED3087C14B6B');
        $this->addSql('ALTER TABLE scheduled_deletion_items DROP CONSTRAINT FK_D9EBD694427CE889');
        $this->addSql('ALTER TABLE scheduled_deletion_items DROP CONSTRAINT FK_D9EBD6948F93B6FC');
        $this->addSql('ALTER TABLE scheduled_deletions DROP CONSTRAINT FK_463ADC92B03A8386');
        $this->addSql('ALTER TABLE torrent_stats DROP CONSTRAINT FK_DC02B5B1F21CFF25');
        $this->addSql('ALTER TABLE torrent_stats_history DROP CONSTRAINT FK_3A2E145BC8BBDC2C');
        $this->addSql('DROP TABLE activity_logs');
        $this->addSql('DROP TABLE deletion_presets');
        $this->addSql('DROP TABLE media_files');
        $this->addSql('DROP TABLE media_player_instances');
        $this->addSql('DROP TABLE movie_files');
        $this->addSql('DROP TABLE movies');
        $this->addSql('DROP TABLE radarr_instances');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE scheduled_deletion_items');
        $this->addSql('DROP TABLE scheduled_deletions');
        $this->addSql('DROP TABLE settings');
        $this->addSql('DROP TABLE torrent_stats');
        $this->addSql('DROP TABLE torrent_stats_history');
        $this->addSql('DROP TABLE tracker_rules');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE volumes');
    }
}

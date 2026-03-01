<?php

declare(strict_types=1);

namespace App\ExternalService\Notification;

use App\Contract\Notification\NotificationChannelInterface;
use App\Entity\ScheduledDeletion;
use App\Repository\MediaFileRepository;
use App\Repository\SettingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class DiscordNotificationService implements NotificationChannelInterface
{
    private const int COLOR_WARNING = 16744448;  // Orange
    private const int COLOR_SUCCESS = 3066993;   // Green
    private const int COLOR_ERROR = 15158332;    // Red

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settingRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send a reminder notification before a scheduled deletion.
     */
    public function sendDeletionReminder(ScheduledDeletion $deletion): bool
    {
        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === null) {
            $this->logger->info('Discord webhook URL not configured, skipping reminder');

            return false;
        }

        $embed = $this->buildReminderEmbed($deletion);

        return $this->sendEmbed($webhookUrl, $embed);
    }

    /**
     * Send a success notification after a scheduled deletion completed.
     */
    public function sendDeletionSuccess(ScheduledDeletion $deletion): bool
    {
        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === null) {
            return false;
        }

        $embed = $this->buildSuccessEmbed($deletion);

        return $this->sendEmbed($webhookUrl, $embed);
    }

    /**
     * Send an error notification after a scheduled deletion encountered failures.
     */
    public function sendDeletionError(ScheduledDeletion $deletion): bool
    {
        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === null) {
            return false;
        }

        $embed = $this->buildErrorEmbed($deletion);

        return $this->sendEmbed($webhookUrl, $embed);
    }

    /**
     * Build the Discord embed for a deletion reminder.
     *
     * @return array<string, mixed>
     */
    private function buildReminderEmbed(ScheduledDeletion $deletion): array
    {
        [$movieLines, $totalFiles, $totalSize] = $this->collectItemStats($deletion);
        $itemCount = $deletion->getItems()->count();

        return [
            'title' => '⚠️ Rappel — Suppression planifiée',
            'description' => $this->buildReminderDescription($deletion, $itemCount),
            'color' => self::COLOR_WARNING,
            'fields' => $this->buildReminderFields($movieLines, $totalFiles, $totalSize, $deletion),
            'footer' => ['text' => 'Scanarr — Annulez via l\'interface si besoin'],
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];
    }

    private function buildReminderDescription(ScheduledDeletion $deletion, int $itemCount): string
    {
        $scheduledDate = $deletion->getScheduledDate();
        $dateStr = $scheduledDate instanceof DateTimeInterface ? $scheduledDate->format('d/m/Y') : '??';
        $timeStr = $deletion->getExecutionTime()->format('H:i');
        $plural = $itemCount > 1;

        return sprintf(
            '**%d film%s** %s supprimé%s le **%s à %s**.',
            $itemCount,
            $plural ? 's' : '',
            $plural ? 'seront' : 'sera',
            $plural ? 's' : '',
            $dateStr,
            $timeStr,
        );
    }

    /**
     * @param array<string> $movieLines
     *
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function buildReminderFields(array $movieLines, int $totalFiles, int $totalSize, ScheduledDeletion $deletion): array
    {
        return [
            $this->buildField('Films concernés', implode("\n", array_slice($movieLines, 0, 20))),
            $this->buildField(
                'Fichiers à supprimer',
                sprintf('%d fichier%s (%s)', $totalFiles, $totalFiles > 1 ? 's' : '', $this->formatSize($totalSize)),
                true,
            ),
            $this->buildField('Créé par', $deletion->getCreatedBy()?->getUsername() ?? 'Unknown', true),
        ];
    }

    /**
     * Collect movie lines, total file count, and total size from deletion items.
     *
     * @return array{0: array<string>, 1: int, 2: int}
     */
    private function collectItemStats(ScheduledDeletion $deletion): array
    {
        $movieLines = [];
        $totalFiles = 0;
        $totalSize = 0;

        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            $title = $movie?->getTitle() ?? 'Unknown';
            $year = $movie?->getYear();
            $movieLines[] = '• ' . $title . ($year !== null ? " ({$year})" : '');
            $totalFiles += count($item->getMediaFileIds());
            $totalSize += $this->sumMediaFileSizes($item->getMediaFileIds());
        }

        return [$movieLines, $totalFiles, $totalSize];
    }

    /**
     * Sum file sizes for the given media file IDs.
     *
     * @param array<string> $mediaFileIds
     */
    private function sumMediaFileSizes(array $mediaFileIds): int
    {
        $totalSize = 0;

        foreach ($mediaFileIds as $mediaFileId) {
            $mediaFile = $this->mediaFileRepository->find($mediaFileId);
            if ($mediaFile !== null) {
                $totalSize += $mediaFile->getFileSizeBytes();
            }
        }

        return $totalSize;
    }

    /**
     * Build the Discord embed for a successful deletion.
     *
     * @return array<string, mixed>
     */
    private function buildSuccessEmbed(ScheduledDeletion $deletion): array
    {
        [$movieLines, $totalSpaceFreed, $itemCount] = $this->collectSuccessData($deletion);

        return [
            'title' => '✅ Suppression exécutée',
            'description' => sprintf(
                '**%d film%s** %s été supprimé%s avec succès.',
                $itemCount,
                $itemCount > 1 ? 's' : '',
                $itemCount > 1 ? 'ont' : 'a',
                $itemCount > 1 ? 's' : '',
            ),
            'color' => self::COLOR_SUCCESS,
            'fields' => [
                $this->buildField('Films supprimés', implode("\n", array_slice($movieLines, 0, 20))),
                $this->buildField('Espace libéré', $this->formatSize($totalSpaceFreed), true),
                $this->buildField('Radarr déréférencé', $deletion->isDeleteRadarrReference() ? 'Oui' : 'Non', true),
            ],
            'footer' => ['text' => 'Scanarr'],
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * @return array{0: array<string>, 1: int, 2: int}
     */
    private function collectSuccessData(ScheduledDeletion $deletion): array
    {
        $report = $deletion->getExecutionReport() ?? [];
        $items = $report['items'] ?? [];
        $movieLines = [];
        $totalSpaceFreed = 0;

        foreach ($items as $itemReport) {
            $label = $this->formatMovieLabel($itemReport);
            $movieLines[] = empty($itemReport['errors']) ? "• {$label} ✅" : "• {$label} ❌";
            $totalSpaceFreed += $itemReport['space_freed_bytes'] ?? 0;
        }

        return [$movieLines, $totalSpaceFreed, count($items)];
    }

    /**
     * Build the Discord embed for a deletion with errors.
     *
     * @return array<string, mixed>
     */
    private function buildErrorEmbed(ScheduledDeletion $deletion): array
    {
        $report = $deletion->getExecutionReport() ?? [];
        $items = $report['items'] ?? [];
        $fields = $this->buildErrorFields($items);

        $scheduledDate = $deletion->getScheduledDate();
        $dateStr = $scheduledDate instanceof DateTimeInterface ? $scheduledDate->format('d/m/Y') : '??';

        return [
            'title' => '❌ Suppression — Erreurs détectées',
            'description' => sprintf(
                'La suppression planifiée du **%s** a rencontré des erreurs.',
                $dateStr,
            ),
            'color' => self::COLOR_ERROR,
            'fields' => $fields,
            'footer' => ['text' => 'Scanarr — Vérifiez les permissions de fichiers'],
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Build success/failure field lists for the error embed.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function buildErrorFields(array $items): array
    {
        $successLines = [];
        $failedLines = [];

        foreach ($items as $itemReport) {
            $label = $this->formatMovieLabel($itemReport);

            if (!empty($itemReport['errors'])) {
                $errorMsg = implode(', ', $itemReport['errors']);
                $failedLines[] = "• {$label} ❌ — {$errorMsg}";
                continue;
            }

            $successLines[] = "• {$label} ✅";
        }

        $fields = [];

        if ($successLines !== []) {
            $fields[] = $this->buildField('Succès', implode("\n", array_slice($successLines, 0, 15)));
        }

        if ($failedLines !== []) {
            $fields[] = $this->buildField('Échecs', implode("\n", array_slice($failedLines, 0, 15)));
        }

        return $fields;
    }

    /**
     * Format a movie label from an item report (title + optional year).
     *
     * @param array<string, mixed> $itemReport
     */
    private function formatMovieLabel(array $itemReport): string
    {
        $title = $itemReport['movie'] ?? 'Unknown';
        $year = $itemReport['year'] ?? null;

        return $title . ($year !== null ? " ({$year})" : '');
    }

    /**
     * Build a single Discord embed field.
     *
     * @return array{name: string, value: string, inline: bool}
     */
    private function buildField(string $name, string $value, bool $inline = false): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'inline' => $inline,
        ];
    }

    /**
     * Send a Discord embed via webhook.
     *
     * @param array<string, mixed> $embed
     */
    private function sendEmbed(string $webhookUrl, array $embed): bool
    {
        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => ['embeds' => [$embed]],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Discord notification sent successfully');

                return true;
            }

            $this->logger->warning('Discord webhook returned non-success status', [
                'status' => $statusCode,
                'body' => $response->getContent(false),
            ]);

            return false;
        } catch (Throwable $e) {
            $this->logger->error('Failed to send Discord notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the configured Discord webhook URL.
     */
    private function getWebhookUrl(): ?string
    {
        $url = $this->settingRepository->getValue('discord_webhook_url');

        return in_array($url, [null, '', '0'], true) ? null : $url;
    }

    /**
     * Format bytes to human-readable size string.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytesPerUnit = 1024;
        $unitIndex = (int)floor(log($bytes) / log($bytesPerUnit));

        return round($bytes / $bytesPerUnit ** $unitIndex, $unitIndex > 1 ? 1 : 0) . ' ' . $units[$unitIndex];
    }
}

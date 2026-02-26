<?php

namespace App\Service;

use App\Entity\ScheduledDeletion;
use App\Repository\MediaFileRepository;
use App\Repository\SettingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class DiscordNotificationService
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

        $items = $deletion->getItems();
        $movieLines = [];
        $totalFiles = 0;
        $totalSize = 0;

        foreach ($items as $item) {
            $movie = $item->getMovie();
            $title = $movie?->getTitle() ?? 'Unknown';
            $year = $movie?->getYear();
            $movieLines[] = '• ' . $title . ($year !== null ? " ({$year})" : '');
            $totalFiles += count($item->getMediaFileIds());

            foreach ($item->getMediaFileIds() as $mediaFileId) {
                $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                if ($mediaFile !== null) {
                    $totalSize += $mediaFile->getFileSizeBytes();
                }
            }
        }

        $scheduledDate = $deletion->getScheduledDate();
        $executionTime = $deletion->getExecutionTime();
        $dateStr = $scheduledDate instanceof DateTimeInterface ? $scheduledDate->format('d/m/Y') : '??';
        $timeStr = $executionTime->format('H:i');
        $createdBy = $deletion->getCreatedBy()?->getUsername() ?? 'Unknown';

        $embed = [
            'title' => '⚠️ Rappel — Suppression planifiée',
            'description' => sprintf(
                '**%d film%s** %s supprimé%s le **%s à %s**.',
                $items->count(),
                $items->count() > 1 ? 's' : '',
                $items->count() > 1 ? 'seront' : 'sera',
                $items->count() > 1 ? 's' : '',
                $dateStr,
                $timeStr,
            ),
            'color' => self::COLOR_WARNING,
            'fields' => [
                [
                    'name' => 'Films concernés',
                    'value' => implode("\n", array_slice($movieLines, 0, 20)),
                    'inline' => false,
                ],
                [
                    'name' => 'Fichiers à supprimer',
                    'value' => sprintf('%d fichier%s (%s)', $totalFiles, $totalFiles > 1 ? 's' : '', $this->formatSize($totalSize)),
                    'inline' => true,
                ],
                [
                    'name' => 'Créé par',
                    'value' => $createdBy,
                    'inline' => true,
                ],
            ],
            'footer' => ['text' => 'Scanarr — Annulez via l\'interface si besoin'],
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];

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

        $report = $deletion->getExecutionReport() ?? [];
        $items = $report['items'] ?? [];

        $movieLines = [];
        $totalSpaceFreed = 0;

        foreach ($items as $itemReport) {
            $title = $itemReport['movie'] ?? 'Unknown';
            $year = $itemReport['year'] ?? null;
            $label = $title . ($year !== null ? " ({$year})" : '');

            $movieLines[] = empty($itemReport['errors']) ? "• {$label} ✅" : "• {$label} ❌";

            $totalSpaceFreed += $itemReport['space_freed_bytes'] ?? 0;
        }

        $embed = [
            'title' => '✅ Suppression exécutée',
            'description' => sprintf(
                '**%d film%s** %s été supprimé%s avec succès.',
                count($items),
                count($items) > 1 ? 's' : '',
                count($items) > 1 ? 'ont' : 'a',
                count($items) > 1 ? 's' : '',
            ),
            'color' => self::COLOR_SUCCESS,
            'fields' => [
                [
                    'name' => 'Films supprimés',
                    'value' => implode("\n", array_slice($movieLines, 0, 20)),
                    'inline' => false,
                ],
                [
                    'name' => 'Espace libéré',
                    'value' => $this->formatSize($totalSpaceFreed),
                    'inline' => true,
                ],
                [
                    'name' => 'Radarr déréférencé',
                    'value' => $deletion->isDeleteRadarrReference() ? 'Oui' : 'Non',
                    'inline' => true,
                ],
            ],
            'footer' => ['text' => 'Scanarr'],
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];

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

        $report = $deletion->getExecutionReport() ?? [];
        $items = $report['items'] ?? [];

        $successLines = [];
        $failedLines = [];

        foreach ($items as $itemReport) {
            $title = $itemReport['movie'] ?? 'Unknown';
            $year = $itemReport['year'] ?? null;
            $label = $title . ($year !== null ? " ({$year})" : '');

            if (empty($itemReport['errors'])) {
                $successLines[] = "• {$label} ✅";
            } else {
                $errorMsg = implode(', ', $itemReport['errors']);
                $failedLines[] = "• {$label} ❌ — {$errorMsg}";
            }
        }

        $scheduledDate = $deletion->getScheduledDate();
        $dateStr = $scheduledDate instanceof DateTimeInterface ? $scheduledDate->format('d/m/Y') : '??';

        $fields = [];

        if ($successLines !== []) {
            $fields[] = [
                'name' => 'Succès',
                'value' => implode("\n", array_slice($successLines, 0, 15)),
                'inline' => false,
            ];
        }

        if ($failedLines !== []) {
            $fields[] = [
                'name' => 'Échecs',
                'value' => implode("\n", array_slice($failedLines, 0, 15)),
                'inline' => false,
            ];
        }

        $embed = [
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

        return $this->sendEmbed($webhookUrl, $embed);
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
        $k = 1024;
        $i = (int)floor(log($bytes) / log($k));

        return round($bytes / $k ** $i, $i > 1 ? 1 : 0) . ' ' . $units[$i];
    }
}

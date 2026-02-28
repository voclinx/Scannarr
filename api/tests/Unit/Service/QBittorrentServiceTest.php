<?php

namespace App\Tests\Unit\Service;

use App\ExternalService\TorrentClient\QBittorrentService;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
class QBittorrentServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private SettingRepository&MockObject $settingRepository;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settingRepository = $this->createMock(SettingRepository::class);
    }

    /**
     * Create a fresh service instance to test without previous SID cache.
     */
    private function createServiceWithSettings(array $settingsMap): QBittorrentService
    {
        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key) => $settingsMap[$key] ?? null);

        return new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );
    }

    // ---------------------------------------------------------------
    // SID Cache
    // ---------------------------------------------------------------

    public function testSidCacheReusesSessionOnConsecutiveCalls(): void
    {
        $settings = [
            'qbittorrent_url' => 'http://localhost:8080',
            'qbittorrent_username' => 'admin',
            'qbittorrent_password' => 'secret',
        ];

        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key): ?string => $settings[$key] ?? null);

        $loginResponse = $this->createMock(ResponseInterface::class);
        $loginResponse->method('getContent')->willReturn('Ok.');
        $loginResponse->method('getHeaders')->willReturn([
            'set-cookie' => ['SID=cached_sid; Path=/; HttpOnly'],
        ]);

        $torrentsResponse = $this->createMock(ResponseInterface::class);
        $torrentsResponse->method('getContent')->willReturn('[]');
        $torrentsResponse->method('getStatusCode')->willReturn(200);

        // Login should only be called once, then 2 torrents/info calls
        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($loginResponse, $torrentsResponse): MockObject {
                if (str_contains($url, '/auth/login')) {
                    return $loginResponse;
                }

                return $torrentsResponse;
            });

        $service = new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );

        // Two consecutive calls should result in only one auth call
        $service->getAllTorrents();
        $service->getAllTorrents();
    }

    public function testSidCacheExpiredTriggersReauth(): void
    {
        $settings = [
            'qbittorrent_url' => 'http://localhost:8080',
            'qbittorrent_username' => 'admin',
            'qbittorrent_password' => 'secret',
        ];

        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key): ?string => $settings[$key] ?? null);

        $loginResponse = $this->createMock(ResponseInterface::class);
        $loginResponse->method('getContent')->willReturn('Ok.');
        $loginResponse->method('getHeaders')->willReturn([
            'set-cookie' => ['SID=fresh_sid; Path=/; HttpOnly'],
        ]);

        $torrentsResponse = $this->createMock(ResponseInterface::class);
        $torrentsResponse->method('getContent')->willReturn('[]');
        $torrentsResponse->method('getStatusCode')->willReturn(200);

        // 2 auth + 2 torrent calls
        $this->httpClient->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($loginResponse, $torrentsResponse): MockObject {
                if (str_contains($url, '/auth/login')) {
                    return $loginResponse;
                }

                return $torrentsResponse;
            });

        // Use reflection to force SID expiry
        $service = new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );

        $service->getAllTorrents();

        // Force the SID to be expired
        $ref = new ReflectionClass($service);
        $expiryProp = $ref->getProperty('sidExpiry');
        $expiryProp->setValue($service, microtime(true) - 1);

        $service->getAllTorrents();
    }

    // ---------------------------------------------------------------
    // getPathMappings()
    // ---------------------------------------------------------------

    public function testGetPathMappingsReturnsDecodedMappings(): void
    {
        $mappings = [
            ['qbit' => '/downloads/', 'host' => '/mnt/data/downloads/'],
            ['qbit' => '/media/', 'host' => '/srv/media/'],
        ];

        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => json_encode($mappings),
        ]);

        $this->assertSame($mappings, $service->getPathMappings());
    }

    public function testGetPathMappingsReturnsEmptyArrayWhenNull(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => null,
        ]);

        $this->assertSame([], $service->getPathMappings());
    }

    public function testGetPathMappingsReturnsEmptyArrayWhenEmpty(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => '',
        ]);

        $this->assertSame([], $service->getPathMappings());
    }

    public function testGetPathMappingsReturnsEmptyArrayOnInvalidJson(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => 'not-json',
        ]);

        $this->assertSame([], $service->getPathMappings());
    }

    // ---------------------------------------------------------------
    // mapQbitPathToHost()
    // ---------------------------------------------------------------

    public function testMapQbitPathToHostWithMatchingMapping(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => json_encode([
                ['qbit' => '/downloads/', 'host' => '/mnt/data/downloads/'],
            ]),
        ]);

        $this->assertSame(
            '/mnt/data/downloads/movie/file.mkv',
            $service->mapQbitPathToHost('/downloads/movie/file.mkv'),
        );
    }

    public function testMapQbitPathToHostWithNoMatchReturnsOriginal(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => json_encode([
                ['qbit' => '/downloads/', 'host' => '/mnt/data/downloads/'],
            ]),
        ]);

        $this->assertSame(
            '/other/path/file.mkv',
            $service->mapQbitPathToHost('/other/path/file.mkv'),
        );
    }

    public function testMapQbitPathToHostWithMultipleMappingsUsesFirst(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => json_encode([
                ['qbit' => '/downloads/', 'host' => '/mnt/data/downloads/'],
                ['qbit' => '/downloads/movies/', 'host' => '/srv/movies/'],
            ]),
        ]);

        // First matching mapping wins
        $this->assertSame(
            '/mnt/data/downloads/movies/file.mkv',
            $service->mapQbitPathToHost('/downloads/movies/file.mkv'),
        );
    }

    public function testMapQbitPathToHostWithEmptyMappings(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_path_mappings' => null,
        ]);

        $this->assertSame('/some/path', $service->mapQbitPathToHost('/some/path'));
    }

    // ---------------------------------------------------------------
    // getAllTorrents()
    // ---------------------------------------------------------------

    public function testGetAllTorrentsReturnsDecodedArray(): void
    {
        $settings = [
            'qbittorrent_url' => 'http://localhost:8080',
            'qbittorrent_username' => 'admin',
            'qbittorrent_password' => 'secret',
        ];

        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key): ?string => $settings[$key] ?? null);

        $loginResponse = $this->createMock(ResponseInterface::class);
        $loginResponse->method('getContent')->willReturn('Ok.');
        $loginResponse->method('getHeaders')->willReturn([
            'set-cookie' => ['SID=test_sid; Path=/'],
        ]);

        $torrentsData = [
            ['hash' => 'abc123', 'name' => 'Movie 1', 'content_path' => '/downloads/movie1.mkv'],
            ['hash' => 'def456', 'name' => 'Movie 2', 'content_path' => '/downloads/movie2.mkv'],
        ];

        $torrentsResponse = $this->createMock(ResponseInterface::class);
        $torrentsResponse->method('getContent')->willReturn(json_encode($torrentsData));
        $torrentsResponse->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($loginResponse, $torrentsResponse): MockObject {
                if (str_contains($url, '/auth/login')) {
                    return $loginResponse;
                }

                return $torrentsResponse;
            });

        $service = new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );

        $result = $service->getAllTorrents();

        $this->assertCount(2, $result);
        $this->assertSame('abc123', $result[0]['hash']);
        $this->assertSame('def456', $result[1]['hash']);
    }

    // ---------------------------------------------------------------
    // deleteTorrents()
    // ---------------------------------------------------------------

    public function testDeleteTorrentsPostsWithPipeSeparatedHashes(): void
    {
        $settings = [
            'qbittorrent_url' => 'http://localhost:8080',
            'qbittorrent_username' => 'admin',
            'qbittorrent_password' => 'secret',
        ];

        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key): ?string => $settings[$key] ?? null);

        $loginResponse = $this->createMock(ResponseInterface::class);
        $loginResponse->method('getContent')->willReturn('Ok.');
        $loginResponse->method('getHeaders')->willReturn([
            'set-cookie' => ['SID=test_sid; Path=/'],
        ]);

        $deleteResponse = $this->createMock(ResponseInterface::class);

        $capturedBody = null;

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($loginResponse, $deleteResponse, &$capturedBody): MockObject {
                if (str_contains($url, '/auth/login')) {
                    return $loginResponse;
                }

                $this->assertSame('POST', $method);
                $this->assertStringContainsString('/api/v2/torrents/delete', $url);
                $capturedBody = $options['body'] ?? [];

                return $deleteResponse;
            });

        $service = new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );

        $result = $service->deleteTorrents(['hash1', 'hash2', 'hash3'], false);

        $this->assertTrue($result);
        $this->assertSame('hash1|hash2|hash3', $capturedBody['hashes']);
        $this->assertSame('false', $capturedBody['deleteFiles']);
    }

    public function testDeleteTorrentsWithDeleteFiles(): void
    {
        $settings = [
            'qbittorrent_url' => 'http://localhost:8080',
            'qbittorrent_username' => 'admin',
            'qbittorrent_password' => 'secret',
        ];

        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->settingRepository->method('getValue')
            ->willReturnCallback(fn (string $key): ?string => $settings[$key] ?? null);

        $loginResponse = $this->createMock(ResponseInterface::class);
        $loginResponse->method('getContent')->willReturn('Ok.');
        $loginResponse->method('getHeaders')->willReturn([
            'set-cookie' => ['SID=test_sid; Path=/'],
        ]);

        $deleteResponse = $this->createMock(ResponseInterface::class);

        $capturedBody = null;

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($loginResponse, $deleteResponse, &$capturedBody): MockObject {
                if (str_contains($url, '/auth/login')) {
                    return $loginResponse;
                }

                $capturedBody = $options['body'] ?? [];

                return $deleteResponse;
            });

        $service = new QBittorrentService(
            $this->httpClient,
            $this->logger,
            $this->settingRepository,
        );

        $result = $service->deleteTorrents(['hash1'], true);

        $this->assertTrue($result);
        $this->assertSame('hash1', $capturedBody['hashes']);
        $this->assertSame('true', $capturedBody['deleteFiles']);
    }

    public function testDeleteTorrentsReturnsFalseWhenNotConfigured(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_url' => null,
        ]);

        $this->assertFalse($service->deleteTorrents(['hash1']));
    }

    public function testDeleteTorrentsReturnsFalseOnEmptyHashes(): void
    {
        $service = $this->createServiceWithSettings([
            'qbittorrent_url' => 'http://localhost:8080',
        ]);

        $this->assertFalse($service->deleteTorrents([]));
    }
}

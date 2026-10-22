<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Service;

use OCA\EducAI\Db\BotSource;
use OCA\EducAI\Db\BotSourceMapper;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;
use OCA\EducAI\Db\Settings;
use OCA\EducAI\Jobs\ReindexBotSourceJob;
use OCA\EducAI\Service\DoclingClient;
use OCA\EducAI\Service\EmbeddingClient;
use OCA\EducAI\Service\KnowledgeSourceStatus;
use OCA\EducAI\Service\RagIngestionService;
use OCA\EducAI\Service\RateLimitService;
use OCA\EducAI\Service\SettingsService;
use OCA\EducAI\Service\UrlContentFetcher;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Exercises ingestion and the real embedding client's HTTP batching together. */
class RagProviderQuotaProgressTest extends TestCase {
	public static function quotaCases(): array {
		return [
			'folder, eight requests per window' => ['folder', 8, true],
			'file, one request per window' => ['file', 1, false],
			'URL, one request per window' => ['url', 1, true],
		];
	}

	#[DataProvider('quotaCases')]
	public function testEverySuccessfulProviderBatchSurvivesQuotaPauses(string $nodeType, int $quota, bool $force): void {
		[$service, $source, $state] = $this->fixture($nodeType, $quota);
		$attempt = 0;
		$windows = (int)ceil(50 / (4 * $quota));
		for ($window = 0; $window < $windows; $window++) {
			$state->windowRequests = 0;
			$service->ingestSource($source, $force, $attempt);
			if ($window + 1 < $windows) {
				self::assertSame(KnowledgeSourceStatus::PENDING, $source->getStatus());
				self::assertCount(($window + 1) * 4 * $quota, $state->staged, 'Every successful HTTP response must be checkpointed before the next request.');
				self::assertSame(['previous complete index'], $state->published);
				self::assertSame('previous-checksum', $source->getChecksum());
				[$job, $retryAt, $args] = $state->jobs[$window];
				self::assertSame(ReindexBotSourceJob::class, $job);
				self::assertGreaterThanOrEqual(time() + 120, $retryAt);
				self::assertSame(['sourceId' => 1, 'force' => $force, 'attempt' => 1], $args);
				$attempt = $args['attempt'];
			}
		}

		self::assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
		self::assertSame(1, $state->publications);
		self::assertCount(50, $state->published);
		self::assertSame(str_split($state->content, 100), array_column($state->published, 'text'));
		self::assertCount(50, $state->successfulInputs, 'Already staged chunks must not incur another provider request.');
		foreach ($state->published as $row) {
			self::assertSame($this->vector($row['text']), array_map('floatval', $row['vector']), 'Each vector must still belong to the matching chunk.');
			self::assertSame('model-a', $row['model']);
			if ($nodeType !== 'url') {
				self::assertSame(10, $row['metadata']['node_id']);
			}
		}
	}

	public function testNoProgressStillExhaustsTheRetryBudgetWithoutReplacingTheIndex(): void {
		[$service, $source, $state] = $this->fixture('folder', 0);
		for ($attempt = 0; $attempt <= 5; $attempt++) {
			$service->ingestSource($source, true, $attempt);
		}
		self::assertSame(KnowledgeSourceStatus::ERROR, $source->getStatus());
		self::assertCount(5, $state->jobs);
		self::assertSame([], $state->staged);
		self::assertSame(['previous complete index'], $state->published);
		self::assertSame('previous-checksum', $source->getChecksum());
	}

	public static function generationChanges(): array {
		return ['content' => ['content'], 'model' => ['model'], 'chunk size' => ['chunk_size'], 'replacement at same path' => ['file_id']];
	}

	#[DataProvider('generationChanges')]
	public function testChangedGenerationCannotPublishPreviouslyStagedVectors(string $change): void {
		[$service, $source, $state] = $this->fixture('folder', 1);
		$service->ingestSource($source, true);
		self::assertCount(4, $state->staged);
		$generation = $state->generation;
		if ($change === 'content') {
			$state->content = str_replace('chunk', 'fresh', $state->content);
		} elseif ($change === 'model') {
			$state->config['embedding_model'] = 'model-b';
		} elseif ($change === 'file_id') {
			$state->fileId = 11;
		} else {
			$state->config['rag_chunk_size'] = 200;
		}
		$state->quota = 100;
		$state->windowRequests = 0;
		$service->ingestSource($source, true, 1);

		self::assertSame(KnowledgeSourceStatus::READY, $source->getStatus());
		self::assertNotSame($generation, $state->generation);
		$expected = str_split($state->content, $state->config['rag_chunk_size']);
		self::assertSame($expected, array_column($state->published, 'text'));
		self::assertCount(count($expected) + 4, $state->successfulInputs, 'Changed content/model/chunking must re-embed the entire new generation.');
		self::assertSame([$state->config['embedding_model']], array_values(array_unique(array_column($state->published, 'model'))));
		self::assertSame([$state->fileId], array_values(array_unique(array_map(static fn (array $row): int => $row['metadata']['node_id'], $state->published))));
	}

	/** Controlled HTTP and storage boundaries; no sleep or network access. */
	private function fixture(string $nodeType, int $quota): array {
		$state = (object)[
			'content' => implode('', array_map(static fn (int $i): string => str_pad('chunk-' . $i . ':', 100, '.'), range(0, 49))),
			'config' => ['rag_enabled' => true, 'rag_chunk_size' => 100, 'rag_chunk_overlap' => 0,
				'embedding_api_endpoint' => 'https://provider.invalid/v1/embeddings',
				'embedding_api_key' => 'synthetic-fixture', 'embedding_model' => 'model-a'],
			'quota' => $quota, 'windowRequests' => 0, 'successfulInputs' => [], 'fileId' => 10,
			'staged' => [], 'generation' => null, 'published' => ['previous complete index'], 'publications' => 0, 'jobs' => [],
		];
		$source = new BotSource();
		$source->setId(1);
		$source->setBotId(7);
		$source->setOwnerUid('alice');
		$source->setNodeId(10);
		$source->setNodeType($nodeType);
		$source->setSourceUrl('https://source.invalid/document');
		$source->setChecksum('previous-checksum');
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturnCallback(static fn (): int => $state->fileId);
		$file->method('getName')->willReturn('notes.txt');
		$file->method('getPath')->willReturn('/alice/files/docs/notes.txt');
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getContent')->willReturnCallback(static fn (): string => $state->content);
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getById')->willReturn([$nodeType === 'file' ? $file : $folder]);
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$sources = $this->createMock(BotSourceMapper::class);
		$sources->method('findById')->willReturn($source);
		$sources->method('update')->willReturnArgument(0);
		$embeddings = $this->createMock(EmbeddingMapper::class);
		$embeddings->method('stagedChunkIndexes')->willReturnCallback(static function (int $id, string $model, string $generation) use ($state): array {
			if ($state->generation !== $generation) {
				$state->generation = $generation;
				$state->staged = [];
			}
			return array_fill_keys(array_keys($state->staged), true);
		});
		$embeddings->method('insertStaged')->willReturnCallback(static function (Embedding $embedding, int $index) use ($state): void {
			$state->staged[$index] = ['text' => $embedding->getChunkText(), 'vector' => json_decode($embedding->getEmbedding(), true), 'model' => $embedding->getEmbeddingModel(), 'metadata' => json_decode($embedding->getMetadata(), true)];
		});
		$embeddings->method('publishStaged')->willReturnCallback(static function (int $id, string $model, string $generation, int $count) use ($state): void {
			self::assertCount($count, $state->staged);
			$state->published = $state->staged;
			$state->staged = [];
			$state->publications++;
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn(new Settings());
		$settings->method('getRagConfig')->willReturnCallback(static fn (): array => $state->config);
		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->method('isEnabled')->willReturn(false);
		$failureResponse = $this->createMock(IResponse::class);
		$failureResponse->method('getStatusCode')->willReturn(429);
		$failureResponse->method('getHeader')->willReturnCallback(static fn (string $name): string => strtolower($name) === 'retry-after' ? '120' : '');
		$failure = new class($failureResponse) extends \RuntimeException {
			public function __construct(private IResponse $response) { parent::__construct('Synthetic quota exhausted', 429); }
			public function hasResponse(): bool { return true; }
			public function getResponse(): IResponse { return $this->response; }
		};
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use ($state, $failure): IResponse {
			if (++$state->windowRequests > $state->quota) { throw $failure; }
			$inputs = $options['json']['input'];
			array_push($state->successfulInputs, ...$inputs);
			$response = $this->createMock(IResponse::class);
			$response->method('getHeader')->willReturn('');
			$response->method('getBody')->willReturn(json_encode(['data' => array_map(fn (string $text): array => ['embedding' => $this->vector($text)], $inputs)]));
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		$embeddingClient = new EmbeddingClient($clients, $settings, $rateLimit, $logger, static function (): void { self::fail('Long quota pauses must be scheduled, not slept inline.'); });
		$docling = $this->createMock(DoclingClient::class);
		$docling->method('isEnabled')->willReturn(false);
		$docling->method('isSupported')->willReturn(false);
		$fetcher = $this->createMock(UrlContentFetcher::class);
		$fetcher->method('fetchAndExtract')->willReturnCallback(static fn (): array => ['content' => $state->content, 'content_type' => 'text/plain']);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturn([]);
		$jobs->method('scheduleAfter')->willReturnCallback(static function (string $job, int $retryAt, array $args) use ($state): void { $state->jobs[] = [$job, $retryAt, $args]; });
		return [new RagIngestionService($sources, $embeddings, $embeddingClient, $docling, $fetcher, $settings, $root, $jobs, $logger), $source, $state];
	}

	private function vector(string $text): array {
		return [(float)crc32($text), (float)strlen($text)];
	}
}

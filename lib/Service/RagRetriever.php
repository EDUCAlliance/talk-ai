<?php

declare(strict_types=1);

namespace OCA\EducAI\Service;

use Exception;
use OCA\EducAI\Db\Embedding;
use OCA\EducAI\Db\EmbeddingMapper;

class RagRetriever {
    private EmbeddingMapper $embeddingMapper;
    private EmbeddingClient $embeddingClient;
    private SettingsService $settingsService;

    public function __construct(
        EmbeddingMapper $embeddingMapper,
        EmbeddingClient $embeddingClient,
        SettingsService $settingsService
    ) {
        $this->embeddingMapper = $embeddingMapper;
        $this->embeddingClient = $embeddingClient;
        $this->settingsService = $settingsService;
    }

    /**
     * @return array<int,array{chunk:Embedding,score:float,metadata:array}>
     */
    public function retrieve(int $botId, string $query): array {
        $config = $this->settingsService->getRagConfig();
        if (!$config['rag_enabled']) {
            return [];
        }
        $embeddingModel = $this->embeddingClient->getActiveModel();
        $embeddings = $this->embeddingMapper->findByBotAndModel($botId, $embeddingModel);
        if (count($embeddings) === 0) {
            return [];
        }

        $vectorList = $this->embeddingClient->embedTexts([$query], $embeddingModel);
        if (count($vectorList) === 0) {
            return [];
        }
        $queryVector = $vectorList[0];

        $scored = [];
        foreach ($embeddings as $embedding) {
            $vector = $this->decodeVector($embedding->getEmbedding());
            if ($vector === null) {
                continue;
            }
            $score = $this->cosineSimilarity($queryVector, $vector);
            $metadata = $this->decodeMetadata($embedding->getMetadata());
            $scored[] = [
                'chunk' => $embedding,
                'score' => $score,
                'metadata' => $metadata,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $a['score'] <=> $b['score'];
        });
        $scored = array_reverse($scored);

        $topK = $config['rag_top_k'] ?? 5;
        if ($topK <= 0) {
            $topK = 5;
        }

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    private function cosineSimilarity(array $a, array $b): float {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * @return array<int,float>|null
     */
    private function decodeVector(?string $value): ?array {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        $vector = [];
        foreach ($decoded as $item) {
            if (!is_numeric($item)) {
                return null;
            }
            $vector[] = (float)$item;
        }
        return $vector;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMetadata(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

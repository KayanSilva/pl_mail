<?php

declare(strict_types=1);

namespace App\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fallback classifier that queries a paid language model through an OpenAI-compatible
 * endpoint (such as OmniRoute) for messages where rules produced no verdict.
 *
 * CRITICAL SAFETY INVARIANT:
 * Email content must NEVER reach a free tier model. If the configured model name
 * contains "free" (case-insensitive) or matches generic auto/combo aliases, the
 * classifier disables itself and leaves the message unclassified.
 */
final readonly class ModelClassifier
{
    private const int MAX_BODY_CHARS = 1500;
    private const array FORBIDDEN_MODEL_SUBSTRINGS = ['free', 'auto', 'combo'];

    private bool $enabled;
    private LoggerInterface $logger;

    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $model,
        private string $apiKey = 'none',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger  = $logger ?? new NullLogger();
        $this->enabled = $this->evaluateSafety($this->model);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function classify(string $fromAddress, ?string $subject, ?string $bodyText): ?ImportanceKind
    {
        if (!$this->enabled) {
            return null;
        }

        $bodySnippet = '';
        if (null !== $bodyText && '' !== trim($bodyText)) {
            $bodySnippet = mb_substr(trim($bodyText), 0, self::MAX_BODY_CHARS);
        }

        $systemPrompt = <<<'PROMPT'
Você é um classificador de e-mails em português.
Classifique a mensagem em exatamente UMA das categorias:
- "government": e-mail de órgão público federal, estadual ou municipal (Receita, INSS, Detran, tribunal, prefeitura, gov.br, etc.).
- "bill": conta a pagar, fatura, boleto, cobrança de banco, cartão ou concessionária de serviço público.
- "family": parente próximo ou assunto estritamente familiar.
- "none": qualquer outro assunto (newsletter, marketing, compras rotineiras, redes sociais, spam).

Responda SOMENTE um JSON no formato: {"kind":"government|bill|family|none"}
PROMPT;

        $userMessage = sprintf(
            "De: %s\nAssunto: %s\nTrecho do corpo:\n%s",
            $fromAddress,
            $subject ?? '(sem assunto)',
            $bodySnippet,
        );

        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions';

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $this->model,
                    'temperature' => 0.0,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ],
                'timeout' => 20,
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('ModelClassifier: endpoint retornou HTTP não-200', [
                    'status' => $response->getStatusCode(),
                ]);
                return null;
            }

            $data = $response->toArray(false);
            $rawContent = (string) ($data['choices'][0]['message']['content'] ?? '');

            return $this->parseKind($rawContent);
        } catch (\Throwable $e) {
            $this->logger->warning('ModelClassifier: falha na chamada do modelo', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function parseKind(string $content): ?ImportanceKind
    {
        $trimmed = trim($content);

        // Strip markdown code fences if present
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || !isset($decoded['kind'])) {
            return null;
        }

        $kindStr = mb_strtolower(trim((string) $decoded['kind']));

        return match ($kindStr) {
            'government' => ImportanceKind::Government,
            'bill'       => ImportanceKind::Bill,
            'family'     => ImportanceKind::Family,
            default      => null,
        };
    }

    private function evaluateSafety(string $model): bool
    {
        $clean = mb_strtolower(trim($model));
        if ('' === $clean) {
            return false;
        }

        foreach (self::FORBIDDEN_MODEL_SUBSTRINGS as $forbidden) {
            if (str_contains($clean, $forbidden)) {
                $this->logger->warning('ModelClassifier: modelo recusado por segurança (contém palavra proibida de free/alias)', [
                    'model'     => $model,
                    'forbidden' => $forbidden,
                ]);
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Service\ImportantMail\ModelClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ModelClassifierTest extends TestCase
{
    public function testDisabledWhenModelIsEmptyOrFreeOrAlias(): void
    {
        $mock = new MockHttpClient();

        self::assertFalse((new ModelClassifier($mock, 'http://localhost:20128', '', 'key'))->isEnabled());
        self::assertFalse((new ModelClassifier($mock, 'http://localhost:20128', 'gemini-free', 'key'))->isEnabled());
        self::assertFalse((new ModelClassifier($mock, 'http://localhost:20128', 'deepseek-Free-r1', 'key'))->isEnabled());
        self::assertFalse((new ModelClassifier($mock, 'http://localhost:20128', 'auto', 'key'))->isEnabled());
        self::assertFalse((new ModelClassifier($mock, 'http://localhost:20128', 'combo', 'key'))->isEnabled());

        // Paid specific models are enabled
        self::assertTrue((new ModelClassifier($mock, 'http://localhost:20128', 'claude-sonnet-4-6', 'key'))->isEnabled());
        self::assertTrue((new ModelClassifier($mock, 'http://localhost:20128', 'gpt-4o', 'key'))->isEnabled());
    }

    public function testDisabledNeverCallsHttp(): void
    {
        $called = false;
        $mock   = new MockHttpClient(function () use (&$called) {
            $called = true;
            return new MockResponse('{}');
        });

        $classifier = new ModelClassifier($mock, 'http://localhost:20128', '', 'key');
        $result     = $classifier->classify('x@y.com', 'assunto', 'corpo');

        self::assertFalse($called);
        self::assertNull($result);
    }

    public function testReturnsKindFromJsonReply(): void
    {
        $reply = json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => '{"kind":"government","reason":"notificação fiscal"}',
                    ],
                ],
            ],
        ]);

        $mock = new MockHttpClient(new MockResponse($reply));
        $classifier = new ModelClassifier($mock, 'http://localhost:20128', 'claude-sonnet-4-6', 'key');

        $result = $classifier->classify('notifica@fazenda.sp.gov', 'Notificação IPVA', 'Pague a taxa');

        self::assertSame(ImportanceKind::Government, $result);
    }

    public function testNoneAndGarbageReplyReturnNull(): void
    {
        $replyNone = json_encode(['choices' => [['message' => ['content' => '{"kind":"none"}']]]]);
        $mockNone  = new MockHttpClient(new MockResponse($replyNone));
        $cNone     = new ModelClassifier($mockNone, 'http://localhost:20128', 'gpt-4o', 'key');
        self::assertNull($cNone->classify('news@tech.com', 'Promoção', 'compre'));

        $replyGarbage = json_encode(['choices' => [['message' => ['content' => 'Não tenho certeza.']]]]);
        $mockGarbage  = new MockHttpClient(new MockResponse($replyGarbage));
        $cGarbage     = new ModelClassifier($mockGarbage, 'http://localhost:20128', 'gpt-4o', 'key');
        self::assertNull($cGarbage->classify('x@y.com', 'teste', 'corpo'));
    }

    public function testHttpErrorReturnsNullNeverThrows(): void
    {
        $mock = new MockHttpClient(new MockResponse('Internal server error', ['http_code' => 500]));
        $classifier = new ModelClassifier($mock, 'http://localhost:20128', 'gpt-4o', 'key');

        self::assertNull($classifier->classify('x@y.com', 'teste', 'corpo'));
    }

    public function testBodyIsTruncatedTo1500Chars(): void
    {
        $capturedBody = null;
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"kind":"none"}']]],
            ]));
        });

        $longBody = str_repeat('A', 5000);
        $classifier = new ModelClassifier($mock, 'http://localhost:20128', 'gpt-4o', 'key');
        $classifier->classify('x@y.com', 'assunto', $longBody);

        self::assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $prompt  = $decoded['messages'][1]['content'] ?? '';

        // The prompt carries truncated body + header info, total prompt well below 5000
        self::assertLessThan(2500, strlen($prompt));
        self::assertStringContainsString(str_repeat('A', 1500), $prompt);
        self::assertStringNotContainsString(str_repeat('A', 1501), $prompt);
    }
}

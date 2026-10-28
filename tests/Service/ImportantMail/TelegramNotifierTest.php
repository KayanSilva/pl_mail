<?php

declare(strict_types=1);

namespace App\Tests\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Entity\Mail\Message;
use App\Service\ImportantMail\TelegramNotifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramNotifierTest extends TestCase
{
    private string $tempEnv;

    protected function setUp(): void
    {
        $this->tempEnv = tempnam(sys_get_temp_dir(), 'tg_env_');
        file_put_contents(
            $this->tempEnv,
            "TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11\nTELEGRAM_HOME_CHANNEL=7254076228\n",
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnv)) {
            unlink($this->tempEnv);
        }
    }

    public function testFormatEscapesHtmlAndHandlesNullSubject(): void
    {
        $msg = new Message();
        $msg->fromName    = 'Receita Federal <aviso>';
        $msg->fromAddress = 'noreply@receita.fazenda.gov.br';
        $msg->subject     = 'Declaração IRPF <b>2026</b> & Débito';

        $html = TelegramNotifier::format($msg, ImportanceKind::Government);

        self::assertStringContainsString('📧 <b>Governo</b>', $html);
        self::assertStringContainsString('&lt;aviso&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;2026&lt;/b&gt; &amp; Débito', $html);
        self::assertStringNotContainsString('<b>2026</b>', $html); // tag unescaped must not exist
    }

    public function testFormatWithNullSubjectUsesPlaceholder(): void
    {
        $msg = new Message();
        $msg->fromAddress = 'mae@gmail.com';
        $msg->subject     = null;

        $html = TelegramNotifier::format($msg, ImportanceKind::Family);
        self::assertStringContainsString('(sem assunto)', $html);
    }

    public function testSendPostsToTelegramBotApi(): void
    {
        $called = false;
        $mock   = new MockHttpClient(function ($method, $url, $options) use (&$called) {
            $called = true;
            self::assertSame('POST', $method);
            self::assertSame('https://api.telegram.org/bot123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11/sendMessage', $url);

            $body = json_decode($options['body'] ?? '{}', true);
            self::assertSame('7254076228', (string) ($body['chat_id'] ?? ''));
            self::assertSame('HTML', $body['parse_mode'] ?? '');
            self::assertSame('📧 Olá', $body['text'] ?? '');

            return new MockResponse('{"ok":true,"result":{"message_id":1}}', ['http_code' => 200]);
        });

        $notifier = new TelegramNotifier($mock, $this->tempEnv);
        $notifier->send('📧 Olá');

        self::assertTrue($called);
    }

    public function testSendThrowsOnTelegramError(): void
    {
        $mock = new MockHttpClient(new MockResponse('{"ok":false,"description":"Bad Request: chat not found"}', ['http_code' => 400]));

        $notifier = new TelegramNotifier($mock, $this->tempEnv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chat not found');

        $notifier->send('📧 Falha');
    }

    public function testThrowsWhenEnvFileMissing(): void
    {
        $mock     = new MockHttpClient();
        $notifier = new TelegramNotifier($mock, '/caminho/inexistente/env');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não encontrado');

        $notifier->send('📧 Teste');
    }

    public function testThrowsWhenTokenMissingInEnv(): void
    {
        file_put_contents($this->tempEnv, "OUTRA_VAR=123\n");
        $mock     = new MockHttpClient();
        $notifier = new TelegramNotifier($mock, $this->tempEnv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TELEGRAM_BOT_TOKEN');

        $notifier->send('📧 Teste');
    }
}

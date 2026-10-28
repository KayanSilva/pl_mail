<?php

declare(strict_types=1);

namespace App\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Entity\Mail\Message;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dispatches immediate Telegram alerts through the Bot API using credentials
 * mounted read-only from ~/.hermes/.env.
 *
 * Never logs or exposes the bot token in error messages or logs.
 */
final readonly class TelegramNotifier
{
    public function __construct(
        private HttpClientInterface $http,
        private string $envFile,
    ) {
    }

    public static function format(Message $message, ImportanceKind $kind): string
    {
        $subject = $message->subject;
        $subjectClean = (null !== $subject && '' !== trim($subject))
            ? htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '(sem assunto)';

        $fromName = $message->fromName;
        $fromAddr = (string) $message->fromAddress;

        if (null !== $fromName && '' !== trim($fromName)) {
            $sender = sprintf(
                '%s &lt;%s&gt;',
                htmlspecialchars($fromName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($fromAddr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        } else {
            $sender = htmlspecialchars($fromAddr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return sprintf(
            "📧 <b>%s</b>\nDe: %s\nAssunto: %s",
            $kind->labelPtBr(),
            $sender,
            $subjectClean,
        );
    }

    public function send(string $htmlText): void
    {
        [$token, $chatId] = $this->readCredentials();

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $token);

        try {
            $response = $this->http->request('POST', $url, [
                'json' => [
                    'chat_id'                  => $chatId,
                    'text'                     => $htmlText,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Falha de rede ao contatar Telegram Bot API: %s', $e->getMessage()), 0, $e);
        }

        if (200 !== $status || !(bool) ($body['ok'] ?? false)) {
            $desc = (string) ($body['description'] ?? 'erro desconhecido');
            throw new RuntimeException(sprintf('Telegram retornou HTTP %d: %s', $status, $desc));
        }
    }

    /**
     * @return array{0: string, 1: string} [token, chatId]
     */
    private function readCredentials(): array
    {
        if (!file_exists($this->envFile)) {
            throw new RuntimeException(sprintf('Arquivo de credenciais Telegram não encontrado em: %s', $this->envFile));
        }

        $content = (string) file_get_contents($this->envFile);
        $dotenv  = new Dotenv();
        $vars    = $dotenv->parse($content);

        $token = trim((string) ($vars['TELEGRAM_BOT_TOKEN'] ?? ''));
        if ('' === $token) {
            throw new RuntimeException('Variável TELEGRAM_BOT_TOKEN ausente ou vazia no arquivo de ambiente');
        }

        // Prefer TELEGRAM_HOME_CHANNEL, fallback to first user in TELEGRAM_ALLOWED_USERS
        $chatId = trim((string) ($vars['TELEGRAM_HOME_CHANNEL'] ?? ''));
        if ('' === $chatId) {
            $allowed = trim((string) ($vars['TELEGRAM_ALLOWED_USERS'] ?? ''));
            if ('' !== $allowed) {
                $parts  = explode(',', $allowed);
                $chatId = trim($parts[0]);
            }
        }

        if ('' === $chatId) {
            throw new RuntimeException('Nenhum chat id encontrado (TELEGRAM_HOME_CHANNEL ou TELEGRAM_ALLOWED_USERS)');
        }

        return [$token, $chatId];
    }
}

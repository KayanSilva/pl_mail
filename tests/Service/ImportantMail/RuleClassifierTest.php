<?php

declare(strict_types=1);

namespace App\Tests\Service\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Service\ImportantMail\RuleClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Rules that tell whether an incoming message is important without asking a
 * language model: government, bill or family.
 *
 * Precedence: Family > Government > Bill.
 */
final class RuleClassifierTest extends TestCase
{
    private RuleClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RuleClassifier(
            govDomains: ['gov.br', 'jus.br', 'leg.br', 'mp.br'],
            govSenderWords: ['receita', 'inss', 'detran', 'prefeitura', 'tribunal'],
            billWords: ['boleto', 'fatura', 'vencimento', '2ª via', 'segunda via', 'cobrança'],
            billSenderWords: ['nubank', 'itau', 'bradesco', 'santander', 'inter', 'caixa', 'enel', 'sabesp', 'vivo', 'claro', 'tim', 'condom'],
        );
    }

    public function testFamilyWinsOverEverything(): void
    {
        // Even if the family member wrote with "fatura" in the subject,
        // it is classified as Family.
        self::assertSame(
            ImportanceKind::Family,
            $this->classifier->classify('mae@gmail.com', 'Fatura do cartão', 'veja a conta', isFamily: true),
        );
    }

    public function testGovDomainMatchesBySuffixOnly(): void
    {
        // Direct domain and subdomains match
        self::assertSame(
            ImportanceKind::Government,
            $this->classifier->classify('notifica@receita.fazenda.gov.br', 'Declaração IRPF', 'aviso', false),
        );
        self::assertSame(
            ImportanceKind::Government,
            $this->classifier->classify('servico@gov.br', 'Notificação gov.br', 'aviso', false),
        );

        // Substring spoofing is rejected: x@gov.br.evil.com or x@evilgov.br
        self::assertNull(
            $this->classifier->classify('golpe@gov.br.evil.com', 'Importante', 'texto', false),
        );
        self::assertNull(
            $this->classifier->classify('golpe@evilgov.br', 'Importante', 'texto', false),
        );
    }

    public function testGovBySenderWordInDisplayNameOrAddress(): void
    {
        self::assertSame(
            ImportanceKind::Government,
            $this->classifier->classify('naoresponda@inss.previdencia.org', 'Benefício', 'aviso', false),
        );
    }

    public function testBillByKeywordInSubject(): void
    {
        self::assertSame(
            ImportanceKind::Bill,
            $this->classifier->classify('cobranca@empresa.com.br', 'Sua fatura de outubro vence amanhã', 'link', false),
        );
        self::assertSame(
            ImportanceKind::Bill,
            $this->classifier->classify('auto@loja.com', 'Boleto para pagamento', 'codigo', false),
        );
    }

    public function testBillByBankSender(): void
    {
        self::assertSame(
            ImportanceKind::Bill,
            $this->classifier->classify('aviso@nubank.com.br', 'Fechamento do mês', 'detalhes', false),
        );
    }

    public function testNewsletterWithBoletoWordInBodyOnlyIsNotBill(): void
    {
        // Keyword only in body with an unknown sender does not qualify as Bill,
        // to avoid newsletters and discussion lists spamming alerts.
        self::assertNull(
            $this->classifier->classify('newsletter@tech.com', 'As 5 notícias da semana', 'falamos sobre boleto bancário no fim', false),
        );
    }

    public function testUppercaseAndWhitespaceAreNormalized(): void
    {
        self::assertSame(
            ImportanceKind::Government,
            $this->classifier->classify('  FOO@RECEITA.FAZENDA.GOV.BR ', 'NOTIFICAÇÃO', null, false),
        );
    }

    public function testNullSubjectAndBodyDoNotThrow(): void
    {
        self::assertNull(
            $this->classifier->classify('desconhecido@example.com', null, null, false),
        );
    }
}

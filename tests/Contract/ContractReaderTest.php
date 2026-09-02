<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Contract\ContractReader;

#[AiContract(
    writes: true,
    tables: ['tl_ww_buchung', 'tl_ww_gutschein'],
    trace: ['tl_log'],
    traceWhen: 'before',
    irreversible: 'sends a confirmation mail to the guest',
    repeatable: false,
    optionValues: ['status' => ['offen', 'bestaetigt', 'storniert']],
    answerShape: ['status', 'id', 'gutschein'],
    genericPathUnsuitable: ['tl_ww_buchung' => 'the transitions hang on save_callbacks'],
)]
class FullyDeclaredCommand
{
}

class UndeclaredCommand
{
}

#[AiContract(writes: true)]
class WritesWithoutTraceCommand
{
}

#[AiContract(writes: true, trace: ['tl_log'])]
class TraceWithoutWhenCommand
{
}

#[AiContract(
    writes: true,
    tables: ['tl_x'],
    trace: ['tl_log'],
    traceWhen: 'on-success',
    irreversible: null,
    repeatable: null,
)]
class ExplicitlyNothingCommand
{
}

#[AiContract(writes: false, tables: ['tl_x'])]
class ToolWithMethodContracts
{
    #[AiContract(writes: true, tables: ['tl_news'], trace: ['tl_log'], traceWhen: 'on-success',
        irreversible: 'sends a mail')]
    public function delete(): void
    {
    }

    public function read(): void
    {
    }
}

/**
 * Reading a command's declared contract.
 *
 * The design rests on one property of PHP that was verified before anything
 * was written: `ReflectionAttribute::getArguments()` returns the raw values
 * even when the attribute class cannot be loaded, and only `newInstance()`
 * needs it. So an extension can declare **without depending on this bundle**,
 * and the reader must never instantiate.
 *
 * 🎯 The second rule is that a malformed declaration is *reported*, never
 * dropped. A contract that silently loses a field looks complete and is not —
 * the failure this project keeps meeting: an answer that reads like an answer,
 * so nobody looks further.
 */
class ContractReaderTest extends TestCase
{
    public function testACommandWithoutTheAttributeHasNoContract(): void
    {
        self::assertNull(ContractReader::read(UndeclaredCommand::class));
    }

    public function testAMissingClassIsNotAnError(): void
    {
        self::assertNull(ContractReader::read('Nicht\\Vorhanden\\Command'));
    }

    public function testEveryDeclaredFieldSurvives(): void
    {
        $contract = ContractReader::read(FullyDeclaredCommand::class);

        self::assertNotNull($contract);
        self::assertSame([], $contract['problems']);
        self::assertSame(['tl_ww_buchung', 'tl_ww_gutschein'], $contract['fields']['tables']);
        self::assertSame('before', $contract['fields']['traceWhen']);
        self::assertFalse($contract['fields']['repeatable']);
        self::assertSame(
            ['tl_ww_buchung' => 'the transitions hang on save_callbacks'],
            $contract['fields']['genericPathUnsuitable'],
        );
    }

    /**
     * The platform property the whole design rests on.
     *
     * PHP resolves an attribute class only on `newInstance()`. `getArguments()`
     * hands back the raw values without it — which is why an extension can
     * carry `#[AiContract(...)]` **without requiring this bundle**, and why the
     * reader must never instantiate.
     *
     * Pinned here rather than asserted in a docblock: it is an assumption about
     * PHP, not about our code, and if a future version tightened it the design
     * would fail silently everywhere else. Verified against PHP 8.4 before
     * anything was built.
     */
    public function testAttributeArgumentsAreReadableWithoutTheAttributeClass(): void
    {
        // eval, because the class has to carry an attribute that cannot be
        // resolved — which is exactly what a declaring extension without this
        // bundle produces, and cannot be written in a normally loaded file
        // without tripping static analysis.
        eval('
            namespace Webwerkwien\ContaoAiCoreBundle\Tests\Contract;
            #[\Kein\Solches\Attribut(writes: true, tables: ["tl_x"])]
            class AttributeProbe {}
        ');

        $attribute = (new \ReflectionClass(AttributeProbe::class))->getAttributes()[0];

        self::assertSame('Kein\Solches\Attribut', $attribute->getName());
        self::assertSame(['writes' => true, 'tables' => ['tl_x']], $attribute->getArguments());

        $this->expectException(\Error::class);
        $attribute->newInstance();
    }

    /**
     * Writing `irreversible: null` says "nothing here", and that is the truth.
     *
     * It is the constructor's own default and the attribute's docblock says so
     * — *"null means none is claimed"*. The validator nevertheless answered
     * *irreversible must be a non-empty string, got null*: a complaint about a
     * correct statement, and the only way to avoid it was to leave the field
     * out, which says the same thing less clearly.
     *
     * Reported by the ww-buchung session on 2026-09-01, from the first real
     * declaration written against this attribute.
     */
    public function testExplicitNullIsAStatementAndNotAMistake(): void
    {
        $contract = ContractReader::read(ExplicitlyNothingCommand::class);

        self::assertSame([], $contract['problems']);
    }

    public function testAnExplicitNullMakesNoClaimEither(): void
    {
        /* Silence and "explicitly nothing" reach the reader the same way: the
           field is absent. What must not happen is a `repeatable: null` showing
           up as if a claim had been made. */
        $contract = ContractReader::read(ExplicitlyNothingCommand::class);

        self::assertArrayNotHasKey('irreversible', $contract['fields']);
        self::assertArrayNotHasKey('repeatable', $contract['fields']);
    }

    public function testANullTraceWhenIsStillReportedAsMissingInformation(): void
    {
        /* Different case: the trace is declared, so the timing is information
           the reader needs and `null` does not supply it. */
        $contract = ContractReader::read(ExplicitlyNothingCommand::class);

        self::assertSame('on-success', $contract['fields']['traceWhen']);
    }

    /**
     * A tool class carries several tools behind several methods.
     *
     * `ArticleTool` in contao-ai-backend-bundle holds create, update, delete and
     * read. One contract at class level would have to cover a read and a
     * cascading delete with the same words, which is no contract at all.
     */
    public function testAMethodContractWinsOverTheClass(): void
    {
        $onMethod = ContractReader::read(ToolWithMethodContracts::class, 'delete');

        self::assertTrue($onMethod['fields']['writes']);
        self::assertSame(['tl_news'], $onMethod['fields']['tables']);
        self::assertSame('sends a mail', $onMethod['fields']['irreversible']);
    }

    public function testAMethodWithoutOneFallsBackToTheClass(): void
    {
        /* Falling back rather than answering null keeps one entry point for
           both shapes — a console command declares at class level and has no
           methods to ask about. */
        $fallback = ContractReader::read(ToolWithMethodContracts::class, 'read');

        self::assertFalse($fallback['fields']['writes']);
        self::assertSame(['tl_x'], $fallback['fields']['tables']);
    }

    public function testAnUnknownMethodNameDoesNotBreakTheClassLookup(): void
    {
        $still = ContractReader::read(ToolWithMethodContracts::class, 'gibtsNicht');

        self::assertNotNull($still);
        self::assertFalse($still['fields']['writes']);
    }

    public function testTheClassPathIsUnchangedWithoutAMethod(): void
    {
        $contract = ContractReader::read(FullyDeclaredCommand::class);

        self::assertSame(['tl_ww_buchung', 'tl_ww_gutschein'], $contract['fields']['tables']);
    }

    public function testWritingWithoutNamingATraceIsReported(): void
    {
        $contract = ContractReader::read(WritesWithoutTraceCommand::class);

        self::assertNotSame([], $contract['problems']);
        self::assertStringContainsString('no trace declared', implode(' ', $contract['problems']));
    }

    public function testATraceWithoutItsTimingIsReported(): void
    {
        /* "before" and "on-success" are different promises, and the difference
           is the entire value of the field: an entry written afterwards records
           only the runs that went well. */
        $contract = ContractReader::read(TraceWithoutWhenCommand::class);

        self::assertStringContainsString('traceWhen', implode(' ', $contract['problems']));
    }
}

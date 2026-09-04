<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\ErrorReport;
use Webwerkwien\ContaoAiCoreBundle\Service\ErrorReportBuilder;

/**
 * What these tests are actually for.
 *
 * A report is generated at the worst possible moment — something has already
 * gone wrong, so the assumption that everything else behaved is exactly the one
 * that does not hold. The interesting question is therefore never "does it
 * render", it is **"can anything reach the summary that was not put there on
 * purpose"**.
 *
 * Hence {@see testSummaryCarriesOnlyTheAllowListedKeys()}, which fails when a
 * field is *added*. A test that only checks the fields it knows about approves
 * of every new one — which is how a leak gets in past a green suite.
 */
class ErrorReportBuilderTest extends TestCase
{
    private const SECRET = 'sk-proj-abcdefghijklmnopqrstuvwxyz0123456789';

    private function builder(): ErrorReportBuilder
    {
        return new ErrorReportBuilder();
    }

    /** Thrown from inside a `Webwerkwien\…` class so the trace has own frames. */
    private function throwable(string $message = 'Etwas ging schief'): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    /**
     * The guard. Adding a field to the summary must break this on purpose.
     */
    public function testSummaryCarriesOnlyTheAllowListedKeys(): void
    {
        $report = $this->builder()->build($this->throwable(), 'backend', [
            'tool'          => 'news_update',
            'call_id'       => 'call_42',
            'platform'      => 'anthropic',
            'argument_keys' => ['id' => 1, 'headline' => 'geheim'],
        ]);

        self::assertSame(
            ['argument_schluessel', 'aufruf_id', 'ausnahme', 'komponente', 'plattform', 'umgebung', 'versionen', 'werkzeug', 'zeitpunkt'],
            $this->sortedKeys($report->summary()),
            'Ein neues Feld im Kurzbericht muss hier auffallen — sonst genehmigt der Test jede Erweiterung.',
        );
    }

    public function testArgumentValuesNeverReachTheReport(): void
    {
        $report = $this->builder()->build($this->throwable(), 'backend', [
            'argument_keys' => ['id' => 7, 'text' => self::SECRET],
        ]);

        self::assertSame(['id', 'text'], $report->summary()['argument_schluessel']);
        self::assertStringNotContainsString(self::SECRET, $report->toMarkdown(true));
        self::assertStringNotContainsString(self::SECRET, $report->toBbCode(true));
    }

    /**
     * A caller that hands over the argument array as a plain list must not turn
     * the values into "keys" — `array_keys()` on a list yields 0,1,2.
     */
    public function testAListOfKeysIsTakenAsKeysNotAsValues(): void
    {
        $report = $this->builder()->build($this->throwable(), 'cli', [
            'argument_keys' => ['id', 'headline'],
        ]);

        self::assertSame(['id', 'headline'], $report->summary()['argument_schluessel']);
    }

    public function testTheExceptionMessageIsMasked(): void
    {
        $report = $this->builder()->build(
            $this->throwable('Anfrage mit ' . self::SECRET . ' fehlgeschlagen'),
            'cli',
        );

        self::assertStringContainsString('sk-***', $report->toMarkdown(true));
        self::assertStringNotContainsString(self::SECRET, $report->toMarkdown(true));
    }

    public function testALiteralSecretIsStruckEvenWithoutAMatchingPattern(): void
    {
        $opaque = 'Ff8kQz2mWx7bVn4t';

        $report = $this->builder()->build(
            $this->throwable('Schlüssel ' . $opaque . ' abgelehnt'),
            'cli',
            [],
            [$opaque],
        );

        self::assertStringNotContainsString($opaque, $report->toMarkdown(true));
    }

    /**
     * The short report is what may travel without being asked for. If the
     * message can be read off it, the whole two-tier split is decorative.
     */
    public function testTheShortReportDoesNotContainTheMessage(): void
    {
        $report = $this->builder()->build($this->throwable('Datenbank nicht erreichbar'), 'backend');

        self::assertStringNotContainsString('Datenbank nicht erreichbar', $report->toMarkdown());
        self::assertStringNotContainsString('Datenbank nicht erreichbar', $report->toBbCode());
        self::assertStringContainsString('Datenbank nicht erreichbar', $report->toMarkdown(true));
    }

    public function testWithoutMessageDropsItEvenFromTheFullRendering(): void
    {
        $report = $this->builder()
            ->build($this->throwable('Datenbank nicht erreichbar'), 'backend')
            ->withoutMessage();

        self::assertFalse($report->hasMessage());
        self::assertStringNotContainsString('Datenbank nicht erreichbar', $report->toMarkdown(true));
    }

    public function testPathsAreShortenedSoTheyDoNotDescribeTheServer(): void
    {
        $report   = $this->builder()->build($this->throwable(), 'core');
        $rendered = $report->toMarkdown(true);

        self::assertStringNotContainsString('/var/www/', $rendered);
        self::assertStringNotContainsString('C:/Users', $rendered);
        self::assertStringNotContainsString('C:\\Users', $rendered);
        self::assertStringContainsString('ErrorReportBuilderTest.php', $rendered);
    }

    public function testForeignFramesAreCollapsedAndOwnFramesKept(): void
    {
        $report = $this->builder()->build($this->throwable(), 'core');
        $trace  = $report->toMarkdown(true);

        self::assertStringContainsString('außerhalb von contao-ai', $trace);
        self::assertStringNotContainsString('PHPUnit\\Framework\\TestCase', $trace);
    }

    public function testVersionsAreDetectedForInstalledPackagesOnly(): void
    {
        $versions = $this->builder()->build($this->throwable(), 'core')->summary()['versionen'];

        self::assertArrayHasKey('core', $versions);
        self::assertArrayHasKey('contao', $versions);
        // The backend bundle is not installed here — an absent package must be
        // dropped, not reported as an empty string and not thrown over.
        self::assertArrayNotHasKey('backend', $versions);
    }

    public function testTheCliPassesItsOwnVersionThrough(): void
    {
        $versions = $this->builder()
            ->build($this->throwable(), 'cli', ['versions' => ['cli' => '0.15.0']])
            ->summary()['versionen'];

        self::assertSame('0.15.0', $versions['cli']);
    }

    public function testBothRenderingsCarryTheNotice(): void
    {
        $report = $this->builder()->build($this->throwable(), 'cli');

        self::assertStringContainsString(ErrorReport::NOTICE, $report->toMarkdown());
        self::assertStringContainsString(ErrorReport::NOTICE, $report->toBbCode());
    }

    /**
     * Everything variable belongs inside `[CODE]`, because vBulletin converts
     * `:p` and friends to smilies outside it — and a colon followed by a letter
     * is ordinary in exception messages and Windows paths.
     */
    public function testBbCodeKeepsEverythingVariableInsideACodeBlock(): void
    {
        $bbCode = $this->builder()
            ->build($this->throwable('Pfad C:\\p und :o kaputt'), 'cli')
            ->toBbCode(true);

        $body = substr($bbCode, (int) strpos($bbCode, '[CODE]'));

        self::assertStringContainsString('[/CODE]', $body);
        // Nothing variable may appear before the code block opens.
        $head = substr($bbCode, 0, (int) strpos($bbCode, '[CODE]'));
        self::assertStringNotContainsString(':o', $head);
        self::assertStringNotContainsString('RuntimeException', $head);
    }

    public function testAnEmptyContextIsEnough(): void
    {
        $report = $this->builder()->build($this->throwable(), 'core');

        self::assertArrayNotHasKey('werkzeug', $report->summary());
        self::assertArrayNotHasKey('argument_schluessel', $report->summary());
        self::assertNotSame('', $report->toMarkdown());
    }

    /**
     * @param  array<string, mixed> $summary
     * @return list<string>
     */
    private function sortedKeys(array $summary): array
    {
        $keys = array_keys($summary);
        sort($keys);

        return $keys;
    }
}

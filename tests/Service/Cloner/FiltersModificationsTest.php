<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\FiltersModifications;

/**
 * Pins the fix for the silently dropped --modifications found on 2026-08-29.
 *
 * A clone call carrying {"published":"","hide":"1"} had both keys discarded by
 * the whitelist without a word in the response. The clones inherited
 * `published = 1` from their source and stood publicly visible for about three
 * minutes. The command had reported `status: ok`.
 *
 * Two things have to hold now: dropped keys are named back to the caller, and
 * `published` is accepted — but normalised, because an empty string in a
 * tinyint column is what v0.2.10 already had to fix once.
 */
class FiltersModificationsTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use FiltersModifications;

            /**
             * @param array<string, scalar|null> $modifications
             * @param list<string>               $allowed
             *
             * @return array{accepted: array<string, scalar|null>, ignored: list<string>}
             */
            public function partition(array $modifications, array $allowed): array
            {
                return $this->partitionModifications($modifications, $allowed);
            }

            public function flag(mixed $value): string
            {
                return $this->normaliseFlag($value);
            }
        };
    }

    public function testNamesTheKeysItRefuses(): void
    {
        $result = $this->subject()->partition(
            ['title' => 'Testseite', 'published' => '', 'hide' => '1'],
            ['title', 'published'],
        );

        $this->assertSame(['title' => 'Testseite', 'published' => ''], $result['accepted']);
        $this->assertSame(['hide'], $result['ignored'], 'A refused key must be reported, not swallowed.');
    }

    public function testReportsNothingWhenEverythingIsAccepted(): void
    {
        $result = $this->subject()->partition(['title' => 'X'], ['title', 'pageTitle']);

        $this->assertSame(['title' => 'X'], $result['accepted']);
        $this->assertSame([], $result['ignored']);
    }

    /**
     * The exact call from 2026-08-29. Empty string means "not published" to a
     * caller, but writing '' into tl_page.published throws on a server with
     * strict SQL mode — the v0.2.10 failure. It has to become '0'.
     *
     * @dataProvider falsyFlags
     */
    public function testTreatsEmptyAndZeroAsNotPublished(mixed $input): void
    {
        $this->assertSame('0', $this->subject()->flag($input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function falsyFlags(): iterable
    {
        yield 'empty string (the 2026-08-29 call)' => [''];
        yield 'string zero'                        => ['0'];
        yield 'integer zero'                       => [0];
        yield 'boolean false'                      => [false];
        yield 'null'                               => [null];
    }

    /**
     * @dataProvider truthyFlags
     */
    public function testTreatsAnythingElseAsPublished(mixed $input): void
    {
        $this->assertSame('1', $this->subject()->flag($input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function truthyFlags(): iterable
    {
        yield 'string one'   => ['1'];
        yield 'integer one'  => [1];
        yield 'boolean true' => [true];
    }
}

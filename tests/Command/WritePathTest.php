<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * No command persists a record on its own. Persistence goes through the writer.
 *
 * 🎯 This test exists because {@see CreateCommandConversionTest} did its job and
 * that turned out to be the problem: it guards the *create* family, so the same
 * omission simply happened somewhere else. An audit on 2026-09-02 found seven
 * commands writing straight to the model —
 *
 *   contao:user:delete          $user->delete()      no tl_undo, no cascade
 *   contao:member:delete        $member->delete()    same
 *   contao:page:publish         $page->save()        no tl_version
 *   contao:comment:publish      $comment->save()     same
 *   contao:user:update          raw assign + save()  no version, no conversion
 *   contao:member:update        raw assign + save()  same
 *   contao:files:meta           $file->save()        no version
 *
 * — and all seven for the same structural reason: they look their record up by
 * username or path instead of by id, so they could never extend
 * `AbstractModelUpdateCommand` / `AbstractModelDeleteCommand` and inherit the
 * path. The shortcut was not carelessness; it was the only thing available.
 *
 * What it cost each time was exactly what the base class brings: the version
 * snapshot, the undo entry, the cascade, the DCA conversion. `contao:user:update
 * --set groups=1,2` wrote the literal string into a serialized column and
 * reported `ok`.
 *
 * ⚠️ **The lesson is about the test, not the code.** A test that pins a class of
 * bug pins it only where it looks. This one therefore takes every command, not a
 * naming family.
 *
 * ## Why it strips comments first
 *
 * The fixes for those seven quote the old line in a comment — *"hier stand
 * `$user->delete()`"* — because a fix without its reason is a line nobody dares
 * touch later. A source scan that reads comments would find those quotes and
 * fail on the very files that were repaired. So the source is tokenised and
 * comments are dropped before anything is matched.
 */
class WritePathTest extends TestCase
{
    /**
     * Commands allowed to persist by themselves, each with the reason.
     *
     * A name lands here only when going through the writer is impossible, not
     * when it is inconvenient. Both entries below write something that is not a
     * DCA record at all, so there is no version, no undo and no cascade to owe.
     */
    private const EXCUSED = [
        // Not records: no version, no undo, no cascade to owe.
        'SettingsUpdateCommand.php' => 'writes localconfig.php, not a table',
        'TemplateWriteCommand.php'  => 'writes a file on disk, not a record',
        'FileWriteCommand.php'      => 'writes file bytes, not a record',
    ];

    /**
     * @return array<string, string> file name => source with comments removed
     */
    private function commandSources(): array
    {
        $files = glob(__DIR__ . '/../../src/Command/*Command.php');
        self::assertIsArray($files);

        $out = [];

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (\is_array($token)) {
                    if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                    continue;
                }
                $code .= $token;
            }

            $out[basename($file)] = $code;
        }

        return $out;
    }

    public function testTheScanSeesTheCommandsAtAll(): void
    {
        // A source scan that matches nothing passes exactly like one that matches
        // everything. This project has produced that failure three times in one
        // day, so every scan here carries a counter.
        $sources = $this->commandSources();

        self::assertGreaterThan(40, \count($sources), 'the command scan found almost nothing');
        self::assertArrayHasKey('UserUpdateCommand.php', $sources);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commands(): iterable
    {
        foreach ((new self('scan'))->commandSources() as $name => $code) {
            yield $name => [$name, $code];
        }
    }

    /**
     * @dataProvider commands
     */
    public function testNoCommandPersistsARecordItself(string $name, string $code): void
    {
        if (isset(self::EXCUSED[$name])) {
            self::assertTrue(true, self::EXCUSED[$name]);

            return;
        }

        // Creates persist themselves, and that is correct rather than tolerated:
        // `RecordWriterInterface` has exactly two methods, `update()` and
        // `delete()`. A new record has no prior state to snapshot and no undo
        // entry to file, so there is no writer path to take. Their own risk —
        // storing an unconverted value — is guarded by
        // {@see CreateCommandConversionTest}.
        //
        // 🎯 Measured when this test was first run: 22 of 22 failures were
        // create commands and not one was anything else. That is the useful part
        // of the number — outside the create family the code had no strays left
        // once the seven were repaired.
        if (str_ends_with($name, 'CreateCommand.php')) {
            self::assertStringNotContainsString(
                'writer()->delete(',
                $code,
                "$name is a create command and must not delete",
            );

            return;
        }

        // `$something->save()` / `$something->delete()` on a variable. Method
        // calls on `$this` are the command's own helpers and stay out.
        preg_match_all('/\$(?!this\b)(\w+)->(save|delete)\s*\(/', $code, $matches, PREG_SET_ORDER);

        $offenders = [];

        foreach ($matches as [, $var, $method]) {
            $offenders[] = '$' . $var . '->' . $method . '()';
        }

        self::assertSame(
            [],
            $offenders,
            \sprintf(
                '%s persists a record itself (%s). Route it through $this->writer()->update()/delete() '
                . 'so the version snapshot, undo entry and cascade happen — or add it to EXCUSED with a reason.',
                $name,
                implode(', ', $offenders),
            ),
        );
    }

    public function testTheSevenRepairedCommandsUseTheWriter(): void
    {
        // The specific regression. Named rather than derived, because "uses the
        // writer" is what these seven were missing and a generic rule would not
        // notice if one of them quietly stopped.
        $repaired = [
            'UserDeleteCommand.php', 'MemberDeleteCommand.php',
            'PagePublishCommand.php', 'CommentPublishCommand.php',
            'UserUpdateCommand.php', 'MemberUpdateCommand.php',
            'FileMetaUpdateCommand.php',
        ];

        $sources = $this->commandSources();

        foreach ($repaired as $name) {
            self::assertArrayHasKey($name, $sources, "$name disappeared");
            self::assertStringContainsString(
                'writer()->',
                $sources[$name],
                "$name no longer routes through the writer",
            );
        }
    }

    public function testEveryCommandThatUsesTheWriterInitialisesTheFramework(): void
    {
        // 🎯 Found on c5, not here. The H-3 fix routed
        // `contao:news:repair-headlines` through the writer, and the writer
        // needs `$GLOBALS['TL_MODELS']`. The command had only ever taken a
        // `Connection` — raw SQL needs no framework — so the real run died with
        // *"There is no class for table tl_news registered"* while 603 tests
        // stayed green.
        //
        // ⚠️ What made it slip through twice: `--dry-run` writes nothing and
        // therefore never reaches the writer. The dry run answered `ok` and
        // even listed the record it would repair. A rehearsal that skips the
        // one step that fails is not a rehearsal.
        //
        // Routing a command through the writer is now a two-part move: take the
        // writer *and* initialise the framework. This pins the second half.
        $sources     = $this->commandSources();
        $usingWriter = [];
        $missing     = [];

        foreach ($sources as $name => $code) {
            if (!str_contains($code, 'writer()->')) {
                continue;
            }

            $usingWriter[] = $name;

            if (!str_contains($code, 'framework->initialize()')) {
                $missing[] = $name;
            }
        }

        // The scan has to find something, or it certifies nothing.
        self::assertGreaterThanOrEqual(
            10,
            \count($usingWriter),
            'the writer scan found almost nothing — it used to find 10',
        );

        self::assertSame(
            [],
            $missing,
            \sprintf(
                '%s calls the writer without $this->framework->initialize(). The writer resolves the model '
                . 'class from $GLOBALS[\'TL_MODELS\'], which is empty until the framework is initialised — '
                . 'and --dry-run will not notice.',
                implode(', ', $missing),
            ),
        );
    }

    public function testTheTwoUsernameUpdatesStillConvertTheirFields(): void
    {
        // `--set groups=1,2` has to become a serialized list. Without this the
        // string lands in the column and the command reports success — the exact
        // shape CreateCommandConversionTest guards against for create commands.
        $sources = $this->commandSources();

        foreach (['UserUpdateCommand.php', 'MemberUpdateCommand.php'] as $name) {
            self::assertStringContainsString(
                'convertFields(',
                $sources[$name],
                "$name writes --set fields without DCA conversion",
            );
        }
    }
}

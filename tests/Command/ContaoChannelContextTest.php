<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Every line this bundle writes to one of Contao's own log channels carries a context.
 *
 * Without one, Contao's processor fills the columns from the request, and on the
 * console there is none: the line reads FE / N/A. Nr. 59 fixed `file folder-publish`,
 * then Nr. 66 (2026-09-17) found `settings update` doing the same, and the sweep that
 * followed two more (`undo restore`, the upload downscale). So this checks all of src/.
 */
class ContaoChannelContextTest extends TestCase
{
    public function testEveryContaoChannelLineCarriesAContext(): void
    {
        $unattributed = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $source = self::code((string) file_get_contents($file->getPathname()));
            if (!str_contains($source, "'monolog.logger.contao.")) {
                continue;
            }

            // Any variable a Contao channel is assigned to, however the container is reached.
            preg_match_all('/(\$\w+)\s*=\s*[^;]*->get\(\'monolog\.logger\.contao\./', $source, $assigned);
            $variables = array_unique($assigned[1]);
            $markers     = ['logContext(', 'ContaoContext(', '$this->context('];
            if (preg_match('/\$context\s*=\s*\$this->logContext\(/', $source)) {
                $markers[] = '$context)';
            }

            foreach (explode(';', $source) as $statement) {
                if (!preg_match('/->(info|notice|warning|error)\(/', $statement)) {
                    continue;
                }

                $onContaoChannel = str_contains($statement, "'monolog.logger.contao.");
                foreach ($variables as $variable) {
                    $onContaoChannel = $onContaoChannel || (bool) preg_match('/' . preg_quote($variable, '/') . '->(info|notice|warning|error)\(/', $statement);
                }

                $attributed = array_filter($markers, static fn (string $m): bool => str_contains($statement, $m));

                if ($onContaoChannel && [] === $attributed) {
                    $unattributed[] = basename($file->getPathname()) . ': ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        $this->assertSame([], $unattributed, 'a line on a Contao channel without a context reads FE / N/A');
    }

    private static function code(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}

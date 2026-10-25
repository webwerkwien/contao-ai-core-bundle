<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Apply;

/**
 * `@name` in a plan for `contao:ai:apply`: the ID an earlier step produced (v1.13.0).
 *
 * Recognised in two places and nowhere else, so that text keeps every `@` it had:
 *
 * 1. a whole value: `"pid": "@versand.article"`
 * 2. the argument of an insert tag: `{{link_url::@versand}}`, up to `}}` or `|`
 *
 * An e-mail address, a mention or `href="@x"` is never touched. Internal links in
 * Contao go through insert tags anyway, so the second form covers them.
 */
final class ApplyReferences
{
    private const NAME   = '[a-z0-9_-]+(?:\.article)?';
    private const WHOLE  = '/^@(' . self::NAME . ')$/';
    private const IN_TAG = '/(\{\{[^{}:|]+::)@(' . self::NAME . ')(?=\}\}|\|)/';

    /**
     * Every reference in a step, in order of appearance; `do` and `ref` are names, not values.
     *
     * @param array<string, mixed> $step
     *
     * @return list<string>
     */
    public static function find(array $step): array
    {
        unset($step['do'], $step['ref']);

        $found = [];

        array_walk_recursive($step, static function (mixed $value) use (&$found): void {
            if (!\is_string($value)) {
                return;
            }

            if (1 === preg_match(self::WHOLE, $value, $m)) {
                $found[] = $m[1];

                return;
            }

            if (preg_match_all(self::IN_TAG, $value, $m) > 0) {
                array_push($found, ...$m[2]);
            }
        });

        return $found;
    }

    /**
     * @param array<string, int> $ids name => ID, `name.article` included
     *
     * @throws \InvalidArgumentException for a name not in $ids
     */
    public static function resolve(mixed $value, array $ids): mixed
    {
        if (\is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::resolve($v, $ids), $value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        if (1 === preg_match(self::WHOLE, $value, $m)) {
            return self::id($m[1], $ids);
        }

        return preg_replace_callback(self::IN_TAG, static fn (array $m): string => $m[1] . self::id($m[2], $ids), $value);
    }

    /**
     * @param array<string, int> $ids
     */
    private static function id(string $name, array $ids): int
    {
        return $ids[$name] ?? throw new \InvalidArgumentException(\sprintf(
            '"@%s" has no ID: no earlier step produced it%s.',
            $name,
            str_ends_with($name, '.article') ? ' (the page create answered without an article)' : '',
        ));
    }
}

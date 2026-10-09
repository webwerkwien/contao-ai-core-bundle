<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Page;

use Doctrine\DBAL\Connection;

/**
 * Keeps a page where Contao's back end lets it stand.
 *
 * Contao enforces the shape of the tree in `PageTypeAccessVoter` — a permission
 * voter the console write path never asks:
 *
 * - a website root stands at the top level (`pid` 0), and nothing else does
 *   (`validateRootType()`);
 * - an error page stands directly below a website root, at most one of each type
 *   per root (`validateFirstLevelType()`).
 *
 * Checked before the write, on create and on every update that changes `type` or
 * `pid`. An update that touches neither is not checked, so a page already in the
 * wrong place stays editable. See PageTreeRulesTest (v1.2.0).
 */
final class PageTreeRules
{
    /** `PageTypeAccessVoter::FIRST_LEVEL_TYPES`, identical in Contao 5.3, 5.7 and 6.0. */
    public const FIRST_LEVEL_TYPES = ['error_401', 'error_403', 'error_404', 'error_503'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $fields the values being written
     * @param array<string, mixed> $stored the stored page on an update, empty on a create
     *
     * @throws \InvalidArgumentException naming the rule
     */
    public function assertPlacement(array $fields, array $stored = [], ?int $id = null): void
    {
        $type = (string) ($fields['type'] ?? $stored['type'] ?? 'regular');
        $pid  = (int) ($fields['pid'] ?? $stored['pid'] ?? 0);

        if ([] !== $stored && $type === (string) ($stored['type'] ?? '') && $pid === (int) ($stored['pid'] ?? 0)) {
            return;
        }

        if ('root' === $type && 0 !== $pid) {
            throw new \InvalidArgumentException(\sprintf(
                'A website root (type root) can only stand at the top level, not below page %d. Nothing was written.',
                $pid,
            ));
        }

        if ('root' !== $type && 0 === $pid) {
            throw new \InvalidArgumentException(\sprintf(
                'Only a website root (type root) can stand at the top level, not a page of type %s. '
                . 'Give the page a parent (pid) below a website root. Nothing was written.',
                $type,
            ));
        }

        if (!\in_array($type, self::FIRST_LEVEL_TYPES, true)) {
            return;
        }

        if ('root' !== $this->connection->fetchOne('SELECT type FROM tl_page WHERE id = ?', [$pid])) {
            throw new \InvalidArgumentException(\sprintf(
                'An error page (%s) must stand directly below a website root, and page %d is none. Nothing was written.',
                $type,
                $pid,
            ));
        }

        $other = $this->connection->fetchOne(
            'SELECT id FROM tl_page WHERE pid = ? AND type = ? AND id != ? LIMIT 1',
            [$pid, $type, $id ?? 0],
        );

        if (false !== $other && null !== $other) {
            throw new \InvalidArgumentException(\sprintf(
                'Website root %d already has a page of type %s (ID %d); a root can have only one. Nothing was written.',
                $pid,
                $type,
                (int) $other,
            ));
        }
    }
}

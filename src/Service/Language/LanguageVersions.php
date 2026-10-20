<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Language;

use Doctrine\DBAL\Connection;

/**
 * The other language versions of a record, as terminal42/contao-changelanguage links
 * them (v1.11.0). Reads only.
 *
 * The package links a version to its counterpart in the main language through
 * `languageMain`; the counterpart itself has none. So the versions of a record are the
 * record it points to, or itself when it points nowhere, plus everything that points
 * there. Elements are not linked: their page and article are.
 *
 * Why this exists: on showcase a chat changed the English slider headline and then told
 * the user the page existed in English only — the German start page, linked to it, had
 * the same slider (2026-10-20). Nothing it could call answered "which versions does this
 * page have", so it guessed. `content:find` and `language:versions` answer it now.
 *
 * Only registered when the package is installed: without it there is no `languageMain`.
 * What it cannot see are versions nobody linked — `roots()` names the other website
 * roots, so a caller can say that one may exist instead of saying there is none.
 */
class LanguageVersions
{
    /**
     * The tables whose records the package links, with their label column and the
     * archive table whose jumpTo page tells the language of an entry.
     *
     * @var array<string, array{string, string|null}>
     */
    public const TABLES = [
        'tl_page'            => ['title', null],
        'tl_article'         => ['title', null],
        'tl_news'            => ['headline', 'tl_news_archive'],
        'tl_calendar_events' => ['title', 'tl_calendar'],
        'tl_faq'             => ['question', 'tl_faq_category'],
    ];

    /** @var array<int, array<string, mixed>|null> */
    private array $pages = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The record with its root and language, and its linked versions; null when the
     * record does not exist.
     *
     * @return array{record: array<string, mixed>, linked: bool, main: int, versions: list<array<string, mixed>>, mainMissing?: true}|null
     */
    public function of(string $table, int $id): ?array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException(\sprintf('Language versions exist for %s, not for "%s".', implode(', ', array_keys(self::TABLES)), $table));
        }
        [$label] = self::TABLES[$table];

        $record = $this->connection->fetchAssociative("SELECT id, pid, $label AS title, published, languageMain FROM $table WHERE id = ?", [$id]);
        if (false === $record) {
            return null;
        }

        $main = (int) $record['languageMain'] ?: $id;
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, pid, $label AS title, published FROM $table WHERE id = ? OR languageMain = ? ORDER BY id",
            [$main, $main],
        );

        $versions = [];
        $mainFound = false;
        foreach ($rows as $row) {
            $mainFound = $mainFound || (int) $row['id'] === $main;
            if ((int) $row['id'] !== $id) {
                $versions[] = $this->describe($table, $row) + ['main' => (int) $row['id'] === $main];
            }
        }

        $answer = [
            'record'   => $this->describe($table, $record),
            'linked'   => [] !== $versions,
            'main'     => $main,
            'versions' => $versions,
        ];
        if (!$mainFound) {
            $answer['mainMissing'] = true; // languageMain points to nothing; `language check` reports it as invalid
        }

        return $answer;
    }

    /**
     * All website roots with their language, in the order of the site structure.
     *
     * @return list<array{id: int, title: string, language: string, dns: string, fallback: bool}>
     */
    public function roots(): array
    {
        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'language' => (string) $r['language'],
            'dns'      => (string) $r['dns'],
            'fallback' => (bool) $r['fallback'],
        ], $this->connection->fetchAllAssociative("SELECT id, title, language, dns, fallback FROM tl_page WHERE type = 'root' ORDER BY sorting, id"));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function describe(string $table, array $row): array
    {
        $root = $this->rootOf($this->pageOf($table, $row));

        return [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'published' => (bool) $row['published'],
            'language'  => null === $root ? null : (string) $root['language'],
            'root'      => null === $root ? null : (int) $root['id'],
        ];
    }

    /**
     * The page whose root tells the language: the page itself, the article's page, or
     * the jumpTo page of an entry's archive (0 when the archive has none).
     *
     * @param array<string, mixed> $row
     */
    private function pageOf(string $table, array $row): int
    {
        return match ($table) {
            'tl_page'    => (int) $row['id'],
            'tl_article' => (int) $row['pid'],
            default      => (int) ($this->connection->fetchOne('SELECT jumpTo FROM ' . self::TABLES[$table][1] . ' WHERE id = ?', [(int) $row['pid']]) ?: 0),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rootOf(int $pageId): ?array
    {
        $page = $this->page($pageId);
        for ($depth = 0; null !== $page && 'root' !== $page['type'] && $depth < 50; ++$depth) {
            $page = (int) $page['pid'] > 0 ? $this->page((int) $page['pid']) : null;
        }

        return $page;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (!\array_key_exists($id, $this->pages)) {
            $row = $this->connection->fetchAssociative('SELECT id, pid, type, language FROM tl_page WHERE id = ?', [$id]);
            $this->pages[$id] = false === $row ? null : $row;
        }

        return $this->pages[$id];
    }
}

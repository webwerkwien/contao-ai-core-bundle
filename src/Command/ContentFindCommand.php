<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Input;
use Contao\InputEncodingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;

/**
 * Finds content elements by their text, with where they sit: article, page, root and
 * its language (v1.10.0).
 *
 * The way an agent found an element until now was the search index (`contao:search:query`,
 * which answers pages, not elements) or listing tables one by one. Both fail where it
 * matters: a page with `noindex` is not indexed — Contao's SearchIndexListener and the
 * crawler skip it, so on a staging site or a showcase the index stays empty — and listing
 * took a chat on showcase 9–10 calls and half a minute to change one slider headline
 * (2026-10-19). This searches `tl_content` itself.
 *
 * Since v1.11.0 each hit names the linked versions of its page and article in the other
 * languages (`versions`), where terminal42/contao-changelanguage is installed: a chat on
 * showcase had changed the English headline and claimed there was no other language, while
 * the linked German page had the same slider (2026-10-20). Without the package there are no
 * links to read; on a site with several roots the answer says so (`versionsNote`).
 *
 * Read-only, and like every command here it trusts its operator: it does not ask
 * Contao's voters. The backend bundle's `content_find` drops what the user may not read.
 */
#[AsCommand(name: 'contao:content:find', description: 'Find content elements by their text (headline, text, HTML), with article, page, root and language')]
class ContentFindCommand extends AbstractReadCommand
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;
    private const MIN_LENGTH    = 3;

    /** The text columns searched; all three are core columns of tl_content. */
    private const FIELDS = ['headline', 'text', 'html'];

    /** @var array<int, array<string, mixed>|null> */
    private array $pages = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LanguageVersions $versions = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'Text to find, at least ' . self::MIN_LENGTH . ' characters; matched anywhere, case-insensitive');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max results (1–' . self::MAX_LIMIT . ')', self::DEFAULT_LIMIT);
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Only elements below this root page (website root), e.g. the one of a language');
    }

    protected function doExecute(): int
    {
        $query = trim((string) $this->input->getArgument('query'));
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return $this->outputError(\sprintf('The search text needs at least %d characters.', self::MIN_LENGTH));
        }
        $limit = max(1, min(self::MAX_LIMIT, (int) $this->input->getOption('limit')));
        $root  = null === $this->input->getOption('root') ? null : (int) $this->input->getOption('root');

        // A root that is no root would find nothing, silently; 0 or text would find only
        // the elements without one (Fable review 2026-10-20).
        if (null !== $root && 'root' !== ($this->page($root)['type'] ?? null)) {
            return $this->outputError(\sprintf('Page %d is not a website root (type root); --root takes the id of one.', $root));
        }

        // A text typed as the page shows it is searched in the forms Contao stores: as
        // typed, as a text field stores it (Input::encodeInput, `(` as `&#40;`, `'` as
        // `&#39;` — Fable review 2026-10-20) and as the rich text editor does (`&amp;`).
        $needles = self::needles($query);

        $where = [];
        $params = [];
        foreach (self::FIELDS as $field) {
            foreach ($needles as $needle) {
                // LOWER(): case-insensitive also where a column's collation is binary
                $where[] = "LOWER($field) LIKE LOWER(?)";
                $params[] = '%' . addcslashes($needle, '%_\\') . '%';
            }
        }

        // With --root the filter runs after the query (the root comes from the page
        // tree), so more rows are read than asked for.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pid, ptable, type, headline, text, html, invisible FROM tl_content WHERE ' . implode(' OR ', $where)
            . ' ORDER BY id LIMIT ' . (null === $root ? $limit + 1 : self::MAX_LIMIT * 10),
            $params,
        );

        $results = [];
        $more = false;
        foreach ($rows as $row) {
            $location = $this->locate((string) $row['ptable'], (int) $row['pid']);
            if (null !== $root && (int) ($location['root']['id'] ?? 0) !== $root) {
                continue;
            }
            $match = self::match($row, $query, $needles);
            if (null === $match) {
                continue; // the text only in a serialized headline's frame ("value", "unit")
            }
            if (\count($results) === $limit) {
                $more = true;
                break;
            }
            [$field, $snippet] = $match;
            $hit = [
                'id'        => (int) $row['id'],
                'type'      => (string) $row['type'],
                'field'     => $field,
                'snippet'   => $snippet,
                'invisible' => (bool) $row['invisible'],
                'ptable'    => (string) $row['ptable'],
                'pid'       => (int) $row['pid'],
            ] + $location;
            $versions = $this->versionsOf($location);
            $results[] = null === $versions ? $hit : $hit + ['versions' => $versions];
        }

        $answer = [
            'query'   => $query,
            'count'   => \count($results),
            'more'    => $more,
            'results' => $results,
        ];

        // Which languages the site has, so "the other versions" has an answer even where
        // nothing is linked.
        $roots = $this->connection->fetchAllAssociative("SELECT id, title, language FROM tl_page WHERE type = 'root' ORDER BY sorting, id");
        if (\count($roots) > 1) {
            $answer['roots'] = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'language' => (string) $r['language']], $roots);
            if (null === $this->versions) {
                $answer['versionsNote'] = 'terminal42/contao-changelanguage is not installed: versions in other languages are not linked and cannot be listed. The other roots may hold the same content; do not say there is none.';
            }
        }

        $this->outputRecord($answer);

        return self::SUCCESS;
    }

    /**
     * The linked versions of the hit's page and article, or of its news entry or event;
     * null without changelanguage. An empty list means none is linked.
     *
     * @param array<string, mixed> $location
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function versionsOf(array $location): ?array
    {
        if (null === $this->versions) {
            return null;
        }

        $of = fn (string $table, int $id): array => $this->versions->of($table, $id)['versions'] ?? [];

        if (isset($location['article'])) {
            return [
                'page'    => isset($location['page']['id']) ? $of('tl_page', (int) $location['page']['id']) : [],
                'article' => $of('tl_article', (int) $location['article']['id']),
            ];
        }

        $parent = $location['parent'] ?? null;
        if (\is_array($parent) && \in_array($parent['table'] ?? null, ['tl_news', 'tl_calendar_events'], true)) {
            return ['entry' => $of((string) $parent['table'], (int) $parent['id'])];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function needles(string $query): array
    {
        return array_values(array_unique([
            $query,
            Input::encodeInput($query, InputEncodingMode::encodeAll, false),
            htmlspecialchars($query, ENT_QUOTES),
        ]));
    }

    /**
     * The field that holds the text and the words around it, as plain text; null when the
     * text sits only in a serialized headline's frame (`value`, `unit`), not in any value.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $needles
     *
     * @return array{string, string}|null
     */
    private static function match(array $row, string $query, array $needles): ?array
    {
        foreach (self::FIELDS as $field) {
            $text = self::plain($field, (string) ($row[$field] ?? ''));
            $at = mb_stripos($text, $query);
            if (false !== $at) {
                $start = max(0, $at - 60);

                return [$field, ($start > 0 ? '…' : '') . mb_substr($text, $start, mb_strlen($query) + 120) . ($start + mb_strlen($query) + 120 < mb_strlen($text) ? '…' : '')];
            }
        }

        // Matched only in markup (an attribute, a link) or in an encoded form: the field
        // whose stored value holds it — the headline's value, not its serialized frame.
        // Until the Fable review this took the first non-empty field and called "value" a
        // hit in every headline.
        foreach (self::FIELDS as $field) {
            $raw = (string) ($row[$field] ?? '');
            if ('headline' === $field) {
                $pair = @unserialize($raw, ['allowed_classes' => false]);
                $raw = \is_array($pair) ? (string) ($pair['value'] ?? '') : $raw;
            }
            foreach ($needles as $needle) {
                if ('' !== $raw && false !== mb_stripos($raw, $needle)) {
                    return [$field, mb_substr(self::plain($field, (string) $row[$field]), 0, 180)];
                }
            }
        }

        return null;
    }

    private static function plain(string $field, string $value): string
    {
        if ('headline' === $field) {
            $pair = @unserialize($value, ['allowed_classes' => false]);
            $value = \is_array($pair) ? (string) ($pair['value'] ?? '') : $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * Where an element sits: up through nested elements to its article, news entry or
     * event; for an article the page and the root with its language.
     *
     * @return array<string, mixed>
     */
    private function locate(string $ptable, int $pid): array
    {
        for ($depth = 0; 'tl_content' === $ptable && $depth < 10; ++$depth) {
            $parent = $this->connection->fetchAssociative('SELECT ptable, pid FROM tl_content WHERE id = ?', [$pid]);
            if (false === $parent) {
                return ['parent' => ['table' => 'tl_content', 'id' => $pid]];
            }
            [$ptable, $pid] = [(string) $parent['ptable'], (int) $parent['pid']];
        }

        if ('tl_article' === $ptable || '' === $ptable) {
            $article = $this->connection->fetchAssociative('SELECT id, pid, title, inColumn, published FROM tl_article WHERE id = ?', [$pid]);
            if (false === $article) {
                return ['article' => null];
            }
            [$page, $root] = $this->pageAndRoot((int) $article['pid']);

            return [
                'article' => ['id' => (int) $article['id'], 'title' => (string) $article['title'], 'column' => (string) $article['inColumn'], 'published' => (bool) $article['published']],
                'page'    => $page,
                'root'    => $root,
            ];
        }

        $label = match ($ptable) {
            'tl_news'            => 'headline',
            'tl_calendar_events' => 'title',
            default              => null,
        };
        $parent = null === $label ? false : $this->connection->fetchAssociative("SELECT id, $label AS label FROM $ptable WHERE id = ?", [$pid]);

        return ['parent' => ['table' => $ptable, 'id' => $pid] + (false === $parent ? [] : ['title' => (string) $parent['label']])];
    }

    /**
     * @return array{array<string, mixed>|null, array<string, mixed>|null}
     */
    private function pageAndRoot(int $pageId): array
    {
        $page = $this->page($pageId);
        if (null === $page) {
            return [null, null];
        }

        $root = $page;
        for ($depth = 0; 'root' !== $root['type'] && (int) $root['pid'] > 0 && $depth < 50; ++$depth) {
            $up = $this->page((int) $root['pid']);
            if (null === $up) {
                break;
            }
            $root = $up;
        }

        return [
            ['id' => (int) $page['id'], 'title' => (string) $page['title'], 'alias' => (string) $page['alias'], 'published' => (bool) $page['published']],
            'root' === $root['type'] ? ['id' => (int) $root['id'], 'title' => (string) $root['title'], 'language' => (string) $root['language'], 'dns' => (string) $root['dns']] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(int $id): ?array
    {
        if (!\array_key_exists($id, $this->pages)) {
            $row = $this->connection->fetchAssociative('SELECT id, pid, type, title, alias, language, dns, published FROM tl_page WHERE id = ?', [$id]);
            $this->pages[$id] = false === $row ? null : $row;
        }

        return $this->pages[$id];
    }
}

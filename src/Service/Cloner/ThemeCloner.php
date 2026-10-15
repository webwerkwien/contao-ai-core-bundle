<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Cloner;

use Contao\StringUtil;

/**
 * Clones a theme with everything in it, and points the copies at each other (v1.9.0).
 *
 * Contao does not copy themes: `tl_theme` is `notCopyable` in 5.3, 5.7 and 6.0, and
 * `DC_Table::copy()` refuses it. The back end's way to a second theme is export and import
 * (`.cto`). This clone does that step at once — and goes further than a plain row copy
 * would: a copy of the rows alone would leave the copied layouts placing the modules of
 * the OLD theme, a copied news list opening the old reader, a copied module using the old
 * image size, and whoever edited the copy would change the live site. (Until the
 * pre-release review this docblock said Contao's own copy behaved like that; it has none.)
 *
 * This copy bends the references that point INTO the theme to their copies:
 *
 * - `tl_layout.modules` (moduleWizard): module ids and theme elements (`content-<id>`)
 * - `tl_module.rootPageDependentModules`: the same references, keyed by website root
 * - fields that name a module: a `relation`/`foreignKey` to tl_module in the DCA, or a
 *   field named `…readerModule` (news, events and FAQ; the news bundle declares neither)
 * - fields relating to tl_content (`cteAlias`): a theme element aliasing another one
 * - image size fields (`inputType` imageSize) whose size is one of the theme's
 *
 * References that point OUT of the theme stay: pages keep their layouts, articles their
 * module elements, modules their pages and archives. The copy is unused until someone
 * assigns its layouts; nothing on the site changes. The theme's template folder and
 * files are not copied either — the copy uses the same folder (`references`).
 *
 * Versions and oncopy callbacks run once the references are bent, so a version shows
 * the record as it ends up; children first, the theme last, as DC_Table does.
 */
class ThemeCloner extends AbstractDcaCloner
{
    /** Copied in this order: modules and layouts point at image sizes and theme elements. */
    private const PARTS = ['tl_image_size', 'tl_content', 'tl_module', 'tl_layout'];

    protected function table(): string
    {
        return 'tl_theme';
    }

    protected function nameField(): string
    {
        return 'name';
    }

    /**
     * @return array{id: int, table: string, count: int, children: array<string, int>, remapped: object, not_copied: list<string>, references: list<string>, ignored_modifications: list<string>, note: string}
     */
    public function clone(int $sourceId, array $modifications, string $operator, array $options = []): array
    {
        $this->framework->initialize();

        $source = $this->fetchSourceRow('tl_theme', $sourceId);

        ['accepted' => $accepted, 'ignored' => $ignored] = $this->partitionModifications($modifications, ['name']);

        // `tl_theme.name` is `unique` in the DCA: a second "(Kopie)" would be refused by the
        // back end on the next save. A name the caller chose is refused here if it is taken;
        // the default counts on.
        if (\array_key_exists('name', $accepted)) {
            $name = trim((string) $accepted['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('"name" darf nicht leer sein.');
            }
            if ($this->themeNameTaken($name)) {
                throw new \InvalidArgumentException(\sprintf('Ein Theme namens "%s" gibt es schon.', $name));
            }
        } else {
            $base = ((string) ($source['name'] ?? '')) . ' (Kopie';
            $name = $base . ')';
            for ($n = 2; $this->themeNameTaken($name); ++$n) {
                $name = $base . ' ' . $n . ')';
            }
        }

        return $this->connection->transactional(function () use ($source, $sourceId, $name, $ignored, $operator): array {
            ['id' => $newId, 'row' => $clone] = $this->insertCopy('tl_theme', $source, ['name' => $name]);

            /** @var array<string, array<int, int>> $map old id => new id, per table */
            $map = array_fill_keys([...self::PARTS, 'tl_image_size_item'], []);

            foreach ($this->rows('tl_image_size', $sourceId) as $row) {
                $map['tl_image_size'][(int) $row['id']] = $this->insertCopy('tl_image_size', $row, ['pid' => $newId])['id'];

                foreach ($this->rows('tl_image_size_item', (int) $row['id']) as $item) {
                    $map['tl_image_size_item'][(int) $item['id']] = $this->insertCopy('tl_image_size_item', $item, ['pid' => $map['tl_image_size'][(int) $row['id']]])['id'];
                }
            }

            $this->copyElements($sourceId, $newId, 'tl_theme', $map);

            foreach (['tl_module', 'tl_layout'] as $table) {
                foreach ($this->rows($table, $sourceId) as $row) {
                    $map[$table][(int) $row['id']] = $this->insertCopy($table, $row, ['pid' => $newId])['id'];
                }
            }

            $remapped = $this->bendReferences($map);

            // Versions and callbacks once the rows are final: children first, the theme last.
            foreach (['tl_image_size_item', ...self::PARTS] as $table) {
                foreach ($map[$table] as $oldId => $copyId) {
                    $this->firstVersion($table, $copyId, $operator);
                    $this->dcaCallbacks?->copy($table, $copyId, $oldId);
                }
            }
            $this->firstVersion('tl_theme', $newId, $operator);
            $this->dcaCallbacks?->copy('tl_theme', $newId, $sourceId);

            $children = [];
            foreach ([...self::PARTS, 'tl_image_size_item'] as $table) {
                $children[$table] = \count($map[$table]);
            }

            $references = $this->references('tl_theme', $clone);
            if (!self::isEmpty($clone['templates'] ?? null) && !\in_array('templates', $references, true)) {
                $references[] = 'templates';
            }

            return [
                'id'       => $newId,
                'table'    => 'tl_theme',
                'count'    => $children['tl_module'],
                'children' => $children,
                // "table.field" => how many copies now point at a copy instead of the original.
                'remapped' => (object) $remapped,
                'not_copied' => $this->notCopied($source, $clone, 'name'),
                'references' => $references,
                'ignored_modifications' => $ignored,
                'note' => 'Contao itself offers no theme copy in the back end (only export and import). '
                    . 'This clone does that step: references inside the theme now point at the copies (see remapped); '
                    . 'references out of it stay, so no page uses the copy until its layouts are assigned.',
            ];
        });
    }

    private function themeNameTaken(string $name): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM tl_theme WHERE name = ?', [$name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, int $pid, ?string $ptable = null): array
    {
        $order = isset($this->fields($table)['sorting']) ? 'sorting, id' : 'id';
        $sql   = 'SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE pid = ?';
        $args  = [$pid];

        if (null !== $ptable) {
            $sql   .= ' AND ptable = ?';
            $args[] = $ptable;
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY ' . $order, $args);
    }

    /**
     * Theme elements with the elements nested inside them (accordions, element groups).
     *
     * @param array<string, array<int, int>> $map
     */
    private function copyElements(int $sourcePid, int $newPid, string $ptable, array &$map): void
    {
        foreach ($this->rows('tl_content', $sourcePid, $ptable) as $row) {
            $copyId = $this->insertCopy('tl_content', $row, ['pid' => $newPid])['id'];
            $map['tl_content'][(int) $row['id']] = $copyId;
            $this->copyElements((int) $row['id'], $copyId, 'tl_content', $map);
        }
    }

    /**
     * @param array<string, array<int, int>> $map
     *
     * @return array<string, int> "table.field" => rows changed
     */
    private function bendReferences(array $map): array
    {
        $remapped = [];

        foreach (['tl_module', 'tl_layout', 'tl_content'] as $table) {
            $fields = $this->fields($table);

            foreach ($map[$table] as $copyId) {
                $row     = $this->connection->fetchAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = ?', [$copyId]);
                $changes = [];

                foreach ($fields as $field => $definition) {
                    if (!\is_array($definition) || !\array_key_exists($field, $row ?: []) || self::isEmpty($row[$field])) {
                        continue;
                    }

                    $value = $row[$field];
                    $new   = match (true) {
                        'moduleWizard' === ($definition['inputType'] ?? null)            => self::bendModuleWizard($value, $map),
                        'rootPageDependentSelect' === ($definition['inputType'] ?? null) => self::bendRootPageDependent($value, $map),
                        'imageSize' === ($definition['inputType'] ?? null)               => self::bendImageSize($value, $map['tl_image_size']),
                        self::namesModule((string) $field, $definition)                  => self::bendIds($value, $map['tl_module']),
                        // An element aliasing another theme element (`cteAlias`); an article
                        // element is not in the map and stays.
                        'tl_content' === ($definition['relation']['table'] ?? null)      => self::bendIds($value, $map['tl_content']),
                        default                                                          => $value,
                    };

                    if ($new !== $value) {
                        $changes[$this->connection->quoteIdentifier((string) $field)] = $new;
                        $remapped[$table . '.' . $field] = ($remapped[$table . '.' . $field] ?? 0) + 1;
                    }
                }

                if ([] !== $changes) {
                    $this->connection->update($this->connection->quoteIdentifier($table), $changes, ['id' => $copyId]);
                }
            }
        }

        ksort($remapped);

        return $remapped;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function namesModule(string $field, array $definition): bool
    {
        return 'tl_module' === ($definition['relation']['table'] ?? null)
            || str_starts_with((string) ($definition['foreignKey'] ?? ''), 'tl_module.')
            || str_ends_with($field, 'readerModule');
    }

    /**
     * A single id or a serialized list of ids; ids that are not in the theme stay.
     *
     * @param array<int, int> $map
     */
    private static function bendIds(mixed $value, array $map): mixed
    {
        if (is_numeric($value)) {
            return isset($map[(int) $value]) ? (string) $map[(int) $value] : $value;
        }

        $list = StringUtil::deserialize($value);
        if (!\is_array($list)) {
            return $value;
        }

        $bent = array_map(static fn ($id) => is_numeric($id) && isset($map[(int) $id]) ? (string) $map[(int) $id] : $id, $list);

        return $bent === $list ? $value : serialize($bent);
    }

    /**
     * `[{"mod": "12", "col": "main", "enable": "1"}, {"mod": "content-7", …}]`, serialized.
     *
     * @param array<string, array<int, int>> $map
     */
    private static function bendModuleWizard(mixed $value, array $map): mixed
    {
        $rows = StringUtil::deserialize($value);
        if (!\is_array($rows)) {
            return $value;
        }

        $bent = $rows;
        foreach ($bent as $i => $entry) {
            if (\is_array($entry) && isset($entry['mod'])) {
                $bent[$i]['mod'] = self::bendModuleRef((string) $entry['mod'], $map);
            }
        }

        return $bent === $rows ? $value : serialize($bent);
    }

    /**
     * `rootPageDependentModules`: `[rootPageId => "12" | "content-7"]`, serialized — the
     * module (or, since 5.4, theme element) shown below each website root. The field has
     * neither `relation` nor `foreignKey`; found by the pre-release review.
     *
     * @param array<string, array<int, int>> $map
     */
    private static function bendRootPageDependent(mixed $value, array $map): mixed
    {
        $byRoot = StringUtil::deserialize($value);
        if (!\is_array($byRoot)) {
            return $value;
        }

        $bent = array_map(static fn ($mod) => \is_scalar($mod) ? self::bendModuleRef((string) $mod, $map) : $mod, $byRoot);

        return $bent === $byRoot ? $value : serialize($bent);
    }

    /**
     * One module reference as the theme manager stores it: a module id, or `content-<id>`
     * for a theme element. Not in the theme: unchanged.
     *
     * @param array<string, array<int, int>> $map
     */
    private static function bendModuleRef(string $mod, array $map): string
    {
        if (str_starts_with($mod, 'content-')) {
            $id = (int) substr($mod, 8);

            return isset($map['tl_content'][$id]) ? 'content-' . $map['tl_content'][$id] : $mod;
        }

        return is_numeric($mod) && isset($map['tl_module'][(int) $mod]) ? (string) $map['tl_module'][(int) $mod] : $mod;
    }

    /**
     * `[width, height, size]`, serialized; `size` names an image size by id when it is
     * one of the database's (otherwise a mode like `crop` or a configured `_name`).
     *
     * @param array<int, int> $sizes
     */
    private static function bendImageSize(mixed $value, array $sizes): mixed
    {
        $size = StringUtil::deserialize($value);
        if (!\is_array($size) || !isset($size[2]) || !is_numeric($size[2]) || !isset($sizes[(int) $size[2]])) {
            return $value;
        }

        $size[2] = (string) $sizes[(int) $size[2]];

        return serialize($size);
    }
}

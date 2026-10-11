<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Webwerkwien\ContaoAiCoreBundle\Command\ReadsDcaOptions;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

/**
 * The values a field offers — static, or asked from its own options callback.
 *
 * Until v0.16.0 the CLI answered page types from a hard-coded list (bundle page
 * types were missing) and could not list custom templates at all. See
 * OptionsResolverTest.
 *
 * A callback is called as the back end would, with a DC_Table built without its
 * constructor — no request, no back-end user — carrying the table, an id and the
 * active record, the same way CacheTagInvalidator calls its callbacks. What throws
 * is unresolvable: `values()` answers null and `lastError()` says why. Nothing is
 * guessed.
 */
class OptionsResolver
{
    use ReadsDcaOptions;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ?CliUser $cliUser = null,
    ) {
    }

    /**
     * The stored values a field allows — what its widget would offer.
     *
     * Public API since v1.4.2 (maintainer, 2026-10-11, asked for by Consho): the
     * writer persists without checking a value against the field's options, so an
     * extension's command that should refuse what the widget would refuse asks here
     * instead of rebuilding the callback call. Null means "cannot say", not "nothing
     * allowed" — the field is unknown, has no options, its callback threw (lastError()
     * says why) or returned no array: skip the check for that field, never guess. The
     * values are strings; compare `(string) $value`. On the console the callback runs
     * as `contao-ai`, the user the write runs as. Signatures stay stable within 1.x.
     *
     * @param array<string, mixed> $activeRecord the record the options are for, e.g. `['type' => 'text']`
     *
     * @return list<string>|null the values, or null when the field has no options or they cannot be resolved
     *
     * @api
     */
    public function values(string $table, string $field, array $activeRecord = [], int $id = 0): ?array
    {
        $options = $this->options($table, $field, $activeRecord, $id);

        // `options()` has loaded the DCA. The eval travels along: an `isAssociative`
        // list stores its index, whether the options are static or a callback's (Nr. 53).
        return null === $options ? null : $this->optionValues([
            'options' => $options,
            'eval'    => $GLOBALS['TL_DCA'][$table]['fields'][$field]['eval'] ?? [],
        ]);
    }

    /**
     * The options as the callback returned them — labels and groups included.
     *
     * @param array<string, mixed> $activeRecord
     *
     * @return array<array-key, mixed>|null
     */
    public function options(string $table, string $field, array $activeRecord = [], int $id = 0): ?array
    {
        unset($this->errors[$table . '.' . $field]);

        try {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
                $this->framework->initialize();
                Controller::loadDataContainer($table);
            }
        } catch (\Throwable $e) {
            // Unresolvable like a throwing callback: values() promises null, not an exception.
            $this->errors[$table . '.' . $field] = $e->getMessage();

            return null;
        }

        $def = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? null;

        if (!\is_array($def)) {
            return null;
        }

        $callback = $def['options_callback'] ?? null;

        if (null === $callback) {
            return isset($def['options']) && \is_array($def['options']) ? $def['options'] : null;
        }

        try {
            // The labels the back end has loaded before it builds a widget's options.
            // changelanguage's master options format theirs from tl_news_archive's
            // language file, and sprintf() got null on the console (c5, 5.3,
            // 2026-10-10). Without a booted kernel (unit tests) there is nothing to load.
            if (null !== System::getContainer()) {
                System::loadLanguageFile('default');
                System::loadLanguageFile($table);
            }

            $dc   = RecordDataContainer::create($table, $id, $activeRecord);
            $call = static fn (): mixed => \is_array($callback)
                ? System::importStatic($callback[0])->{$callback[1]}($dc)
                : $callback($dc);
            // On the console the callback sees `contao-ai`, the user the write runs
            // as — a permission-filtered list answers empty for the anonymous non-admin
            // instead of throwing. Where someone is logged in, that someone (CliUser).
            $result = null === $this->cliUser ? $call() : $this->cliUser->asCurrentUser($call);
        } catch (\Throwable $e) {
            $this->errors[$table . '.' . $field] = $e->getMessage();

            return null;
        }

        return \is_array($result) ? $result : null;
    }

    /**
     * Why the last values()/options() call for this field answered null because
     * its callback threw — null when it did not (no options, or resolved fine).
     *
     * @api since v1.4.2, see values()
     */
    public function lastError(string $table, string $field): ?string
    {
        return $this->errors[$table . '.' . $field] ?? null;
    }

}

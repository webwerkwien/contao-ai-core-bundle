<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Dca;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Webwerkwien\ContaoAiCoreBundle\Command\ReadsDcaOptions;

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

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    /**
     * @param array<string, mixed> $activeRecord the record the options are for, e.g. `['type' => 'text']`
     *
     * @return list<string>|null the values, or null when the field has no options or they cannot be resolved
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

        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            $this->framework->initialize();
            Controller::loadDataContainer($table);
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
            $dc     = RecordDataContainer::create($table, $id, $activeRecord);
            $result = \is_array($callback)
                ? System::importStatic($callback[0])->{$callback[1]}($dc)
                : $callback($dc);
        } catch (\Throwable $e) {
            $this->errors[$table . '.' . $field] = $e->getMessage();

            return null;
        }

        return \is_array($result) ? $result : null;
    }

    public function lastError(string $table, string $field): ?string
    {
        return $this->errors[$table . '.' . $field] ?? null;
    }

}

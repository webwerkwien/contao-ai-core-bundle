<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Change the global Contao settings.
 *
 * The write half of the Settings module, and the only command in this bundle
 * that does not end at the database: `Config::persist()` rewrites
 * `system/config/localconfig.php`.
 *
 * Three things this does that are not obvious, each verified against Contao's
 * own code rather than assumed:
 *
 * **1. It saves explicitly.** `Config::persist()` only marks the instance
 * modified; the file is written by `Config::__destruct()`. Relying on a
 * destructor to land a configuration change is the shape of failure this
 * project has hit repeatedly — it works until the day the process ends some
 * other way, and then reports success having written nothing. So `save()` is
 * called directly, and the file is read back afterwards to confirm the key is
 * in it. `save()` clears the modified flag, so the destructor does not write a
 * second time.
 *
 * **2. Only fields the DCA knows are accepted.** `Config::persist()` will
 * happily write any key, and nothing ever reads it back or complains. A typo
 * would put a dead variable into `localconfig.php` permanently, and this is a
 * file nobody reads by hand. Contao itself is protected by the form only
 * offering real fields; here the check has to be explicit.
 *
 * **3. A mandatory setting cannot be emptied.** `adminEmail`, the date formats
 * and the two result limits are `mandatory` in the DCA — the back end refuses
 * to save them blank, and an empty `dateFormat` breaks every date on the site.
 *
 * The log line matches Contao's, on the same channel and with the same "from
 * … to …" wording, so a change made here and one made in the back end read
 * alike. `outputSuccess()` adds this bundle's own audit entry beside it.
 *
 * ⚠️ Values are written as given. `allowedAttributes` is a `rowWizard` and
 * `defaultChmod` a `chmod` widget — both store serialized arrays. `chmod` is
 * converted from a comma-separated list like any other list field; for
 * `allowedAttributes` pass Contao's serialized form, because inventing a
 * shorthand for a nested key/value structure would be a second syntax to get
 * wrong.
 */
#[AsCommand(name: 'contao:settings:update', description: 'Change the global Contao settings (localconfig.php)')]
class SettingsUpdateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        if (!isset($GLOBALS['TL_DCA']['tl_settings']['fields'])) {
            Controller::loadDataContainer('tl_settings');
        }

        $dca = $GLOBALS['TL_DCA']['tl_settings']['fields'] ?? [];

        if ([] === $dca) {
            return $this->outputError('The tl_settings data container has no fields.');
        }

        if ([] === $fields) {
            return $this->outputError(
                'Nothing to change. Pass --set field=value; contao:settings:read lists the fields.',
            );
        }

        $unknown = array_values(array_diff(array_keys($fields), array_keys($dca)));
        if ([] !== $unknown) {
            return $this->outputError(\sprintf(
                'Not a Contao setting: %s. Nothing was written — an unknown key would sit in '
                . 'localconfig.php unread and unreported. See contao:settings:read.',
                implode(', ', $unknown),
            ));
        }

        $emptied = [];
        foreach ($fields as $field => $value) {
            if ('' === (string) $value && ($dca[$field]['eval']['mandatory'] ?? false)) {
                $emptied[] = $field;
            }
        }
        if ([] !== $emptied) {
            return $this->outputError(\sprintf(
                'These settings are mandatory and cannot be emptied: %s.',
                implode(', ', $emptied),
            ));
        }

        // `defaultChmod` is a chmod widget: a serialized list, like tl_page.chmod.
        $fields = $this->convertFields('tl_settings', $fields);

        $changed = [];
        foreach ($fields as $field => $value) {
            $previous = Config::get($field);

            if ($this->sameValue($previous, $value)) {
                continue;
            }

            Config::persist($field, $value);
            Config::set($field, StringUtil::deserialize($value));

            $changed[$field] = [
                'from' => $this->loggable($previous),
                'to'   => $this->loggable($value),
            ];
        }

        if ([] === $changed) {
            $this->outputSuccess([
                'changed'   => [],
                'unchanged' => array_keys($fields),
                'note'      => 'Every value already matched; localconfig.php was not touched.',
            ]);

            return Command::SUCCESS;
        }

        // Not left to __destruct(): see the class docblock.
        Config::getInstance()->save();

        $missing = $this->notInFile(array_keys($changed));
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'localconfig.php does not contain %s after saving. The settings were not '
                . 'persisted — check the file permissions and the disk quota.',
                implode(', ', $missing),
            ));
        }

        $this->logToContao($changed);

        $this->outputSuccess([
            'file'    => 'system/config/localconfig.php',
            'changed' => $changed,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Whether writing this would be a no-op.
     *
     * Compared loosely on purpose: `Config::get()` answers with the typed value
     * (`30`, `true`), while `--set` always arrives as a string. A strict
     * comparison would report every unchanged numeric setting as changed and
     * rewrite the file for nothing.
     */
    private function sameValue(mixed $previous, mixed $value): bool
    {
        if (\is_array($previous) || \is_array(StringUtil::deserialize($value))) {
            return serialize($previous) === serialize(StringUtil::deserialize($value));
        }

        if (\is_bool($previous)) {
            return $previous === \in_array((string) $value, ['1', 'true'], true);
        }

        return (string) $previous === (string) $value;
    }

    private function loggable(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Which of these keys `localconfig.php` does not carry after the save.
     *
     * The point of the whole exercise: a write that reported success and left
     * the file untouched would be indistinguishable from one that worked.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function notInFile(array $keys): array
    {
        try {
            $root = System::getContainer()->getParameter('kernel.project_dir');
        } catch (\Throwable) {
            return [];
        }

        $file = $root . '/system/config/localconfig.php';

        if (!is_readable($file)) {
            return $keys;
        }

        $contents = (string) file_get_contents($file);

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => !str_contains($contents, "['TL_CONFIG']['$key']"),
        ));
    }

    /**
     * Contao's own wording, channel and per-field granularity.
     *
     * `DC_File::save()` writes one line per field to `contao.configuration`,
     * naming the old and the new value — and skips the values for a password
     * field. Same here, so a change made through this command is not
     * distinguishable in the system log from one made in the back end.
     *
     * @param array<string, array{from: string, to: string}> $changed
     */
    private function logToContao(array $changed): void
    {
        try {
            $logger = System::getContainer()->get('monolog.logger.contao.configuration');
        } catch (\Throwable) {
            return;
        }

        $dca = $GLOBALS['TL_DCA']['tl_settings']['fields'] ?? [];

        foreach ($changed as $field => $values) {
            if ('password' === ($dca[$field]['inputType'] ?? null)) {
                $logger->info('The global configuration variable "' . $field . '" has been changed');
                continue;
            }

            $logger->info(\sprintf(
                'The global configuration variable "%s" has been changed from "%s" to "%s"',
                $field,
                $values['from'],
                $values['to'],
            ));
        }
    }
}

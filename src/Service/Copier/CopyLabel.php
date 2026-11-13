<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Copier;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Contao\UserModel;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The copy mark `MSC.copyOf` ("%s (Kopie)", "%s (copy)") in the language the
 * operator works in, for the copier and the cloners alike.
 *
 * In the back end the mark comes in the editor's language, because the request
 * loaded it. A console run has no request, and Contao falls back to English; so
 * the mark is looked up in the back-end language of the Contao user the operator
 * names. Contao's translator loads that language file and restores
 * `$GLOBALS['TL_LANG']` afterwards (contao/contao#5371).
 */
final class CopyLabel
{
    /** @var array<string, string|null> back-end language per operator name */
    private array $languages = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * $value with the copy mark in the operator's language. As in the back end, an
     * empty value stays empty and a value that already carries the mark is not
     * marked again (MarkAsCopy).
     */
    public function mark(string $value, string $operator): string
    {
        $marked = MarkAsCopy::apply($this->label($this->languageOf($operator)), $value, false);

        return \is_string($marked) ? $marked : $value;
    }

    /**
     * `MSC.copyOf` in $language. Without a language, or where the language has no
     * such text, the loaded language's: in the back end the editor's, on the console
     * English.
     */
    public function label(?string $language): string
    {
        if (null !== $language && '' !== $language && null !== $this->translator) {
            $label = $this->translator->trans('MSC.copyOf', [], 'contao_default', $language);

            if (str_contains($label, '%s')) {
                return $label;
            }
        }

        if (!isset($GLOBALS['TL_LANG']['MSC']['copyOf'])) {
            $this->framework->getAdapter(System::class)->loadLanguageFile('default');
        }

        $label = $GLOBALS['TL_LANG']['MSC']['copyOf'] ?? null;

        return \is_string($label) && str_contains($label, '%s') ? $label : '%s (copy)';
    }

    /** Back-end language of the Contao user named $operator, or null when there is none. */
    public function languageOf(string $operator): ?string
    {
        if ('' === $operator) {
            return null;
        }

        if (!\array_key_exists($operator, $this->languages)) {
            $user     = $this->framework->getAdapter(UserModel::class)->findOneBy('username', $operator);
            $language = \is_object($user) ? (string) ($user->language ?? '') : '';

            $this->languages[$operator] = '' !== $language ? $language : null;
        }

        return $this->languages[$operator];
    }
}

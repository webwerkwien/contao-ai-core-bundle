<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\PageCloner;

/**
 * What `--modifications` accepts for tl_page, and why.
 *
 * The list is a policy, not an accident, so it is pinned here rather than left
 * to whoever edits the constant next: **an override is accepted when it
 * controls whether and where the clone becomes visible.** Copy it, but do not
 * surface it yet — that is what cloning is for.
 *
 * Anything that grants or removes access is out of scope. Growing this list by
 * habit is exactly how a clone stops being a safe operation.
 */
class PageModificationPolicyTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function constant(string $name): array
    {
        return (new \ReflectionClass(PageCloner::class))->getConstant($name);
    }

    public function testTheVisibilityFlagsAreAccepted(): void
    {
        $allowed = $this->constant('ALLOWED_PAGE_MODIFICATIONS');

        $this->assertContains('published', $allowed, 'Clone-but-do-not-publish is the normal case.');
        $this->assertContains('hide', $allowed, 'A published clone may still need to stay out of the navigation.');
    }

    public function testTheEditorialFieldsStayAccepted(): void
    {
        $allowed = $this->constant('ALLOWED_PAGE_MODIFICATIONS');

        foreach (['title', 'pageTitle', 'description'] as $field) {
            $this->assertContains($field, $allowed);
        }
    }

    /**
     * Access control is not visibility. A `protected: ""` on the clone of a
     * protected page would publish its content to everyone — the precise
     * mistake the allow-list exists to prevent.
     *
     * @dataProvider fieldsThatMustStayOut
     */
    public function testFieldsThatChangeMoreThanVisibilityAreRefused(string $field): void
    {
        $this->assertNotContains(
            $field,
            $this->constant('ALLOWED_PAGE_MODIFICATIONS'),
            \sprintf('"%s" does not control visibility and must not be overridable on a clone.', $field),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fieldsThatMustStayOut(): iterable
    {
        yield 'protected — access control'   => ['protected'];
        yield 'groups — access control'      => ['groups'];
        yield 'type — changes what it is'    => ['type'];
        yield 'pid — changes where it sits'  => ['pid'];
        yield 'alias — always regenerated'   => ['alias'];
    }

    /**
     * A flag written verbatim puts `""` into a tinyint NOT NULL column, which is
     * the v0.2.10 failure. Adding a flag to the allow-list without also listing
     * it here would reintroduce it silently.
     */
    public function testEveryFlagIsAlsoWhitelisted(): void
    {
        $this->assertEmpty(
            array_diff($this->constant('FLAG_PAGE_MODIFICATIONS'), $this->constant('ALLOWED_PAGE_MODIFICATIONS')),
            'A flag that is normalised but not accepted can never be reached.',
        );
    }

    /**
     * The other direction: a tinyint field accepted without normalisation would
     * be written as the caller phrased it — and callers write "" for "off".
     */
    public function testTheKnownTinyintFieldsAreNormalised(): void
    {
        $flags = $this->constant('FLAG_PAGE_MODIFICATIONS');

        $this->assertContains('published', $flags);
        $this->assertContains('hide', $flags);
    }
}

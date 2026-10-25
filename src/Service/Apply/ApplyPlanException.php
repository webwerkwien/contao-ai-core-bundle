<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Apply;

/**
 * Everything wrong with a plan, not only the first thing: a caller fixing one
 * problem per run would need as many runs as there are problems.
 */
final class ApplyPlanException extends \InvalidArgumentException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service\Apply;

/**
 * The steps of one `contao:ai:apply` run, checked before anything is written (v1.13.0).
 *
 * A step names a command this bundle already has and passes its options under
 * their own names; nothing here writes. What may appear is a fixed list on
 * purpose: each table family that joins needs its own proof that it rolls back
 * with the rest.
 */
final class ApplyPlan
{
    public const MAX_STEPS = 500;

    /**
     * `do` => the console command, how it takes the record ID, its options, whether
     * it takes `set`.
     *
     * @var array<string, array{command: string, id: 'argument'|'option'|null, options: list<string>, set: bool}>
     */
    public const STEPS = [
        'page create'    => ['command' => 'contao:page:create', 'id' => null, 'options' => ['title', 'type', 'pid', 'alias', 'language', 'after'], 'set' => true],
        'page update'    => ['command' => 'contao:page:update', 'id' => 'argument', 'options' => [], 'set' => true],
        'page delete'    => ['command' => 'contao:page:delete', 'id' => 'argument', 'options' => [], 'set' => false],
        'page move'      => ['command' => 'contao:page:move', 'id' => 'argument', 'options' => ['to', 'after'], 'set' => false],
        'article create' => ['command' => 'contao:article:create', 'id' => null, 'options' => ['title', 'pid', 'after'], 'set' => true],
        'article update' => ['command' => 'contao:article:update', 'id' => 'argument', 'options' => [], 'set' => true],
        'article delete' => ['command' => 'contao:article:delete', 'id' => 'argument', 'options' => [], 'set' => false],
        'article move'   => ['command' => 'contao:article:move', 'id' => 'argument', 'options' => ['to', 'after'], 'set' => false],
        'content create' => ['command' => 'contao:content:create', 'id' => null, 'options' => ['type', 'pid', 'ptable', 'after'], 'set' => true],
        'content update' => ['command' => 'contao:content:update', 'id' => 'argument', 'options' => [], 'set' => true],
        'content delete' => ['command' => 'contao:content:delete', 'id' => 'argument', 'options' => [], 'set' => false],
        'content move'   => ['command' => 'contao:content:move', 'id' => 'argument', 'options' => ['to', 'after', 'ptable'], 'set' => false],
        'language link'  => ['command' => 'contao:language:link', 'id' => 'option', 'options' => ['table', 'main'], 'set' => false],
    ];

    private const REF = '/^[a-z0-9_-]+$/';

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function __construct(public readonly array $steps)
    {
    }

    /**
     * @throws ApplyPlanException with every problem found
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApplyPlanException(['Not valid JSON: ' . $e->getMessage()]);
        }

        if (!\is_array($data) || !\is_array($data['steps'] ?? null) || !array_is_list($data['steps'])) {
            throw new ApplyPlanException(['Expected an object with a list "steps".']);
        }

        $steps = $data['steps'];

        if ([] === $steps) {
            throw new ApplyPlanException(['No steps.']);
        }

        if (\count($steps) > self::MAX_STEPS) {
            throw new ApplyPlanException([\sprintf('%d steps; at most %d per run.', \count($steps), self::MAX_STEPS)]);
        }

        $problems = [];
        $refs     = []; // ref => `do` of the step that defines it

        foreach ($steps as $index => $step) {
            $at = 'Step ' . $index;

            if (!\is_array($step) || ([] !== $step && array_is_list($step))) {
                $problems[] = $at . ': not an object.';

                continue;
            }

            $do = $step['do'] ?? null;

            if (!\is_string($do) || !isset(self::STEPS[$do])) {
                $problems[] = \sprintf('%s: unknown "do" %s; one of: %s.', $at, json_encode($do, JSON_UNESCAPED_UNICODE), implode(', ', array_keys(self::STEPS)));

                continue;
            }

            $spec    = self::STEPS[$do];
            $at     .= ' (' . $do . ')';
            $allowed = ['do', 'ref', ...$spec['options'], ...(null === $spec['id'] ? [] : ['id']), ...($spec['set'] ? ['set'] : [])];

            foreach (array_keys($step) as $key) {
                if (!\in_array($key, $allowed, true)) {
                    $problems[] = \sprintf('%s: unknown key "%s".', $at, $key);
                }
            }

            if (null !== $spec['id'] && !\array_key_exists('id', $step)) {
                $problems[] = $at . ': "id" is required.';
            }

            foreach ([...$spec['options'], 'id'] as $option) {
                if (\array_key_exists($option, $step) && !\is_string($step[$option]) && !\is_int($step[$option])) {
                    $problems[] = \sprintf('%s: "%s" must be text or a number.', $at, $option);
                }
            }

            if (\array_key_exists('set', $step) && (!\is_array($step['set']) || ([] !== $step['set'] && array_is_list($step['set'])))) {
                $problems[] = $at . ': "set" must be an object of field => value.';
            }

            // Backwards only, and checked before this step's own ref is known: a step
            // cannot point at itself.
            foreach (ApplyReferences::find($step) as $name) {
                [$base, $suffix] = array_pad(explode('.', $name, 2), 2, null);

                if (!isset($refs[$base])) {
                    $problems[] = \sprintf('%s: "@%s" does not name an earlier step.', $at, $name);
                } elseif ('article' === $suffix && 'page create' !== $refs[$base]) {
                    $problems[] = \sprintf('%s: "@%s" needs a page create; step "%s" is a %s.', $at, $name, $base, $refs[$base]);
                }
            }

            if (\array_key_exists('ref', $step)) {
                $ref = $step['ref'];

                if (!\is_string($ref) || 1 !== preg_match(self::REF, $ref)) {
                    $problems[] = \sprintf('%s: ref %s may hold only a-z, 0-9, _ and -.', $at, json_encode($ref, JSON_UNESCAPED_UNICODE));
                } elseif (isset($refs[$ref])) {
                    $problems[] = \sprintf('%s: ref "%s" is used twice.', $at, $ref);
                } else {
                    $refs[$ref] = $do;
                }
            }
        }

        if ([] !== $problems) {
            throw new ApplyPlanException($problems);
        }

        return new self($steps);
    }
}

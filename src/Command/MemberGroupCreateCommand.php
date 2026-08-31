<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a front end member group.
 *
 * The counterpart to `tl_user_group` on the front end side: `tl_member_group`
 * is what `protected`/`groups` on a page, article or content element points at.
 * Six fields, and only `name` is mandatory outright.
 *
 * The interesting one is `jumpTo`. It is marked mandatory in the DCA, but it
 * sits in a **subpalette** — it only appears once `redirect` is checked, and
 * DC_Table only demands it then. So:
 *
 *   --set redirect=1                 → rejected, jumpTo is missing
 *   --set redirect=1 --set jumpTo=7  → accepted
 *   (neither)                        → accepted, jumpTo is not in play
 *
 * 🎯 Same principle as ModuleCreateCommand's palette rule, one DCA level down:
 * a mandatory field is only mandatory where Contao actually shows it. The two
 * checks are deliberately not shared yet — palettes and subpalettes are
 * different structures, and a third caller is the moment to unify them rather
 * than guess at the shape now.
 */
#[AsCommand(name: 'contao:member-group:create', description: 'Create a front end member group')]
class MemberGroupCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Group name shown in the back end');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $name = (string) $this->input->getOption('name');

        if ('' === $name) {
            return $this->outputError('--name is required');
        }

        // Shape of the request first, database second: the subpalette check
        // needs nothing but the DCA, so making the caller wait for a query to
        // be told their arguments are incomplete would be the wrong order.
        if (!isset($GLOBALS['TL_DCA']['tl_member_group']['fields'])) {
            Controller::loadDataContainer('tl_member_group');
        }

        $missing = $this->missingSubpaletteFields('tl_member_group', $fields);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required with these settings: %s. Pass them with --set.',
                implode(', ', $missing),
            ));
        }

        if ($this->valueTaken(MemberGroupModel::class, 'name', $name)) {
            return $this->outputError(\sprintf(
                'A member group named "%s" already exists. Group names are unique in Contao.',
                $name,
            ));
        }

        $fields = $this->convertFields('tl_member_group', $fields);

        $group         = new MemberGroupModel();
        $group->tstamp = time();
        $group->name   = $name;

        foreach ($fields as $key => $value) {
            $group->$key = $value;
        }

        $group->save();
        $this->createVersion('tl_member_group', (int) $group->id);

        $this->outputSuccess([
            'id'   => (int) $group->id,
            'name' => $name,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Mandatory fields that only become mandatory because a selector is on.
     *
     * `subpalettes` is keyed by the selector field; its value lists the fields
     * that appear when the selector is truthy. Reading it from the DCA rather
     * than hard-coding `redirect => jumpTo` means an extension that adds a
     * subpalette to this table is covered without a change here.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    public function missingSubpaletteFields(string $table, array $fields): array
    {
        $dca     = $GLOBALS['TL_DCA'][$table] ?? [];
        $missing = [];

        foreach ($dca['subpalettes'] ?? [] as $selector => $palette) {
            if (!\is_string($palette)) {
                continue;
            }

            $on = (string) ($fields[$selector] ?? '');
            if ('' === $on || '0' === $on) {
                continue;
            }

            foreach (array_map('trim', explode(',', $palette)) as $field) {
                if ('' === $field || empty($dca['fields'][$field]['eval']['mandatory'])) {
                    continue;
                }
                if ('' === (string) ($fields[$field] ?? '')) {
                    $missing[] = $field;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}

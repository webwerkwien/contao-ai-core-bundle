<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests;

use Contao\System;

/**
 * Skips a test that has to resolve a Contao model.
 *
 * `Model::findById()` reaches DcaExtractor, which needs a real Symfony
 * container and a database. The command tests mock the framework away, so
 * without `CONTAO_ROOT` these tests cannot run — see tests/bootstrap.php.
 *
 * They used to *error* instead, all 17 of them, on every plain
 * `vendor/bin/phpunit`. A suite that is red by design is a suite nobody reads:
 * the next real regression arrives as error number 18 and looks like the
 * weather. Skipping says the same thing — "not covered in this mode" — while
 * leaving a failure visible as a failure.
 *
 * The check is on the container rather than on the CONTAO_ROOT env var,
 * because the container is what the test actually needs; whoever supplies it
 * is beside the point.
 */
trait NeedsContaoContainerTrait
{
    protected function skipWithoutContaoContainer(): void
    {
        if (null === System::getContainer()) {
            $this->markTestSkipped(
                'Needs a booted Contao framework to resolve a model. '
                . 'Run with CONTAO_ROOT=/path/to/contao vendor/bin/phpunit.'
            );
        }
    }
}

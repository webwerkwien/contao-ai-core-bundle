<?php

namespace Webwerkwien\ContaoCliBridgeBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Webwerkwien\ContaoCliBridgeBundle\ContaoCliBridgeBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoCliBridgeBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }
}

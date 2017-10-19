<?php

namespace ElasticExportIdealoDEmocaviMOD;

use ElasticExportIdealoDEmocaviMOD\Helper\PriceHelper;
use ElasticExportIdealoDEmocaviMOD\Helper\PropertyHelper;
use ElasticExportIdealoDEmocaviMOD\Helper\StockHelper;
use Plenty\Modules\DataExchange\Services\ExportPresetContainer;
use Plenty\Plugin\DataExchangeServiceProvider;

class ElasticExportIdealoDEmocaviMODServiceProvider extends DataExchangeServiceProvider
{
    public function register()
    {

    }

    public function exports(ExportPresetContainer $container)
    {
        $container->add(
            'IdealoDE-Plugin-MOCAVI-MOD',
            'ElasticExportIdealoDEmocaviMOD\ResultField\IdealoDE',
            'ElasticExportIdealoDEmocaviMOD\Generator\IdealoDE',
            '',
            true,
            true
        );
    }
}

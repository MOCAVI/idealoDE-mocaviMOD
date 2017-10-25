<?php

namespace ElasticExportIdealoDEmocaviMOD\ResultField;

use Cache\Util\Key;
use Plenty\Modules\Cloud\ElasticSearch\Lib\ElasticSearch;
use Plenty\Modules\DataExchange\Contracts\ResultFields;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\Item\Search\Mutators\ImageMutator;
use Plenty\Modules\Cloud\ElasticSearch\Lib\Source\Mutator\BuiltIn\LanguageMutator;
use Plenty\Modules\Item\Search\Mutators\KeyMutator;
use Plenty\Modules\Item\Search\Mutators\SkuMutator;
use Plenty\Modules\Item\Search\Mutators\DefaultCategoryMutator;

/**
 * Class IdealoDE
 * @package ElasticExportIdealoDE\ResultField
 */
class IdealoDE extends ResultFields
{
    const IDEALO_DE = 121.00;

    const ALL_MARKET_REFERENCE = -1;

    /**
     * @var ArrayHelper
     */
    private $arrayHelper;

    /**
     * IdealoResultFields constructor.
     *
     * @param ArrayHelper $arrayHelper
     */
    public function __construct(ArrayHelper $arrayHelper)
    {
        $this->arrayHelper = $arrayHelper;
    }

    /**
     * Creates the fields set to be retrieved from ElasticSearch.
     *
     * @param array $formatSettings
     * @return array
     */
    public function generateResultFields(array $formatSettings = []):array
    {
        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');

        $reference = $settings->get('referrerId') ? $settings->get('referrerId') : self::IDEALO_DE;

        $this->setOrderByList(['item.id', ElasticSearch::SORTING_ORDER_ASC]);

        $itemDescriptionFields = ['texts.urlPath', 'texts.lang'];
        $itemDescriptionFields[] = ($settings->get('nameId')) ? 'texts.name' . $settings->get('nameId') : 'texts.name1';

        if($settings->get('descriptionType') == 'itemShortDescription'
            || $settings->get('previewTextType') == 'itemShortDescription')
        {
            $itemDescriptionFields[] = 'texts.shortDescription';
        }

        if($settings->get('descriptionType') == 'itemDescription'
            || $settings->get('descriptionType') == 'itemDescriptionAndTechnicalData'
            || $settings->get('previewTextType') == 'itemDescription'
            || $settings->get('previewTextType') == 'itemDescriptionAndTechnicalData')
        {
            $itemDescriptionFields[] = 'texts.description';
        }

        if($settings->get('descriptionType') == 'technicalData'
            || $settings->get('descriptionType') == 'itemDescriptionAndTechnicalData'
            || $settings->get('previewTextType') == 'technicalData'
            || $settings->get('previewTextType') == 'itemDescriptionAndTechnicalData')
        {
            $itemDescriptionFields[] = 'texts.technicalData';
        }

        // Mutators
        /**
         * @var ImageMutator $imageMutator
         */
        $imageMutator = pluginApp(ImageMutator::class);
        if($imageMutator instanceof ImageMutator)
        {
            // add image reference for a specific market
            $imageMutator->addMarket($reference);

            // add image reference -1 when the image is available for all markets
            $imageMutator->addMarket(self::ALL_MARKET_REFERENCE);
        }

        /**
         * @var KeyMutator $keyMutator
         */
        $keyMutator = pluginApp(KeyMutator::class);
        if($keyMutator instanceof KeyMutator)
        {
            $keyMutator->setKeyList($this->getKeyList());
            $keyMutator->setNestedKeyList($this->getNestedKeyList());
        }

        /**
         * @var LanguageMutator $languageMutator
         */
        $languageMutator = pluginApp(LanguageMutator::class, [[$settings->get('lang')]]);

        /**
         * @var SkuMutator $skuMutator
         */
        $skuMutator = pluginApp(SkuMutator::class);
        if($skuMutator instanceof SkuMutator)
        {
            $skuMutator->setMarket($reference);
        }
        /**
         * @var DefaultCategoryMutator $defaultCategoryMutator
         */
        $defaultCategoryMutator = pluginApp(DefaultCategoryMutator::class);
        if($defaultCategoryMutator instanceof DefaultCategoryMutator)
        {
            $defaultCategoryMutator->setPlentyId($settings->get('plentyId'));
        }

        // Fields
        $fields = [
            [
                //item
                'item.id',
                'item.manufacturer.id',
                'item.amazonFedas',

                //variation
                'id',
                'variation.availability.id',
                'variation.stockLimitation',
                'variation.vatId',
                'variation.model',
                'variation.isMain',
                'variation.weightG',
                'variation.number',

                //images
                'images.item.urlMiddle',
                'images.item.urlPreview',
                'images.item.urlSecondPreview',
                'images.item.url',
                'images.item.path',
                'images.item.position',

                'images.variation.urlMiddle',
                'images.variation.urlPreview',
                'images.variation.urlSecondPreview',
                'images.variation.url',
                'images.variation.path',
                'images.variation.position',

                //unit
                'unit.content',
                'unit.id',

                //marketAvailability
                'ids.markets',

                //sku
                'skus.sku',

                //defaultCategories
                'defaultCategories.id',

                //barcodes
                'barcodes.code',
                'barcodes.type',

                //attributes
                'attributes.attributeValueSetId',
                'attributes.attributeId',
                'attributes.valueId',

                //properties
                'properties.property.id',
                'properties.property.valueType',
                'properties.selection.name',
                'properties.texts.value',
                'properties.valueInt',
                'properties.valueFloat'
            ],

            [
                $languageMutator,
                $skuMutator,
                $keyMutator,
                $defaultCategoryMutator
            ],
        ];

        // Get the associated images if reference is selected
        if($reference != -1)
        {
            $fields[1][] = $imageMutator;
        }

        foreach($itemDescriptionFields as $itemDescriptionField)
        {
            //texts
            $fields[0][] = $itemDescriptionField;
        }

        return $fields;
    }

    /**
     * Returns the list of keys.
     *
     * @return array
     */
    private function getKeyList()
    {
        $keyList = [
            //item
            'item.id',
            'item.manufacturer.id',
            'item.amazonFedas',

            //variation
            'variation.availability.id',
            'variation.stockLimitation',
            'variation.vatId',
            'variation.model',
            'variation.isMain',
            'variation.weightG',
            'variation.number',

            //unit
            'unit.content',
            'unit.id'
        ];

        return $keyList;
    }

    /**
     * Returns the list of nested keys.
     *
     * @return mixed
     */
    private function getNestedKeyList()
    {
        $nestedKeyList['keys'] = [
            //images
            'images.item',
            'images.variation',

            //sku
            'skus',

            //texts
            'texts',

            //defaultCategories
            'defaultCategories',

            //barcodes
            'barcodes',

            //attributes
            'attributes',

            //properties
            'properties'
        ];

        $nestedKeyList['nestedKeys'] = [
            //images
            'images.item' => [
                'urlMiddle',
                'urlPreview',
                'urlSecondPreview',
                'url',
                'path',
                'position'
            ],
            'images.variation' => [
                'urlMiddle',
                'urlPreview',
                'urlSecondPreview',
                'url',
                'path',
                'position'
            ],

            //sku
            'skus' => [
                'sku'
            ],

            //texts
            'texts'  => [
                'urlPath',
                'lang',
                'name1',
                'name2',
                'name3',
                'shortDescription',
                'description',
                'technicalData'
            ],

            //defaultCategories
            'defaultCategories' => [
                'id'
            ],

            //barcodes
            'barcodes'  => [
                'code',
                'type'
            ],

            //attributes
            'attributes'   => [
                'attributeValueSetId',
                'attributeId',
                'valueId'
            ],

            //proprieties
            'properties'    => [
                'property.id',
                'property.valueType',
                'selection.name',
                'texts.value',
                'valueInt',
                'valueFloat'
            ]
        ];

        return $nestedKeyList;
    }
}

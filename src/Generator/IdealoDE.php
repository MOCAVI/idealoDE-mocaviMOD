<?php

namespace ElasticExportIdealoDEmocaviMOD\Generator;

use ElasticExport\Helper\ElasticExportCoreHelper;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExportIdealoDEmocaviMOD\Helper\PriceHelper;
use ElasticExportIdealoDEmocaviMOD\Helper\PropertyHelper;
use ElasticExportIdealoDEmocaviMOD\Helper\StockHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
use Plenty\Modules\Order\Shipping\Models\DefaultShipping;
use Plenty\Modules\Order\Payment\Method\Models\PaymentMethod;
use Plenty\Plugin\Log\Loggable;

/**
 * Class IdealoDE
 * @package ElasticExportIdealoDE\Generator
 */
class IdealoDE extends CSVPluginGenerator
{
	use Loggable;

    const IDEALO_DE = 121.00;
    const IDEALO_CHECKOUT = 121.02;

    const DELIMITER = "\t"; // tab

    const DEFAULT_PAYMENT_METHOD = 'vorkasse';

    const SHIPPING_COST_TYPE_FLAT = 'flat';
    const SHIPPING_COST_TYPE_CONFIGURATION = 'configuration';

    const PROPERTY_IDEALO_DIREKTKAUF = 'CheckoutApproved';
    const PROPERTY_IDEALO_SPEDITION = 'FulfillmentType:Spedition';
    const PROPERTY_IDEALO_PAKETDIENST = 'FulfillmentType:Paketdienst';

    /**
     * @var ElasticExportCoreHelper $elasticExportCoreHelper
     */
    private $elasticExportCoreHelper;

    /**
     * @var ArrayHelper $arrayHelper
     */
    private $arrayHelper;

    /**
     * @var PropertyHelper
     */
    private $propertyHelper;

    /**
     * @var StockHelper
     */
    private $stockHelper;

    /**
     * @var array
     */
    private $usedPaymentMethods = [];

    /**
     * @var array
     */
    private $defaultShippingList = [];

	/**
	 * @var ElasticExportStockHelper $elasticExportStockHelper
	 */
	private $elasticExportStockHelper;

	/**
	 * @var ElasticExportPriceHelper $elasticExportPriceHelper
	 */
	private $elasticExportPriceHelper;

	/**
	 * IdealoDE constructor.
	 *
	 * @param ArrayHelper $arrayHelper
	 * @param PropertyHelper $propertyHelper
	 * @param StockHelper $stockHelper
	 */
    public function __construct(
        ArrayHelper $arrayHelper,
        PropertyHelper $propertyHelper,
        StockHelper $stockHelper
    )
    {
        $this->arrayHelper = $arrayHelper;
        $this->propertyHelper = $propertyHelper;
        $this->stockHelper = $stockHelper;
    }

    /**
     * Generates and populates the data into the CSV file.
     *
     * @param VariationElasticSearchScrollRepositoryContract $elasticSearch
     * @param array $formatSettings
     * @param array $filter
     */
    protected function generatePluginContent($elasticSearch, array $formatSettings = [], array $filter = [])
    {
		$this->elasticExportStockHelper = pluginApp(ElasticExportStockHelper::class);
        $this->elasticExportCoreHelper = pluginApp(ElasticExportCoreHelper::class);
        $this->elasticExportPriceHelper = pluginApp(ElasticExportPriceHelper::class);

        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');

        $this->setDelimiter(self::DELIMITER);

        $this->addCSVContent($this->head($settings));

        // Initiate the variables needed for grouping variations
        $currentItemId = null;
        $previousItemId = null;
        $variations = array();

        $startTime = microtime(true);

        if($elasticSearch instanceof VariationElasticSearchScrollRepositoryContract)
        {
            // Initiate the counter for the variations limit
            $limit = 0;
            $limitReached = false;

            do
            {
                if($limitReached === true)
                {
                    break;
                }

                $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.writtenLines', [
                    'Lines written' => $limit,
                ]);

                $esStartTime = microtime(true);

                // Get the data from Elastic Search
                $resultList = $elasticSearch->execute();

                $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.esDuration', [
                    'Elastic Search duration' => microtime(true) - $esStartTime,
                ]);

                if(count($resultList['error']) > 0)
                {
                    $this->getLogger(__METHOD__)->error('ElasticExportIdealoDEmocaviMOD::item.occurredElasticSearchErrors', [
                        'Error message' => $resultList['error'],
                    ]);

                    break;
                }

                $buildRowStartTime = microtime(true);

                if(is_array($resultList['documents']) && count($resultList['documents']) > 0)
                {
                    // Filter and create the grouped variations array
                    foreach($resultList['documents'] as $variation)
                    {
                        // Stop and set the flag if limit is reached
                        if($limit == $filter['limit'])
                        {
                            $limitReached = true;
                            break;
                        }

                        $attributes = $this->elasticExportCoreHelper->getAttributeValueSetShortFrontendName($variation, $settings, '|');

                        // Skip main variations without attributes
                        if(strlen($attributes) <= 0 && $variation['variation']['isMain'] === false)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportIdealoDEmocaviMOD::item.itemMainVariationAttributeNameError', [
                                'VariationId' => (string)$variation['id']
                            ]);

                            continue;
                        }

                        // If filtered by stock is set and stock is negative, then skip the variation
                        if ($this->elasticExportStockHelper->isFilteredByStock($variation, $filter) === true)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportIdealoDEmocaviMOD::item.itemExportNotPartOfExportStock', [
                                'VariationId' => (string)$variation['id']
                            ]);

                            continue;
                        }

                        // Check if it's the first item from the resultList
                        if ($currentItemId === null)
                        {
                            $previousItemId = $variation['data']['item']['id'];
                        }

                        $currentItemId = $variation['data']['item']['id'];

                        // Check if it's the same item and add it to the grouper
                        if ($currentItemId == $previousItemId)
                        {
                            // Add the new variation to the grouper
                            $variations[] = $variation;
                        }
                        else
                        {
                            $this->constructData($settings, $variations);

                            // Pass the items to the CSV printer
                            $variations = array();
                            $variations[] = $variation;
                            $previousItemId = $variation['data']['item']['id'];
                        }

                        // New line was added
                        $limit += 1;
                    }

                    // Write the last batch of variations
                    if (is_array($variations) && count($variations) > 0)
                    {
                        $this->constructData($settings, $variations);
                    }

                    $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.buildRowDuration', [
                        'Build rows duration' => microtime(true) - $buildRowStartTime,
                    ]);
                }

            } while ($elasticSearch->hasNext());
        }

        $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.fileGenerationDuration', [
            'Whole file generation duration' => microtime(true) - $startTime,
        ]);
    }

    /**
     * Creates the Header of the CSV file.
     *
     * @param KeyValue $settings
     * @return array
     */
    private function head(KeyValue $settings):array
    {
        $data = [
            'article_id',
            'deeplink',
            'name',
            'short_description',
            'description',
            'article_no',
            'producer',
            'model',
            'availability',
            'ean',
            'isbn',
            'fedas',
            'warranty',
            'price',
            'price_old',
            'weight',
            'category1',
            'category2',
            'category3',
            'category4',
            'category5',
            'category6',
            'category_concat',
            'image_url_preview',
            'image_url',
            'base_price',
            'free_text_field',
            'checkoutApproved',
            'itemsInStock',
            'fulfillmentType',
            'twoManHandlingPrice',
            'disposalPrice'
        ];

        /**
         * If the shipping cost type is configuration, all payment methods will be taken as available payment methods from the chosen
         * default shipping configuration.
         */
        if($settings->get('shippingCostType') == self::SHIPPING_COST_TYPE_CONFIGURATION)
        {
			/**
			 * @var PaymentMethod[] $paymentMethods
			 */
            $paymentMethods = $this->elasticExportCoreHelper->getPaymentMethods($settings);
            $defaultShipping = $this->elasticExportCoreHelper->getDefaultShipping($settings);

            if($defaultShipping instanceof DefaultShipping)
            {
                foreach([$defaultShipping->paymentMethod2, $defaultShipping->paymentMethod3] as $paymentMethodId)
                {
					if(array_key_exists($paymentMethodId, $paymentMethods))
					{
						$usedPaymentMethod = $this->usedPaymentMethods[$defaultShipping->id][0];

						/**
						 * Three cases:
						 */
						if(	(count($this->usedPaymentMethods) == 0) ||

							((count($this->usedPaymentMethods) == 1 || count($this->usedPaymentMethods) == 2)
                                && $usedPaymentMethod instanceof PaymentMethod
								&& isset($usedPaymentMethod->id)
                                && $usedPaymentMethod->id != $paymentMethodId)
						)
						{
							$paymentMethod = $paymentMethods[$paymentMethodId];

							if($paymentMethod instanceof PaymentMethod)
							{
                                if(isset($paymentMethod->name) && strlen($paymentMethod->name))
                                {
                                    $data[] = $paymentMethod->name;
                                    $this->usedPaymentMethods[$defaultShipping->id][] = $paymentMethods[$paymentMethodId];
                                }
							}
						}
					}
                }
            }
        }

        /**
         * If nothing is checked at the elastic export settings regarding the shipping cost type,
         * all payment methods within both default shipping configurations will be taken as available payment methods.
         */
        elseif($settings->get('shippingCostType') == 1)
        {
			/**
			 * @var PaymentMethod[] $paymentMethods
			 */
            $paymentMethods = $this->elasticExportCoreHelper->getPaymentMethods($settings);
            $this->defaultShippingList = $this->elasticExportCoreHelper->getDefaultShippingList();

            foreach($this->defaultShippingList as $defaultShipping)
            {
                if($defaultShipping instanceof DefaultShipping)
                {
                    foreach([$defaultShipping->paymentMethod2, $defaultShipping->paymentMethod3] as $paymentMethodId)
                    {
                    	if(!array_key_exists($paymentMethodId, $paymentMethods) ||
							!($paymentMethods[$paymentMethodId] instanceof PaymentMethod))
						{
							continue;
						}

						$paymentMethod = $paymentMethods[$paymentMethodId];

                        if($paymentMethod instanceof PaymentMethod)
                        {
                            if((count($this->usedPaymentMethods) == 0))
                            {
                                $data[] = $paymentMethod->name;
                                $this->usedPaymentMethods[$defaultShipping->id][] = $paymentMethods[$paymentMethodId];
                            }
                            elseif(count($this->usedPaymentMethods) == 1
                                && $this->usedPaymentMethods[1][0]->id != $paymentMethodId)
                            {
                                $data[] = $paymentMethod->name;
                                $this->usedPaymentMethods[$defaultShipping->id][] = $paymentMethods[$paymentMethodId];
                            }
                            elseif($this->usedPaymentMethods[1][0] instanceof PaymentMethod
                                && $this->usedPaymentMethods[2][0] instanceof PaymentMethod

                                && count($this->usedPaymentMethods) == 2
                                && ($this->usedPaymentMethods[1][0]->id != $paymentMethodId
                                    && $this->usedPaymentMethods[2][0]->id != $paymentMethodId))
                            {
                                $data[] = $paymentMethod->name;
                                $this->usedPaymentMethods[$defaultShipping->id][] = $paymentMethods[$paymentMethodId];
                            }
                        }
                    }
                }
            }
        }

        if(count($this->usedPaymentMethods) <= 0 || $settings->get('shippingCostType') == self::SHIPPING_COST_TYPE_FLAT)
        {
            $data[] = self::DEFAULT_PAYMENT_METHOD;
        }

        return $data;
    }

    /**
     * Creates the variation rows and prints them into the CSV file.
     *
     * @param KeyValue $settings
     * @param array $variationGroup
     */
    private function constructData(KeyValue $settings, $variationGroup)
    {
        $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.itemExportConstructGroup', [
            'VariationGroup' => count($variationGroup) . ' variations to be printed in CSV'
        ]);

        foreach($variationGroup as $variation)
        {
            $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.itemExportConstructItem', [
                'VariationId' => (string)$variation['id']
            ]);

            $this->buildRow($settings, $variation);
		}

        $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.itemExportConstructGroupFinished', [
            'VariationGroup' => 'variations printed successfully in CSV'
        ]);
    }

	/**
	 * Creates the item row and prints it into the CSV file.
	 *
	 * @param KeyValue $settings
	 * @param array $variation
	 */
    private function buildRow(KeyValue $settings, $variation)
	{
        $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.itemExportConstructGroup', [
            'Data row duration' => 'Row printing start'
        ]);

        $dataTime = microtime(true);

        try
        {
            // get the price list
            $priceList = $this->getPriceList($variation, $settings);

            // only variations with the Retail Price greater than zero will be handled
            if($priceList['price'] > 0)
            {
                // get variation name
                $variationName = $this->elasticExportCoreHelper->getAttributeValueSetShortFrontendName($variation, $settings);

                // calculate stock
                $stock = $this->stockHelper->getStock($variation);

                //getImages
                $imageDataList = $this->elasticExportCoreHelper->getImageListInOrder($variation, $settings, 1, ElasticExportCoreHelper::VARIATION_IMAGES, 'normal', true);

                // get the checkout approved property
                $checkoutApproved = $this->propertyHelper->getCheckoutApproved($variation);

                $data = [
                    'article_id' 		=> '',
                    'deeplink' 			=> $this->elasticExportCoreHelper->getMutatedUrl($variation, $settings, true, false),
                    'name' 				=> $this->elasticExportCoreHelper->getMutatedName($variation, $settings) . (strlen($variationName) ? ' ' . $variationName : ''),
                    'short_description' => $this->elasticExportCoreHelper->getMutatedPreviewText($variation, $settings),
                    'description' 		=> $this->elasticExportCoreHelper->getMutatedDescription($variation, $settings),
                    'article_no' 		=> $variation['data']['variation']['number'],
                    'producer' 			=> $this->elasticExportCoreHelper->getExternalManufacturerName((int)$variation['data']['item']['manufacturer']['id']),
                    'model' 			=> $variation['data']['variation']['model'],
                    'availability' 		=> $this->elasticExportCoreHelper->getAvailability($variation, $settings),
                    'ean'	 			=> $this->elasticExportCoreHelper->getBarcodeByType($variation, $settings->get('barcode')),
                    'isbn' 				=> $this->elasticExportCoreHelper->getBarcodeByType($variation, ElasticExportCoreHelper::BARCODE_ISBN),
                    'fedas' 			=> $variation['data']['item']['amazonFedas'],
                    'warranty' 			=> '',
                    'price' 			=> $priceList['price'],
                    'price_old' 		=> $priceList['price_old'],
                    'weight' 			=> $variation['data']['variation']['weightG'],
                    'category1' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 1),
                    'category2' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 2),
                    'category3' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 3),
                    'category4' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 4),
                    'category5' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 5),
                    'category6' 		=> $this->elasticExportCoreHelper->getCategoryBranch((int)$variation['data']['defaultCategories'][0]['id'], $settings, 6),
                    'category_concat' 	=> $this->elasticExportCoreHelper->getCategory((int)$variation['data']['defaultCategories'][0]['id'], $settings->get('lang'), $settings->get('plentyId')),
                    'image_url_preview' => $this->elasticExportCoreHelper->getImageUrlBySize($imageDataList[0], ElasticExportCoreHelper::SIZE_PREVIEW),
                    'image_url' 		=> $this->elasticExportCoreHelper->getImageUrlBySize($imageDataList[0], ElasticExportCoreHelper::SIZE_NORMAL),
                    'base_price' 		=> $this->elasticExportPriceHelper->getBasePrice($variation, $priceList['price'], $settings->get('lang'), '/', false, true, $priceList['currency']),
                    'free_text_field'   => $this->propertyHelper->getFreeText($variation),
                    'checkoutApproved'	=> $checkoutApproved,
                ];

                /**
                 * if the article is available for idealo DK further fields will be set depending on the properties of the article.
                 *
                 * Be sure to set the price in twoManHandlingPrice and disposalPrice with a dot instead of a comma for idealo DK
                 * will only except it that way.
                 *
                 * The properties twoManHandlingPrice and disposalPrice will also only be set if the property fulfillmentType is 'Spedition'
                 * otherwise these two properties will be ignored.
                 */
                if($checkoutApproved == 'true')
                {
                    if($variation['data']['skus']['sku'] != null)
                    {
                        $sku = $variation['data']['skus']['sku'];
                    }
                    else
                    {
                        $sku = $this->elasticExportCoreHelper->generateSku($variation['id'], self::IDEALO_CHECKOUT, 0, $variation['id']);
                    }

                    $data['article_id'] = $sku;
                    $data['itemsInStock'] = $stock;

                    $data['fulfillmentType'] = '';
                    $data['twoManHandlingPrice'] = '';
                    $data['disposalPrice'] = '';

                    if($this->propertyHelper->getProperty($variation, self::PROPERTY_IDEALO_SPEDITION) === true)
                    {
                        $data['fulfillmentType'] = 'Spedition';

                        $twoManHandling = $this->propertyHelper->getProperty($variation, 'TwoManHandlingPrice');
                        $twoManHandling = number_format((float)$twoManHandling, 2, ',', '');

                        $disposal = $this->propertyHelper->getProperty($variation, 'DisposalPrice');
                        $disposal = number_format((float)$disposal, 2, ',', '');

                        $data['twoManHandlingPrice'] = ($twoManHandling > 0) ? $twoManHandling : '';

                        if($data['twoManHandlingPrice'] > 0)
                        {
                            $data['disposalPrice'] = ($disposal > 0) ? $disposal : '';
                        }
                    }
                    elseif($this->propertyHelper->getProperty($variation, self::PROPERTY_IDEALO_PAKETDIENST) === true)
                    {
                        $data['fulfillmentType'] = 'Paketdienst';
                    }
                }
                else
                {
                    if($variation['data']['skus']['sku'] != null)
                    {
                        $sku = $variation['data']['skus']['sku'];
                    }
                    else
                    {
                        $sku = $this->elasticExportCoreHelper->generateSku($variation['id'], self::IDEALO_DE, 0, $variation['id']);
                    }

                    $data['article_id'] = $sku;
                    $data['itemsInStock'] = '';
                    $data['fulfillmentType'] = '';
                    $data['twoManHandlingPrice'] = '';
                    $data['disposalPrice'] = '';
                }

                if(count($this->usedPaymentMethods) == 1)
                {
                    foreach($this->usedPaymentMethods as $paymentMethod)
                    {
                        foreach($paymentMethod as $method)
                        {
                            if($method instanceof PaymentMethod)
                            {
                                if(isset($method->name))
                                {
                                    $cost = $this->elasticExportCoreHelper->getShippingCost($variation['data']['item']['id'], $settings, $method->id);
                                    $data[$method->name] = number_format((float)$cost, 2, '.', '');
                                }
                            }
                            else
                            {
                                $this->getLogger(__METHOD__)->error('ElasticExportIdealoDEmocaviMOD::item.loadInstanceError', 'PaymentMethod');
                            }
                        }
                    }
                }
                elseif(count($this->usedPaymentMethods) > 1)
                {
                    foreach($this->usedPaymentMethods as $defaultShipping => $paymentMethod)
                    {
                        foreach ($paymentMethod as $method)
                        {
                            if($method instanceof PaymentMethod)
                            {
                                if(isset($method->name))
                                {
                                    $cost = $this->elasticExportCoreHelper->calculateShippingCost(
                                        $variation['data']['item']['id'],
                                        $this->defaultShippingList[$defaultShipping]->shippingDestinationId,
                                        $this->defaultShippingList[$defaultShipping]->referrerId,
                                        $method->id);
                                    $data[$method->name] = number_format((float)$cost, 2, '.', '');
                                }
                            }
                            else
                            {
                                $this->getLogger(__METHOD__)->error('ElasticExportIdealoDEmocaviMOD::item.loadInstanceError', 'PaymentMethod');
                            }
                        }
                    }
                }
                elseif(count($this->usedPaymentMethods) <= 0 && $settings->get('shippingCostType') == self::SHIPPING_COST_TYPE_FLAT)
                {
                    $data[self::DEFAULT_PAYMENT_METHOD] = $settings->get('shippingCostFlat');
                }
                else
                {
                    $data[self::DEFAULT_PAYMENT_METHOD] = 0.00;
                }

                // Get the values and print them in the CSV file
                $this->addCSVContent(array_values($data));

                $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.itemExportConstructGroupFinished', [
                    'Data row duration' => 'Row printing took: ' . (microtime(true) - $dataTime),
                ]);
            }
            else
            {
                $this->getLogger(__METHOD__)->info('ElasticExportIdealoDEmocaviMOD::item.itemExportNotPartOfExportPrice', [
                    'VariationId' => (string)$variation['id']
                ]);
            }
        }
        catch (\Throwable $throwable)
        {
            $this->getLogger(__METHOD__)->error('ElasticExportIdealoDEmocaviMOD::item.fillRowError', [
                'Error message ' => $throwable->getMessage(),
                'Error line'    => $throwable->getLine(),
                'VariationId'   => (string)$variation['id']
            ]);
        }
	}

    /**
     * @param  array    $variation
     * @param  KeyValue $settings
     * @return array
     */
    private function getPriceList(array $variation, KeyValue $settings):array
    {
        $rrp = '';

        $priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings, 2, '.');

        if($priceList['specialPrice'] < $priceList['price'] && $priceList['specialPrice'] > 0)
        {
            $price = $priceList['specialPrice'];
        }
        else
        {
            $price = $priceList['price'];
        }

        if($priceList['recommendedRetailPrice'] > $priceList['price'] && $priceList['recommendedRetailPrice'] > $price)
        {
            $rrp = $priceList['recommendedRetailPrice'];
        }
        elseif($priceList['price'] > $price)
        {
            $rrp = $priceList['price'];
        }

        return [
            'price' => $price,
            'price_old' => $rrp,
            'currency' => $priceList['currency']
        ];
    }
}

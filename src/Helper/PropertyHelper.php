<?php

namespace ElasticExportIdealoDEmocaviMOD\Helper;

use Plenty\Modules\Item\Property\Contracts\PropertyMarketReferenceRepositoryContract;
use Plenty\Modules\Item\Property\Contracts\PropertyNameRepositoryContract;
use Plenty\Modules\Item\Property\Models\PropertyName;
use Plenty\Plugin\Log\Loggable;

class PropertyHelper
{
    use Loggable;

    const IDEALO_DE = 121.00;
    const IDEALO_DE_DIREKTKAUF = 121.02;

    const PROPERTY_TYPE_TEXT = 'text';
    const PROPERTY_TYPE_SELECTION = 'selection';
    const PROPERTY_TYPE_EMPTY = 'empty';
    const PROPERTY_TYPE_INT = 'int';
    const PROPERTY_TYPE_FLOAT = 'float';

    const PROPERTY_IDEALO_CHECKOUT_APPROVED    = 'CheckoutApproved';
    const PROPERTY_IDEALO_SPEDITION     = 'FulfillmentType:Spedition';
    const PROPERTY_IDEALO_PAKETDIENST   = 'FulfillmentType:Paketdienst';

    /**
     * @var array
     */
    private $itemFreeTextCache = [];

    /**
     * @var array
     */
    private $itemPropertyCache = [];

    /**
     * @var PropertyNameRepositoryContract
     */
    private $propertyNameRepository;

    /**
     * @var PropertyMarketReferenceRepositoryContract
     */
    private $propertyMarketReferenceRepository;

    /**
     * PropertyHelper constructor.
     *
     * @param PropertyNameRepositoryContract $propertyNameRepository
     * @param PropertyMarketReferenceRepositoryContract $propertyMarketReferenceRepository
     */
    public function __construct(
        PropertyNameRepositoryContract $propertyNameRepository,
        PropertyMarketReferenceRepositoryContract $propertyMarketReferenceRepository)
    {
        $this->propertyNameRepository = $propertyNameRepository;
        $this->propertyMarketReferenceRepository = $propertyMarketReferenceRepository;
    }

    /**
     * Set checkoutApproved if either property or market availability is set.
     *
     * @param $variation
     * @return string
     */
    public function getCheckoutApproved($variation):string
    {
        $checkoutApproved = 'false';

        $propertyIsSet = $this->getProperty($variation, self::PROPERTY_IDEALO_CHECKOUT_APPROVED) === true;

        $marketAvailabilityIsSet = in_array(self::IDEALO_DE_DIREKTKAUF, $variation['data']['ids']['markets']);

        if ($propertyIsSet || $marketAvailabilityIsSet)
        {
            $checkoutApproved = 'true';
        }

        return $checkoutApproved;
    }

    /**
     * Get free text.
     *
     * @param  array $variation
     * @return string
     */
    public function getFreeText($variation):string
    {
        if(!array_key_exists($variation['data']['item']['id'], $this->itemFreeTextCache))
        {
            $freeText = array();

            foreach($variation['data']['properties'] as $property)
            {
                if(!is_null($property['property']['id']) &&
                    $property['property']['valueType'] != 'file' &&
                    $property['property']['valueType'] != 'empty')
                {
                    $propertyName = $this->propertyNameRepository->findOne($property['property']['id'], 'de');
                    $propertyMarketReference = $this->propertyMarketReferenceRepository->findOne($property['property']['id'], self::IDEALO_DE);

                    if(is_null($propertyMarketReference))
                    {
                    $propertyMarketReference = $this->propertyMarketReferenceRepository->findOne($property['property']['id'], self::IDEALO_DE_DIREKTKAUF);
                    }
                    // Skip properties which do not have the Component Id set
                    if(!($propertyName instanceof PropertyName) ||
                        is_null($propertyName) ||
                        is_null($propertyMarketReference) ||
                        $propertyMarketReference->componentId != 1)
                    {
                        continue;
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_TEXT)
                    {
                        if(is_array($property['texts']))
                        {
                            $freeText[] = $property['texts'][0]['value'];
                        }
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_SELECTION)
                    {
                        if(is_array($property['selection']))
                        {
                            $freeText[] = $property['selection'][0]['name'];
                        }
                    }
                }
            }

            $this->itemFreeTextCache[$variation['data']['item']['id']] = implode(' ', $freeText);
        }

        return $this->itemFreeTextCache[$variation['data']['item']['id']];
    }

    /**
     * Get property.
     *
     * @param  array $variation
     * @param  string $property
     * @return string|bool
     */
    public function getProperty($variation, string $property)
    {
        $itemPropertyList = $this->getItemPropertyList($variation);

        if(array_key_exists($property, $itemPropertyList))
        {
            if ($property == self::PROPERTY_IDEALO_CHECKOUT_APPROVED   ||
                $property == self::PROPERTY_IDEALO_SPEDITION    ||
                $property == self::PROPERTY_IDEALO_PAKETDIENST)
            {
                return true;
            }
            else
            {
                return $itemPropertyList[$property];
            }
        }

        return '';
    }

    /**
     * Get item properties for a given variation.
     *
     * @param  array $variation
     * @return array
     */
    private function getItemPropertyList($variation):array
    {
        if(!array_key_exists($variation['data']['item']['id'], $this->itemPropertyCache))
        {
            $list = array();

            foreach($variation['data']['properties'] as $property)
            {
                if(!is_null($property['property']['id']) &&
                    $property['property']['valueType'] != 'file')
                {
                    $propertyName = $this->propertyNameRepository->findOne($property['property']['id'], 'de');
                    $propertyMarketReference = $this->propertyMarketReferenceRepository->findOne($property['property']['id'], self::IDEALO_DE);
//ADK Änderung auf ElasticExportIdealoDE v.1.0.18
                    if(is_null($propertyMarketReference))
                    {
                      $propertyMarketReference = $this->propertyMarketReferenceRepository->findOne($property['property']['id'], self::IDEALO_DE_DIREKTKAUF);
                    }
//ENDE
                    // Skip properties which do not have the External Component set up
                    if(!($propertyName instanceof PropertyName) ||
                        is_null($propertyName) ||
                        is_null($propertyMarketReference) ||
                        $propertyMarketReference->externalComponent == '0')
                    {
                        $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.variationPropertyNotAdded', [
                            'ItemId'            => $variation['data']['item']['id'],
                            'VariationId'       => $variation['id'],
                            'Property'          => $property,
                            'ExternalComponent' => $propertyMarketReference->externalComponent
                        ]);

                        continue;
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_TEXT)
                    {
                        if(is_array($property['texts']))
                        {
                            $list[(string)$propertyMarketReference->externalComponent] = $property['texts'][0]['value'];
                        }
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_SELECTION)
                    {
                        if(is_array($property['selection']))
                        {
                            $list[(string)$propertyMarketReference->externalComponent] = $property['selection'][0]['name'];
                        }
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_EMPTY)
                    {
                        $list[(string)$propertyMarketReference->externalComponent] = $propertyMarketReference->externalComponent;
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_INT)
                    {
                        if(!is_null($property['valueInt']))
                        {
                            $list[(string)$propertyMarketReference->externalComponent] = $property['valueInt'];
                        }
                    }

                    if($property['property']['valueType'] == self::PROPERTY_TYPE_FLOAT)
                    {
                        if(!is_null($property['valueFloat']))
                        {
                            $list[(string)$propertyMarketReference->externalComponent] = $property['valueFloat'];
                        }
                    }

                }
            }

            $this->itemPropertyCache[$variation['data']['item']['id']] = $list;

            $this->getLogger(__METHOD__)->debug('ElasticExportIdealoDEmocaviMOD::item.variationPropertyList', [
                'ItemId'        => $variation['data']['item']['id'],
                'VariationId'   => $variation['id'],
                'PropertyList'  => count($list) > 0 ? $list : 'no properties'
            ]);
        }

        return $this->itemPropertyCache[$variation['data']['item']['id']];
    }
}

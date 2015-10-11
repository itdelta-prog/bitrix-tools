<?php
/**
 * @link https://github.com/bitrix-expert/tools
 * @copyright Copyright © 2015 Nik Samokhvalov
 * @license MIT
 */

namespace Bex\Tools\Iblock;

use Bex\Tools\Finder;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * Finder of the info blocks and properties of the info blocks.
 * 
 * @author Nik Samokhvalov <nik@samokhvalov.info>
 */
class IblockFinder extends Finder
{
    /**
     * Code of the shard cache for properties.
     */
    const CACHE_PROPS_SHARD = 'props';

    protected static $cacheDir = 'bex_tools/iblocks';
    protected $id;
    protected $type;
    protected $code;

    /**
     * @inheritdoc
     *
     * @throws ArgumentNullException Empty parameters in the filter
     * @throws LoaderException Module "iblock" not installed
     */
    public function __construct(array $filter)
    {
        if (!Loader::includeModule('iblock'))
        {
            throw new LoaderException('Failed include module "iblock"');
        }

        $filter = $this->prepareFilter($filter);

        if (isset($filter['type']))
        {
            $this->type = $filter['type'];
        }

        if (isset($filter['code']))
        {
            $this->code = $filter['code'];
        }

        if (isset($filter['id']))
        {
            $this->id = $filter['id'];
        }

        if (!isset($this->id))
        {
            if (!isset($this->type))
            {
                throw new ArgumentNullException('type');
            }
            elseif (!isset($this->code))
            {
                throw new ArgumentNullException('code');
            }

            $this->id = $this->getFromCache([
                'type' => 'id'
            ]);
        }
    }

    /**
     * Gets iblock ID.
     *
     * @return integer
     */
    public function id()
    {
        return $this->getFromCache([
            'type' => 'id'
        ]);
    }

    /**
     * Gets iblock type.
     *
     * @return string
     */
    public function type()
    {
        return $this->getFromCache([
            'type' => 'type'
        ]);
    }

    /**
     * Gets iblock code.
     *
     * @return string
     */
    public function code()
    {
        return $this->getFromCache([
            'type' => 'code'
        ]);
    }

    /**
     * Gets property ID.
     *
     * @param string $code Property code
     *
     * @return integer
     */
    public function propId($code)
    {
        return $this->getFromCache([
                'type' => 'propId',
                'propCode' => $code,
            ],
            static::CACHE_PROPS_SHARD
        );
    }

    /**
     * Gets property enum value ID.
     *
     * @param string $code Property code
     * @param integer $valueXmlId Property enum value XML ID
     *
     * @return integer
     */
    public function propEnumId($code, $valueXmlId)
    {
        return $this->getFromCache([
                'type' => 'propEnumId',
                'propCode' => $code,
                'valueXmlId' => $valueXmlId
            ],
            static::CACHE_PROPS_SHARD
        );
    }

    /**
     * @inheritdoc
     * 
     * @throws ArgumentNullException Empty parameters in the filter
     */
    protected function prepareFilter(array $filter)
    {
        foreach ($filter as $code => &$value)
        {
            if ($code === 'id' || $code === 'propId')
            {
                intval($value);

                if ($value <= 0)
                {
                    throw new ArgumentNullException($code);
                }
            }
            else
            {
                trim(htmlspecialchars($value));

                if (strlen($value) <= 0)
                {
                    throw new ArgumentNullException($code);
                }
            }
        }

        return $filter;
    }

    /**
     * @inheritdoc
     */
    protected function getValue(array $cache, array $filter, $shard)
    {
        switch ($filter['type'])
        {
            case 'id':
                if (isset($this->id))
                {
                    return $this->id;
                }

                $value = (int) $cache['IBLOCKS_ID'][$this->type][$this->code];

                if ($value <= 0)
                {
                    throw new ArgumentException('Iblock ID by type "' . $this->type . '" and code "'
                        . $this->code . '" not found');
                }

                return $value;
                break;

            case 'type':
                $value = (string) $cache['IBLOCKS_TYPE'][$this->id];

                if (strlen($value) <= 0)
                {
                    throw new ArgumentException('Iblock type by iblock #' . $this->id . ' not found');
                }

                return $value;
                break;

            case 'code':
                $value = (string) $cache['IBLOCKS_CODE'][$this->id];

                if (strlen($value) <= 0)
                {
                    throw new ArgumentException('Iblock code by iblock #' . $this->id . ' not found');
                }

                return $value;
                break;

            case 'propId':
                $value = (int) $cache['PROPS_ID'][$this->id][$filter['propCode']];

                if ($value <= 0)
                {
                    throw new ArgumentException('Property ID by iblock #' . $this->id . ' and property code "'
                        . $filter['propCode'] . '" not found');
                }

                return $value;
                break;

            case 'propEnumId':
                $propId = $cache['PROPS_ID'][$this->id][$filter['propCode']];

                $value = (int) $cache['PROPS_ENUM_ID'][$propId][$filter['valueXmlId']];

                if ($value <= 0)
                {
                    throw new ArgumentException('Property enum ID by iblock #' . $this->id . ', property code "'
                        . $filter['propCode'] . '" and property XML ID "' . $filter['valueXmlId'] . '" not found');
                }

                return $value;
                break;

            default:
                throw new \InvalidArgumentException('Invalid type on filter');
                break;
        }
    }

    /**
     * @inheritdoc
     */
    protected function getItems($shard)
    {
        if ($shard === static::CACHE_PROPS_SHARD)
        {
            return $this->getProperties();
        }

        return $this->getIblocks();
    }

    /**
     * Gets iblocks ID and codes.
     *
     * @return array
     * @throws ArgumentException
     */
    protected function getIblocks()
    {
        $items = [];
        $iblockIds = [];

        $rsIblocks = IblockTable::getList([
            'select' => [
                'IBLOCK_TYPE_ID',
                'ID',
                'CODE'
            ]
        ]);

        while ($iblock = $rsIblocks->fetch())
        {
            if ($iblock['CODE'])
            {
                $items['IBLOCKS_ID'][$iblock['IBLOCK_TYPE_ID']][$iblock['CODE']] = $iblock['ID'];
                $items['IBLOCKS_CODE'][$iblock['ID']] = $iblock['CODE'];

                $iblockIds[] = $iblock['ID'];
            }

            $items['IBLOCKS_TYPE'][$iblock['ID']] = $iblock['IBLOCK_TYPE_ID'];
        }

        foreach ($iblockIds as $id)
        {
            Application::getInstance()->getTaggedCache()->registerTag('iblock_id_' . $id);
        }

        Application::getInstance()->getTaggedCache()->registerTag('iblock_id_new');

        return $items;
    }

    /**
     * Gets properties ID.
     *
     * @return array
     * @throws ArgumentException
     */
    protected function getProperties()
    {
        $items = [];

        $rsProps = PropertyTable::getList([
            'select' => [
                'ID',
                'CODE',
                'IBLOCK_ID'
            ]
        ]);

        while ($prop = $rsProps->fetch())
        {
            $items['PROPS_ID'][$prop['IBLOCK_ID']][$prop['CODE']] = $prop['ID'];
        }

        $rsPropsEnum = PropertyEnumerationTable::getList([
            'select' => [
                'ID',
                'XML_ID',
                'PROPERTY_ID',
                'PROPERTY_CODE' => 'PROPERTY.CODE'
            ]
        ]);

        while ($propEnum = $rsPropsEnum->fetch())
        {
            if ($propEnum['PROPERTY_CODE'])
            {
                $items['PROPS_ENUM_ID'][$propEnum['PROPERTY_ID']][$propEnum['XML_ID']] = $propEnum['ID'];
            }
        }

        return $items;
    }
}
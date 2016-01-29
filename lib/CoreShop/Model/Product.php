<?php
/**
 * CoreShop
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2015 Dominik Pfaffenbauer (http://dominik.pfaffenbauer.at)
 * @license    http://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 */

namespace CoreShop\Model;

use CoreShop\Model\Product\SpecificPrice;
use Pimcore\Cache;
use Pimcore\Model\Object;
use Pimcore\View\Helper\Url;
use Pimcore\Model\Object\Fieldcollection\Data\CoreShopProductSpecificPrice;
use Pimcore\Model\Asset\Image;

use CoreShop\Tool;
use CoreShop\Exception\UnsupportedException;
use CoreShop\Tool\Service;

class Product extends Base {

    /**
     * @static
     * @param int $id
     * @return null|Product
     */
    public static function getById($id) {
        $object = Object\AbstractObject::getById($id);

        if ($object instanceof Product) {
            return $object;
        }

        return null;
    }

    /**
     * Get all Products
     *
     * @return array
     */
    public static function getAll()
    {
        $list = new Object\CoreShopProduct\Listing();
        $list->setCondition("enabled=1");

        return $list->getObjects();
    }

    /**
     * Get latest Products
     *
     * @param int $limit
     * @return array|mixed
     */
    public static function getLatest($limit = 8)
    {
        $cacheKey = "coreshop_latest";

        if(!$objects = \Pimcore\Cache::load($cacheKey)) {

            $list = new Object\CoreShopProduct\Listing();
            $list->setCondition("enabled=1");
            $list->setOrderKey("o_creationDate");
            $list->setOrder("DESC");

            if($limit) {
                $list->setLimit($limit);
            }

            $objects = $list->getObjects();
        }

        return $objects;
    }

    /**
     * Get Image for Product
     *
     * @return bool|\Pimcore\Model\Asset
     */
    public function getImage()
    {
        if(count($this->getImages()) > 0)
        {

            return $this->getImages()[0];
        }

        return $this->getDefaultImage();
    }

    /**
     * Get default Image for Product
     *
     * @return bool|\Pimcore\Model\Asset
     */
    public function getDefaultImage()
    {
        $defaultImage = Configuration::get("SYSTEM.PRODUCT.DEFAULTIMAGE");

        if($defaultImage) {
            $image = Image::getByPath($defaultImage);

            if ($image instanceof Image)
                return $image;
        }

        return false;
    }

    /**
     * Get Product is new
     *
     * @return bool
     */
    public function getIsNew()
    {
        $markAsNew = Configuration::get("SYSTEM.PRODUCT.DAYSASNEW");

        if(is_int($markAsNew) && $markAsNew > 0)
        {
            $creationDate = new \Zend_Date($this->getCreationDate());
            $nowDate = new \Zend_Date();

            $diff = $nowDate->sub($creationDate)->toValue();
            $days = ceil($diff/60/60/24) +1;

            if($days <= $markAsNew)
                return true;
        }

        return false;
    }

    /**
     * Check if Product is in Categry
     *
     * @param Category $category
     * @return bool
     */
    public function inCategory(Category $category) {
        foreach($this->getCategories() as $c) {
            if($c->getId() == $category->getId())
                return true;
        }

        return false;
    }

    /**
     * Return Product as Array
     *
     * @return array
     * @throws \Exception
     * @throws \Zend_Exception
     */
    public function toArray()
    {
        $urlHelper = new Url();
        $image = $this->getImage();

        return array(
            "image" => $image !== FALSE ? $image->getFullPath() : FALSE,
            "price" => $this->getPrice(),
            "priceFormatted" => Tool::formatPrice($this->getPrice()),
            "name" => $this->getName(),
            "thumbnail" => array(
                "cart" => $this->getImage() instanceof Image ? $this->getImage()->getThumbnail("coreshop_productCartPreview")->getPath(true) : ""
            ),
            "href" => $urlHelper->url(array("lang" => \Zend_Registry::get("Zend_Locale"), "name" => $this->getName(), "product" => $this->getId()), 'coreshop_detail')
        );
    }

    /**
     * Get all Variants Differences
     *
     * @return array
     */
    public function getVariantDifferences( $language = 'en' )
    {
        $master = $this;
        //Find master object
        while($master->getType() === "variant") {
            $master = $master->getParent();
        }
        if($master instanceof Product)
            return Service::getProductVariations($master, $this, $language);
        return false;
    }

    /**
     * get price without tax
     *
     * @return float|mixed
     * @throws UnsupportedException
     */
    public function getPriceWithoutTax() {
        $cacheKey = "coreshop_product_price_" . $this->getId();

        if($price = Cache::load($cacheKey)) {
            return $price;
        }

        $price = $this->getRetailPrice();
        $price -= $this->getSpecificPriceDiscount();

        return $price;
    }

    /**
     * Get Discount from Specific Prices
     *
     * @return float
     */
    public function getSpecificPriceDiscount() {
        $specificPrices = $this->getSpecificPrices();
        $discount = 0;

        foreach($specificPrices as $specificPrice) {
            if($specificPrice instanceof SpecificPrice) {
                $conditions = $specificPrice->getConditions();
                $actions = $specificPrice->getActions();

                $isValid = true;

                foreach($conditions as $condition) {
                    if(!$condition->checkCondition($this, $specificPrice)) {
                        $isValid = false;
                        break;
                    }
                }

                if(!$isValid) {
                    break;
                }
                else {
                    foreach($actions as $action) {
                        $discount += $action->getDiscount($this);
                    }
                }
            }
        }

        return $discount;
    }

    /**
     * Get Product Price with Tax
     *
     * @return float|mixed
     * @throws \Exception
     */
    public function getPrice()
    {
        $price = $this->getPriceWithoutTax();
        $calculator = $this->getTaxCalculator();

        if($calculator) {
            $price = $calculator->addTaxes($price);
        }

        return Tool::convertToCurrency($price);
    }

    /**
     * returns tax
     * @return float
     */
    public function getTax() {
        $calculator = $this->getTaxCalculator();

        if($calculator) {
            return $calculator->getTaxesAmount($this->getPriceWithoutTax());
        }

        return 0;
    }

    /**
     * get TaxCalculator
     *
     * @param Country $country
     * @return bool|TaxCalculator
     */
    public function getTaxCalculator(Country $country = null) {
        if(is_null($country)) {
            $country = Tool::getCountry();
        }

        $taxRule = $this->getTaxRule();

        if($taxRule instanceof TaxRuleGroup) {
            $taxManager = TaxManagerFactory::getTaxManager($country, $taxRule->getId());
            $taxCalculator = $taxManager->getTaxCalculator();

            return $taxCalculator;
        }

        return false;
    }

    /**
     * get all specific prices
     *
     * @return array
     */
    public function getSpecificPrices() {
        return SpecificPrice::getSpecificPrices($this);
    }

    /**
     * returns array of images.
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return Image[]
     */
    public function getImages() {
        throw new UnsupportedException("getImages is not supported for " . get_class($this));
    }

    /**
     * returns array of categories.
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return Category[]
     */
    public function getCategories() {
        throw new UnsupportedException("getCategories is not supported for " . get_class($this));
    }

    /**
     * returns retail price
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getRetailPrice() {
        throw new UnsupportedException("getRetailPrice is not supported for " . get_class($this));
    }

    /**
     * returns name
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return string
     */
    public function getName() {
        throw new UnsupportedException("getName is not supported for " . get_class($this));
    }

    /**
     * returns TaxRuleGroup
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return TaxRuleGroup
     */
    public function getTaxRule() {
        throw new UnsupportedException("getTaxRule is not supported for " . get_class($this));
    }


    /**
     * returns price
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return CoreShopProductSpecificPrice[]
     */
    public function getSpecificPrice() {
        throw new UnsupportedException("getPrice is not supported for " . get_class($this));
    }

    /**
     * returns wholesale price
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getWholesalePrice() {
        throw new UnsupportedException("getWholesalePrice is not supported for " . get_class($this));
    }

    /**
     * returns is download product
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return string
     */
    public function getIsDownloadProduct() {
        throw new UnsupportedException("getIsDownloadProduct is not supported for " . get_class($this));
    }

    /**
     * returns is weight of the product
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getWeight() {
        throw new UnsupportedException("getWeight is not supported for " . get_class($this));
    }

    /**
     * returns is width of the product
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getWidth() {
        throw new UnsupportedException("getWidth is not supported for " . get_class($this));
    }

    /**
     * returns is height of the product
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getHeight() {
        throw new UnsupportedException("getHeight is not supported for " . get_class($this));
    }

    /**
     * returns is depth of the product
     * this method has to be overwritten in Pimcore Object
     *
     * @throws UnsupportedException
     * @return float
     */
    public function getDepth() {
        throw new UnsupportedException("getDepth is not supported for " . get_class($this));
    }
}
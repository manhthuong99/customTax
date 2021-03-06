<?php

namespace Gssi\CustomTax\Model\ResourceModel;

class Calculation extends \Magento\Tax\Model\ResourceModel\Calculation
{
    const FIRST_POSITION = 0;
    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $session;

    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Tax\Helper\Data $taxData,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Model\Session $session,
        $connectionName = null
    ) {
        $this->session = $session;
        parent::__construct($context, $taxData, $storeManager, $connectionName);
    }

    public function getRateInfo($request)
    {
        $rates = $this->_getRates($request);
        if ($this->session->isLoggedIn()) {
            $customer = $this->session->getCustomer();
            foreach ($rates as $rateIndex => $rate) {
                $allowStores = $rate['tax_allow_stores'];
                if ($allowStores) {
                    $allowStores = explode(',', $allowStores);
                    $storeCode = $this->_storeManager->getStore()->getCode();
                    if (in_array($storeCode, $allowStores)) {
                        $customerTaxCode = explode('*', $rate['custom_number_tax']);
                        if (isset($customerTaxCode[0])) {
                            $checkTaxCode = strpos($customer->getData('taxvat'), $customerTaxCode[0]);
                            if ($checkTaxCode !== false) {
                                if ($checkTaxCode !== self::FIRST_POSITION) {
                                    unset($rates[$rateIndex]);
                                }
                            } else {
                                unset($rates[$rateIndex]);
                            }
                        }
                    }

                }
            }
        }
        return [
            'process' => $this->getCalculationProcess($request, $rates),
            'value' => $this->_calculateRate($rates)
        ];
    }

    protected function _getRates($request)
    {
        // Extract params that influence our SELECT statement and use them to create cache key
        $storeId = $this->_storeManager->getStore($request->getStore())->getId();
        $customerClassId = $request->getCustomerClassId();
        $countryId = $request->getCountryId();
        $regionId = $request->getRegionId();
        $postcode = $request->getPostcode();

        // Process productClassId as it can be array or usual value. Form best key for cache.
        $productClassId = $request->getProductClassId();
        $ids = is_array($productClassId) ? $productClassId : [$productClassId];
        foreach ($ids as $key => $val) {
            $ids[$key] = (int)$val; // Make it integer for equal cache keys even in case of null/false/0 values
        }
        $ids = array_unique($ids);
        sort($ids);
        $productClassKey = implode(',', $ids);

        // Form cache key and either get data from cache or from DB
        $cacheKey = implode(
            '|',
            [$storeId, $customerClassId, $productClassKey, $countryId, $regionId, $postcode]
        );

        if (!isset($this->_ratesCache[$cacheKey])) {
            // Make SELECT and get data
            $select = $this->getConnection()->select();
            $select->from(
                ['main_table' => $this->getMainTable()],
                [
                    'tax_calculation_rate_id',
                    'tax_calculation_rule_id',
                    'customer_tax_class_id',
                    'product_tax_class_id'
                ]
            )->where(
                'customer_tax_class_id = ?',
                (int)$customerClassId
            );
            if ($productClassId) {
                $select->where('product_tax_class_id IN (?)', $productClassId);
            }
            $ifnullTitleValue = $this->getConnection()->getCheckSql(
                'title_table.value IS NULL',
                'rate.code',
                'title_table.value'
            );
            $ruleTableAliasName = $this->getConnection()->quoteIdentifier('rule.tax_calculation_rule_id');
            $select->join(
                ['rule' => $this->getTable('tax_calculation_rule')],
                $ruleTableAliasName . ' = main_table.tax_calculation_rule_id',
                ['rule.priority', 'rule.position', 'rule.calculate_subtotal']
            )->join(
                ['rate' => $this->getTable('tax_calculation_rate')],
                'rate.tax_calculation_rate_id = main_table.tax_calculation_rate_id',
                [
                    'value' => 'rate.rate',
                    'rate.tax_country_id',
                    'rate.tax_region_id',
                    'rate.tax_postcode',
                    'rate.tax_calculation_rate_id',
                    'rate.code',
                    'rate.custom_number_tax',
                    'rate.tax_allow_stores'
                ]
            )->joinLeft(
                ['title_table' => $this->getTable('tax_calculation_rate_title')],
                "rate.tax_calculation_rate_id = title_table.tax_calculation_rate_id " .
                "AND title_table.store_id = '{$storeId}'",
                ['title' => $ifnullTitleValue]
            )->where(
                'rate.tax_country_id = ?',
                $countryId
            )->where(
                "rate.tax_region_id IN(?)",
                [0, (int)$regionId]
            );
            $postcodeIsNumeric = is_numeric($postcode);
            $postcodeIsRange = false;
            $originalPostcode = null;
            if (is_string($postcode) && preg_match('/^(.+)-(.+)$/', $postcode, $matches)) {
                if ($countryId == self::USA_COUNTRY_CODE && is_numeric($matches[2]) && strlen($matches[2]) == 4) {
                    $postcodeIsNumeric = true;
                    $originalPostcode = $postcode;
                    $postcode = $matches[1];
                } else {
                    $postcodeIsRange = true;
                    $zipFrom = $matches[1];
                    $zipTo = $matches[2];
                }
            }

            if ($postcodeIsNumeric || $postcodeIsRange) {
                $selectClone = clone $select;
                $selectClone->where('rate.zip_is_range IS NOT NULL');
            }
            $select->where('rate.zip_is_range IS NULL');

            if ($postcode != '*' || $postcodeIsRange) {
                $select->where(
                    "rate.tax_postcode IS NULL OR rate.tax_postcode IN('*', '', ?)",
                    $postcodeIsRange ? $postcode : $this->_createSearchPostCodeTemplates($postcode, $originalPostcode)
                );
                if ($postcodeIsNumeric) {
                    $selectClone->where('? BETWEEN rate.zip_from AND rate.zip_to', $postcode);
                } elseif ($postcodeIsRange) {
                    $selectClone->where('rate.zip_from >= ?', $zipFrom)
                        ->where('rate.zip_to <= ?', $zipTo);
                }
            }

            /**
             * @see ZF-7592 issue http://framework.zend.com/issues/browse/ZF-7592
             */
            if ($postcodeIsNumeric || $postcodeIsRange) {
                $select = $this->getConnection()->select()->union(
                    ['(' . $select . ')', '(' . $selectClone . ')']
                );
            }

            $select->order(
                'priority ' . \Magento\Framework\DB\Select::SQL_ASC
            )->order(
                'tax_calculation_rule_id ' . \Magento\Framework\DB\Select::SQL_ASC
            )->order(
                'tax_country_id ' . \Magento\Framework\DB\Select::SQL_DESC
            )->order(
                'tax_region_id ' . \Magento\Framework\DB\Select::SQL_DESC
            )->order(
                'tax_postcode ' . \Magento\Framework\DB\Select::SQL_DESC
            )->order(
                'value ' . \Magento\Framework\DB\Select::SQL_DESC
            );

            $fetchResult = $this->getConnection()->fetchAll($select);
            $filteredRates = [];
            if ($fetchResult) {
                foreach ($fetchResult as $rate) {
                    if (!isset($filteredRates[$rate['tax_calculation_rate_id']])) {
                        $filteredRates[$rate['tax_calculation_rate_id']] = $rate;
                    }
                }
            }
            $this->_ratesCache[$cacheKey] = array_values($filteredRates);
        }

        return $this->_ratesCache[$cacheKey];
    }
}

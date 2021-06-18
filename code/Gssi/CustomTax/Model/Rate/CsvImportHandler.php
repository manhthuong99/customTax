<?php

namespace Gssi\CustomTax\Model\Rate;

class CsvImportHandler extends \Magento\TaxImportExport\Model\Rate\CsvImportHandler
{
    /**
     * Import Tax Rates from CSV file
     *
     * @param array $file file info retrieved from $_FILES array
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function importFromCsvFile($file)
    {
        if (!isset($file['tmp_name'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid file upload attempt.'));
        }
        $ratesRawData = $this->csvProcessor->getData($file['tmp_name']);
        // first row of file represents headers
        $fileFields = $ratesRawData[0];
        $validFields = $this->_filterFileFields($fileFields);
        $invalidFields = array_diff_key($fileFields, $validFields);
        $ratesData = $this->_filterRateData($ratesRawData, $invalidFields, $validFields);
        // store cache array is used to quickly retrieve store ID when handling locale-specific tax rate titles
        $storesCache = $this->_composeStoreCache($validFields);
        $regionsCache = [];
        $keys = [];
        foreach ($ratesData as $rowIndex => $dataRow) {
            // skip headers
            if ($rowIndex == 0) {
                foreach ($dataRow as $key => $value) {
                    if ($value == 'Customer Tax') {
                        $keys['custom_number_tax'] = $key;
                    }
                    if ($value == 'Allow Stores') {
                        $keys['tax_allow_stores'] = $key;
                    }
                }
                unset($rowIndex[0]);
                continue;
            }
            $regionsCache = $this->_importRate($dataRow, $regionsCache, $storesCache, $keys);
        }
    }

    /**
     * Import single rate
     *
     * @param array $rateData
     * @param array $regionsCache cache of regions of already used countries (is used to optimize performance)
     * @param array $storesCache cache of stores related to tax rate titles
     * @param array $keys
     * @return array regions cache populated with regions related to country of imported tax rate
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _importRate(array $rateData, array $regionsCache, array $storesCache, $keys = [])
    {
        // data with index 1 must represent country code
        $countryCode = $rateData[1];
        $country = $this->_countryFactory->create()->loadByCode($countryCode, 'iso2_code');
        if (!$country->getId()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Country code is invalid: %1', $countryCode));
        }
        $regionsCache = $this->_addCountryRegionsToCache($countryCode, $regionsCache);

        // data with index 2 must represent region code
        $regionCode = $rateData[2];
        if (!empty($regionsCache[$countryCode][$regionCode])) {
            $regionId = $regionsCache[$countryCode][$regionCode] == '*' ? 0 : $regionsCache[$countryCode][$regionCode];
            // data with index 3 must represent postcode
            $postCode = empty($rateData[3]) ? null : $rateData[3];
            $modelData = [
                'code' => $rateData[0],
                'tax_country_id' => $rateData[1],
                'tax_region_id' => $regionId,
                'tax_postcode' => $postCode,
                'rate' => $rateData[4],
                'zip_is_range' => $rateData[5],
                'zip_from' => $rateData[6],
                'zip_to' => $rateData[7],
            ];
            if (isset($keys['custom_number_tax'])) {
                $modelData['custom_number_tax'] = $rateData[$keys['custom_number_tax']];
            }
            if (isset($keys['tax_allow_store'])) {
                $modelData['tax_allow_store'] = $rateData[$keys['tax_allow_store']];
            }

            // try to load existing rate
            /** @var $rateModel \Magento\Tax\Model\Calculation\Rate */
            $rateModel = $this->_taxRateFactory->create()->loadByCode($modelData['code']);
            $rateModel->addData($modelData);

            // compose titles list
            $rateTitles = [];
            foreach ($storesCache as $fileFieldIndex => $storeId) {
                $rateTitles[$storeId] = $rateData[$fileFieldIndex];
            }

            $rateModel->setTitle($rateTitles);
            $rateModel->save();
        }

        return $regionsCache;
    }
}

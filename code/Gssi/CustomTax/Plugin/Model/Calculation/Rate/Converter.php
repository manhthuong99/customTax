<?php

namespace Gssi\CustomTax\Plugin\Model\Calculation\Rate;

class Converter
{
    public function afterPopulateTaxRateData(\Magento\Tax\Model\Calculation\Rate\Converter $converter, $result, $formData)
    {
        if (@$formData['custom_number_tax']) {
            $result->setData('custom_number_tax', $formData['custom_number_tax']);
        }
        if (@$formData['tax_allow_stores']) {
            $result->setData('tax_allow_stores', implode(',', $formData['tax_allow_stores']));
        }
        return $result;
    }
}

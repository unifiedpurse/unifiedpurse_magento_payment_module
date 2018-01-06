<?php

class Mage_Unifiedpurse_Model_Source_PaymentAction
{
    public function toOptionArray()
    {
        return array(
            array('value' => Mage_Unifiedpurse_Model_Config::PAYMENT_TYPE_PAYMENT, 'label' => Mage::helper('unifiedpurse')->__('PAYMENT')),
            array('value' => Mage_Unifiedpurse_Model_Config::PAYMENT_TYPE_DEFERRED, 'label' => Mage::helper('unifiedpurse')->__('DEFERRED')),
            array('value' => Mage_Unifiedpurse_Model_Config::PAYMENT_TYPE_AUTHENTICATE, 'label' => Mage::helper('unifiedpurse')->__('AUTHENTICATE')),
        );
    }
}
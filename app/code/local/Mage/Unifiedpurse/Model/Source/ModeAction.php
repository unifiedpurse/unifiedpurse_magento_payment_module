<?php

class Mage_Unifiedpurse_Model_Source_ModeAction
{
    public function toOptionArray()
    {
        return array(
            array('value' => Mage_Unifiedpurse_Model_Config::MODE_LIVE, 'label' => Mage::helper('unifiedpurse')->__('Live')),
            array('value' => Mage_Unifiedpurse_Model_Config::MODE_TEST, 'label' => Mage::helper('unifiedpurse')->__('Test')),
        );
    }
}




<?php

class Mage_unifiedpurse_Model_Mysql4_Api_Debug_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('unifiedpurse/api_debug');
    }
}
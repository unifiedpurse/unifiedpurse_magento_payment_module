<?php


class Mage_Unifiedpurse_Block_Standard_History extends Mage_Core_Block_Template
{
    /**
     *  Return StatusDetail field value from Response
     *
     *  @return	  string
     */
    public function getErrorMessage ()
    {
        $error = Mage::getSingleton('checkout/session')->getErrorMessage();
        Mage::getSingleton('checkout/session')->unsErrorMessage();
        return $error;
    }

    /**
     * Get continue shopping url
     */
    public function getContinueShoppingUrl()
    {
        return Mage::getUrl('checkout/cart');
    }
	
	
	
}
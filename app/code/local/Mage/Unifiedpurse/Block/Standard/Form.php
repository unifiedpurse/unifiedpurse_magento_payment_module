<?php


class Mage_Unifiedpurse_Block_Standard_Form extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        $this->setTemplate('unifiedpurse/standard/form.phtml');
        parent::_construct();
    }
}
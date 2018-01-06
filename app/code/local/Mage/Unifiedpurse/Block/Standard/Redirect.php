<?php


class Mage_Unifiedpurse_Block_Standard_Redirect extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $standard = Mage::getModel('unifiedpurse/standard');
        $form = new Varien_Data_Form();
        $form->setAction($standard->getUnifiedpurseUrl())
            ->setId('unifiedpurse_standard_checkout')
            ->setName('unifiedpurse_standard_checkout')
            ->setMethod('POST')
            ->setUseContainer(true);
		$form_fields=$standard->setOrder($this->getOrder())->getStandardCheckoutFormFields();
        foreach ($form_fields as $field => $value) {
            $form->addField($field, 'hidden', array('name' => $field, 'value' => $value));
        }
        $html = '<html><body>
        ';
        $html.= $this->__('You will be redirected to Unifiedpurse in a few seconds.');
        $html.= $form->toHtml();
        $html.= '
        <script type="text/javascript">document.getElementById("unifiedpurse_standard_checkout").submit();</script>';
        $html.= '</body></html>';

        return $html;
    }
}
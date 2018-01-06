<?php

class Mage_Unifiedpurse_StandardController extends Mage_Core_Controller_Front_Action
{
    public $isValidResponse = false;

    /**
     * Get singleton with Unifiedpurse strandard
     *

     */
    public function getStandard()
    {
        return Mage::getSingleton('unifiedpurse/standard');
    }

	
	public function updateTransaction($transaction_reference,$update_data){
		$write = Mage::getSingleton('core/resource')->getConnection('core_write');
		$write->update('mage_unifiedpurse',$update_data,"transaction_reference='$transaction_reference'");
		return true;
	}
	
	function getTransaction($transaction_reference){
		$read = Mage::getSingleton('core/resource')->getConnection('core_read');
		$readresult = $read->query("SELECT * FROM mage_unifiedpurse WHERE transaction_reference='$transaction_reference' LIMIT 1");
		if($row = $readresult->fetch() )return $row;
		return array();
	}
	
	
	
    /**
     * Get Config model
     *
     */
    public function getConfig()
    {
        return $this->getStandard()->getConfig();
    }

    /**
     *  Return debug flag
     *
     *  @return  boolean
     */
    public function getDebug ()
    {
        return $this->getStandard()->getDebug();
    }

    /**
     * When a customer chooses Unifiedpurse on Checkout/Payment page
     *
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setUnifiedpurseStandardQuoteId($session->getQuoteId());

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        $order->addStatusToHistory(
            $order->getStatus(),
            Mage::helper('unifiedpurse')->__('Customer was redirected to Unifiedpurse')
        );
        $order->save();

        $this->getResponse()
            ->setBody($this->getLayout()
                ->createBlock('unifiedpurse/standard_redirect')
                ->setOrder($order)
                ->toHtml());

        $session->unsQuoteId();
    }

    /**
     *  Success response from Unifiedpurse
     *
     *  @return	  void
     */
    public function  successResponseAction()
    {
        $this->preResponse();

        if (!$this->isValidResponse) {
            $this->_redirect('');
            return ;
        }		

		/*
		if ($this->getDebug()) {
			Mage::getModel('unifiedpurse/api_debug')
				->setResponseBody(print_r($this->responseArr,1))
				->save();
		}
		*/

		
		$transaction_reference=empty($_POST['ref'])?$this->responseArr['ref']:$_POST['ref'];
		if(empty($transaction_reference))$transaction=array();
		else 
		{
			$transaction=$this->getTransaction($transaction_reference);
			
			if(empty($transaction)){
				$transaction=array('transaction_reference'=>0,'order_id'=>0,'response_description'=>'Transaction record not found','status'=>-1);
			}
			elseif($transaction['status']==0)
			{
				$unifiedpurse_merchant_id=$this->getConfig()->getMerchantID();
				$order_amount=$transaction['transaction_amount'];			
				
				$approved_amount=0;
				$response_description="";
				$response_code="";

				$url="https://unifiedpurse.com/api_v1?receiver=$unifiedpurse_merchant_id&action=get_transaction&ref=$transaction_reference&amount=$order_amount&currency={$transaction['currency_code']}";
				
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);			
				curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_URL, $url);
				
				$response = @curl_exec($ch);
				$returnCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if($returnCode != 200)$response=curl_error($ch);
				curl_close($ch);	
				$json=null;
				
				if($returnCode == 200)$json=@json_decode($response,true);
				else $response="HTTP Error $returnCode: $response. ";
				$new_status=0;
				
				if(!empty($json))
				{
					$response_description=$json['info'];
					$approved_amount=$json['amount'];
					
					$response_code=$new_status=$json['status'];	
					
					$update_data=array('response_description'=>$response_description,'response_code'=>$response_code,'approved_amount'=>$approved_amount,'status'=>$new_status);
					
					//$order_amount= sprintf('%.2f', $order->getBaseGrandTotal());  //todo: re-arrange
					
					$order = Mage::getModel('sales/order');
					
						$order->loadByIncrementId($transaction['order_id']);

						if (!$order->getId()) {
							/*
							* need to have logic when there is no order with the order id from Unifiedpurse
							*/
							return false;
						}

						$order->addStatusToHistory(
							$order->getStatus(),
							Mage::helper('unifiedpurse')->__('Customer successfully returned from Unifiedpurse')
						);

						//$order->setEmailSent(false); 
						$order->sendNewOrderEmail();

						if ($new_status==-1) {
							// cancel order
							$order->cancel();
							$order->addStatusToHistory(
								$order->getStatus(),
								Mage::helper('unifiedpurse')->__($response_description)
							);
						} elseif (sprintf('%.2f', $approved_amount) != sprintf('%.2f', $order->getBaseGrandTotal())) {
							// cancel order
							$order->cancel();
							$order->addStatusToHistory(
								$order->getStatus(),
								Mage::helper('unifiedpurse')->__('Order total amount does not match Unifiedpurse gross total amount')
							);
						} else {
							$order->getPayment()->setTransactionId($transaction_reference); //todo; or order id

							if ($this->saveInvoice($order)) {
								$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
							} else {
								$newOrderStatus = $this->getConfig()->getNewOrderStatus() ?
									$this->getConfig()->getNewOrderStatus() : Mage_Sales_Model_Order::STATE_NEW;
							}
							$this->updateTransaction($transaction_reference,$update_data);
						}
						$order->save();

						$session = Mage::getSingleton('checkout/session');
						$session->setQuoteId($session->getUnifiedpurseStandardQuoteId(true));
						Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();				
				}
				else //just display it, don't do any processing
				{
					$response_description=$response;
					$response_code=$returnCode;
				}
				
				$transaction['status']=$new_status;
				$transaction['response_description']=$response_description;
			}
			
		}
		$this->renderResponse($transaction);
    }

    /**
     *  Save invoice for order
     *
     *  @param    Mage_Sales_Model_Order $order
     *  @return	  boolean Can save invoice or not
     */
    protected function saveInvoice (Mage_Sales_Model_Order $order)
    {
        if ($order->canInvoice()) {
            $invoice = $order->prepareInvoice();

            $invoice->register()->capture();
            Mage::getModel('core/resource_transaction')
               ->addObject($invoice)
               ->addObject($invoice->getOrder())
               ->save();
            return true;
        }

        return false;
    }

    /**
     *  Expected GET HTTP Method
     *
     *  @return	  void
     */
    protected function preResponse ()
    {
		$this->responseArr = array_merge($_GET,$_POST);    
		$this->isValidResponse = true;
    }

    /**
     *  History Action
     *
     *  @return	  void
     */
    public function historyAction () //aka failureAction obsolete
    {
        $session = Mage::getSingleton('checkout/session');
		/*
        if (!$session->getErrorMessage()) {
            $this->_redirect('checkout/cart');
            return;
        }
		*/
		
        $this->loadLayout();
        $this->_initLayoutMessages('unifiedpurse/session');
        $this->renderLayout();
    }
	
	protected function getHistoryURL ()
    {
        return Mage::getUrl('unifiedpurse/standard/successresponse');
    }
	
	protected function addQueryArg ($params, $url){
		if(!stristr($url,'?'))$url.='?';
		foreach($params as $pk=>$pv)$url.="&$pk=$pv";
		return $url;
	}
	
	public function isAdmin(){
		
		$is_admin=false;
		
		$switchSessionName = 'adminhtml';
		$currentSessionId = Mage::getSingleton('core/session')->getSessionId();
		$currentSessionName = Mage::getSingleton('core/session')->getSessionName();
		if ($currentSessionId && $currentSessionName && isset($_COOKIE[$currentSessionName])) {
			$switchSessionId = $_COOKIE[$switchSessionName];
			$this->_switchSession($switchSessionName, $switchSessionId);			
			$user = Mage::getSingleton('admin/session')->getUser();
			if(empty($user))$is_admin=0;
			else $is_admin= $user->getUserId();
			$this->_switchSession($currentSessionName, $currentSessionId);
		}
		
		return $is_admin;
	}
	
	protected function _switchSession($namespace, $id = null) {
		session_write_close();
		$GLOBALS['_SESSION'] = null;
		$session = Mage::getSingleton('core/session');
		if ($id) {
			$session->setSessionId($id);
		}
		$session->start($namespace);
	}

	protected function customerLoggedIn(){
		return Mage::getSingleton('customer/session')->isLoggedIn();
	}
	
	
	public function renderResponse ($trans)
	{
		$toecho="";
		
		if(!empty($trans)){
			
			$style="<style type='text/css'>
					.errorMessage,.successMsg
					{
						color:#ffffff;
						font-size:18px;
						font-family:helvetica;
						border-radius:9px
						display:inline-block;
						max-width:100%;
						width:420px;
						border-radius: 8px;
						padding: 4px;
						margin:auto;
					}
					
					.errorMessage
					{
						background-color:#ff5500;
					}
					
					.successMsg
					{
						background-color:#00aa99;
					}
					
					body,html{min-width:100%;}
				</style>";
				
			$home_url=Mage::getUrl('');
			$history_url=$this->getHistoryURL();
				
			if($trans['status']==1){
				$temp_url=Mage::getUrl('checkout/onepage/success');
				
					$toecho="<div class='successMsg'>
						{$trans['response_description']}<br/>
						Your order has been successfully Processed <br/>
						ORDER ID: {$trans['order_id']}<br/>
						TRANSACTION REFERRENCE: {$trans['transaction_reference']}<br/>
						[<a href='$history_url'>Transaction Hisotry</a>]  
						[<a href='$temp_url'>Continue</a>]
						</div>";
			}
			else
			{
				$temp_url=Mage::getUrl('checkout/cart');
				
					$toecho="<div class='errorMessage'>
						Your transaction was not successful<br/>
						REASON: {$trans['response_description']}<br/>
						ORDER ID: {$trans['order_id']}<br/>
						TRANSACTION REFERRENCE: {$trans['transaction_reference']}<br/>
						[<a href='$history_url'>Transaction Hisotry</a>]  
						[<a href='$temp_url'>Continue Shopping</a>]
						</div>";
			}
		}
		else 
		{
			$checkout_url=$this->getHistoryURL();
			$home_url=Mage::getUrl('');
			
			$email_form="";
			
			if($this->isAdmin()){
				$f_email=addslashes(trim(@$_GET['f_email']));
				$dwhere=empty($f_email)?"":"  WHERE customer_email='$f_email' ";	
				$email_form="
			<div class='text-right'>
				<form method='get' action='$checkout_url' class='form-inline'>
					<div class='form-group'>
						<label for='f_email'>Email</label>
						<input type='email' class='form-control input-sm' name='f_email' value='$f_email' />
					</div>
					<button class='btn btn-sm btn-info'>Fetch</button>
				</form>
			</div>";
			} elseif($this->customerLoggedIn()) {
				$customer = Mage::getSingleton('customer/session')->getCustomer();
				//http://www.kathirvel.com/magento-get-loggedin-customer-fullname-firstname-lastname-email-address/
				$f_email= $customer->getEmail();
				$dwhere="  WHERE customer_email='$f_email' ";
			}
			else {
				$email_form="<div class='alert alert-danger'>YOU ARE NOT LOGGED IN</div>";
				$f_email='';
				$dwhere="  WHERE customer_email='' ";
			}
			
			$sql="SELECT COUNT(*) as count_all FROM mage_unifiedpurse $dwhere";
			$read = Mage::getSingleton('core/resource')->getConnection('core_read');
			$readresult = $read->query($sql);
			
			if($row = $readresult->fetch() )$num = $row['count_all'];
			else $num=0;
			

			$toecho.="<h3><i class='fa fa-credit-card'></i>
				Unifiedpurse Transactions 
				<a href='$home_url' class='btn btn-sm btn-link pull-right'><i class='fa fa-home'></i> HOME</a>
			</h3>$email_form
			<hr/>";

			if($num==0)$toecho.="<strong>No record found from transactions made through Unifiedpurse</strong>";
			else
			{
				$perpage=10;
				$totalpages=ceil($num/$perpage);
				$p=empty($_GET['p'])?1:$_GET['p'];
				if($p>$totalpages)$p=$totalpages;
				if($p<1)$p=1;
				$offset=($p-1) *$perpage;
				$sql="SELECT * FROM mage_unifiedpurse $dwhere ORDER BY id DESC LIMIT $offset,$perpage ";
				$query=$read->query($sql);
				$toecho.="
						<table style='width:100%;' class='table table-striped table-condensed' >
							<tr style='width:100%;text-align:center;'>
								<th>
									S/N
								</th>
								<th>
									EMAIL
								</th>
								<th>
									TRANS. REF.
								</th>
								<th>
									TRANS. DATE
								</th>
								<th>
									TRANS. AMOUNT
								</th>
								<th>
									APPROVED AMOUNT
								</th>
								<th>
									TRANSACTION	RESPONSE
								</th>
								<th>
									ACTION
								</th>
							</tr>";
				$sn=$offset;
				foreach($query  as $row)
				{
					$row=(array)$row;
					$sn++;
					
					if($row['status']==0)
					{
						$history_params=array('ref'=>$row['transaction_reference'],'p'=>$p);
						$history_url = $this->addQueryArg ($history_params, $checkout_url);
						$trans_action="<a href='$history_url' class='btn btn-xs btn-primary' >REQUERY</a>";
					}
					else
					{
						$trans_action='NONE';						
					}
					$date_time=date('d-m-Y g:i a',$row['time']);
					$transaction_response=$row['response_description'];
					
					if(empty($transaction_response))$transaction_response='(pending)';
					if(empty($row['approved_amount']))$row['approved_amount']='0.00';
					
					$toecho.="<tr align='center'>
								<td>
									$sn
								</td>
								<td>
									{$row['customer_email']} <br/>
									(<i>{$row['customer_fullname']}</i>)
								</td>
								<td>
									{$row['transaction_reference']}
								</td>
								<td>
									$date_time
								</td>
								<td>
									{$row['transaction_amount']}
									{$row['currency_code']}
								</td>
								<td>
									{$row['approved_amount']}
									{$row['currency_code']}
								</td>
								<td>
									$transaction_response
								</td>
								<td>
									$trans_action
								</td>								
							 </tr>";
				}
				$toecho.="</table>";
				
				$pagination="";
				$prev=$p-1;
				$next=$p+1;
				
				$history_params=array('f_email'=>$f_email);
			
				if($totalpages>2&&$prev>1){
					$history_params['p']=1;
					$history_url = $this->addQueryArg ($history_params, $checkout_url);
					$pagination.=" <li><a href='$history_url'>&lt;&lt;</a></li> ";	
				}
				if($prev>=1){
					$history_params['p']=$prev;
					$history_url = $this->addQueryArg ($history_params, $checkout_url);
					$pagination.=" <li><a href='$history_url' >&lt;</a></li>  ";
				}
				if($next<=$totalpages){
					$history_params['p']=$next;
					$history_url = $this->addQueryArg ($history_params, $checkout_url);
					$pagination.=" <li><a href='$history_url'> > </a></li> ";
				}
				if($next<=$totalpages){
					$history_params['p']=$totalpages;
					$history_url = $this->addQueryArg ($history_params, $checkout_url);
					$pagination.=" <li><a href='$history_url'> >> </a></li> ";
				}

				$pagination="<ul class='pagination pagination-sm' style='margin:0px;'><span class='btn btn-default btn-sm disabled'>PAGE: $p of $totalpages</span> $pagination</ul>";
				
				$toecho.="<div class='text-right' >$pagination</div>";
			}
		}
		
		echo "<!DOCTYPE html>
		<html lang='en'>
		<head>
			<meta charset='utf-8'>
			<meta http-equiv='X-UA-Compatible' content='IE=edge'>
			<meta name='viewport' content='width=device-width, initial-scale=1'>
			<title>Unifiedpurse Payment Transaction</title>
			<link href='//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css' rel='stylesheet'>
			<link href='//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap-theme.min.css' rel='stylesheet'>
			<link href='//maxcdn.bootstrapcdn.com/font-awesome/4.2.0/css/font-awesome.min.css' rel='stylesheet'>
			<script src='//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js'></script>
			<script src='//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js'></script>
			<style type='text/css'>
				.errorMessage,.successMsg
				{
					color:#ffffff;
					font-size:18px;
					font-family:helvetica;
					border-radius:9px
					display:inline-block;
					max-width:50%;
					border-radius: 8px;
					padding: 4px;
					margin:auto;
				}
				
				.errorMessage
				{
					background-color:#ff5500;
				}
				
				.successMsg
				{
					background-color:#00aa99;
				}
				
				body,html{min-width:100%;}
			</style>
		</head>
		<body>
			<div class='container' style='min-height: 500px;padding-top:15px;padding-bottom:15px;'>
				$toecho
			</div>
		</body>
		</html>
		";
		
	}
}

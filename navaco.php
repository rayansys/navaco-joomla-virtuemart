<?php
defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

if (!class_exists ('checkHack')) {
    require_once( VMPATH_ROOT . '/plugins/vmpayment/navaco/helper/inputcheck.php');
}


class plgVmPaymentNavaco extends vmPSPlugin {
    private $url = "https://fcp.shaparak.ir/nvcservice/Api/v2/";
    function __construct (& $subject, $config) {
        parent::__construct ($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys ($this->getTableSQLFields ());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = array(
            'merchant_id' => array('', 'varchar'),
            'username' => array('', 'varchar'),
            'password' => array('', 'varchar'),
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL () {
        return $this->createTableSQL('Payment navaco Table');
    }

    function getTableSQLFields () {

        $SQLfields = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'order_pass'                  => 'varchar(50)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'crypt_virtuemart_pid' 	      => 'varchar(255)',
            'salt'                        => 'varchar(255)',
            'payment_name'                => 'varchar(5000)',
            'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'mobile'                      => 'varchar(12)',
            'tracking_code'               => 'varchar(50)'
        );

        return $SQLfields;
    }


    function plgVmConfirmedOrder ($cart, $order)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL;
        }

        $app	= JFactory::getApplication();
        $session = JFactory::getSession();
        $salt = JUserHelper::genRandomPassword(32);
        $crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
        if ($session->isActive('uniq')) {
            $session->clear('uniq');
        }
        $session->set('uniq', $crypt_virtuemartPID);

        $payment_currency = $this->getPaymentCurrency($method,$order['details']['BT']->payment_currency_id);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$payment_currency);
        $email_currency = $this->getEmailCurrency($method);
        $dbValues['payment_name'] = $this->renderPluginName ($method) . '<br />';
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['order_pass'] = $order['details']['BT']->order_pass;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
        $dbValues['salt'] = $salt;
        $dbValues['payment_currency'] = $order['details']['BT']->order_currency;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['amount'] = $totalInPaymentCurrency['value'];
        $dbValues['mobile'] = $order['details']['BT']->phone_2;
        $this->storePSPluginInternalData ($dbValues);
        $id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
        $app	= JFactory::getApplication();
        $Amount = $totalInPaymentCurrency['value'];
        $MerchantID = $method->merchant_id;
        $username = $method->username;
        $password = $method->password;
        $CallbackURL = JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&gid=3';

        $postField = [
            "CARDACCEPTORCODE"=>$MerchantID,
            "USERNAME"=>$username,
            "USERPASSWORD"=>$password,
            "PAYMENTID"=>$order['details']['BT']->virtuemart_order_id,
            "AMOUNT"=>$Amount,
            "CALLBACKURL"=>($CallbackURL),
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url.'PayRequest');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postField));
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Accept: application/json"));

        $curl_exec = curl_exec($curl);

        curl_close($curl);

        $result 		= json_decode($curl_exec);
        $resultStatus 	= $result->ActionCode;

        if ($resultStatus == 0)
        {
            header("Location: {$result->RedirectUrl}");
        } else {
            $result_error 	= (isset($result->ActionCode) && $result->ActionCode != "") ? $result->ActionCode : "error";
            $msg 			= $this->getGateMsg($result_error);
            $link 			= JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
            $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
        }
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $app		= JFactory::getApplication();
        $jinput 	= $app->input;
        $gateway 	= $jinput->get->get('gid', '0', 'INT');

        if ($gateway == '3')
        {
            $data 	= $jinput->post->get('Data', '', 'STRING');
            $data = json_decode($data);

            $session = JFactory::getSession();



            if ($session->isActive('uniq') && $session->get('uniq') != null) {
                $cryptID = $session->get('uniq');
            } else {
                $msg = $this->getGateMsg(404);
                $link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
                $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
            }

            $orderInfo = $this->getOrderInfo($cryptID);


            if ($orderInfo != null){
                if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
                    return NULL;
                }
            }
            else {
                return NULL;
            }

            $salt 	= $orderInfo->salt;
            $id 	= $orderInfo->virtuemart_order_id;
            $uId 	= $cryptID.':'.$salt;

            $order_id 	= $orderInfo->order_number;
            $payment_id = $orderInfo->virtuemart_paymentmethod_id;
            $pass_id 	= $orderInfo->order_pass;
            $price 		= round($orderInfo->amount,5);
            $method 	= $this->getVmPluginMethod ($payment_id);



            if (JUserHelper::verifyPassword($id , $uId))
            {

                if ($data->ActionCode == 0)
                {

                    $curl = curl_init();
                    $postField = [
                        "CARDACCEPTORCODE"=>$method->merchant_id,
                        "USERNAME"=>$method->username,
                        "USERPASSWORD"=>$method->password,
                        "PAYMENTID"=>$id,
                        "RRN"=>$data->RRN,
                    ];
                    curl_setopt($curl, CURLOPT_URL, $this->url."Confirm");
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postField));
                    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_POST, 1);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Accept: application/json"));

                    $curl_exec = curl_exec($curl);
                    curl_close($curl);

                    $result = json_decode($curl_exec);
                    $resultStatus = (int)$result->ActionCode;

                    if ($resultStatus == 0)
                    {
                        $msg 	= $this->getGateMsg($resultStatus);

                        $html 	= $this->renderByLayout('navaco_payment', array(
                            'order_number' =>$order_id,
                            'order_pass' =>$pass_id,
                            'tracking_code' => $result->RRN,
                            'status' => $msg
                        ));

                        $this->updateStatus ('C',1,$msg,$id);
                        $this->updateOrderInfo ($id,$result->RRN);
                        return true;
                    }
                    else {
                        $msg= $this->getGateMsg($resultStatus);
                        $link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
                        $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
                    }

                }
                else {
                    $msg= $this->getGateMsg(intval(103));
                    $this->updateStatus ('X',0,$msg,$id);
                    $link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
                    $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
                }
            }
            else {
                $msg= $this->getGateMsg(404);
                $link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
                $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
            }
        }
        else {
            return NULL;
        }
    }


    protected function getOrderInfo ($id){
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->qn('#__virtuemart_payment_plg_navaco'));
        $query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
        $db->setQuery((string)$query);
        $result = $db->loadObject();
        return $result;
    }

    protected function updateOrderInfo ($id,$trackingCode){
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
        $conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
        $query->update($db->qn('#__virtuemart_payment_plg_navaco'));
        $query->set($fields);
        $query->where($conditions);

        $db->setQuery($query);
        $result = $db->execute();
    }


    protected function checkConditions ($cart, $method, $cart_prices) {
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        if($this->_toConvert){
            $this->convertToVendorCurrency($method);
        }

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array ($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        if (!is_array ($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
            return TRUE;
        }

        return FALSE;
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return false;
            } else {
                return false;
            }
        }
        $method_name = $this->_psType . '_name';

        $htmla = array();
        foreach ($this->methods as $this->_currentMethod) {
            if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

                $html = '';
                $cartPrices=$cart->cartPrices;
                if (isset($this->_currentMethod->cost_method)) {
                    $cost_method=$this->_currentMethod->cost_method;
                } else {
                    $cost_method=true;
                }
                $methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

                $this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
                $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
                $html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                $htmla[] = $html;
            }
        }
        $htmlIn[] = $htmla;
        return true;

    }

    public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        return $this->OnSelectCheck ($cart);
    }

    function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
        return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return NULL;
        }
        return true;
    }

    function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

        return $this->onStoreInstallPluginTable ($jplugin_id);
    }


    function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
        return $this->onShowOrderPrint ($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

        return $this->setOnTablePluginParams ($name, $id, $table);
    }

    static function getPaymentCurrency (&$method, $selectedUserCurrency = false) {

        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model = VmModel::getModel( 'vendor' );
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies( $method->virtuemart_vendor_id );

            if(!$selectedUserCurrency) {
                if($method->payment_currency == -1) {
                    $mainframe = JFactory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest( "virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt( 'virtuemart_currency_id', $vendor_currencies['vendor_currency'] ) );
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if(in_array($selectedUserCurrency,$vendor_currencies['all_currencies'])){
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }

    }

    public function getGateMsg ($msgId) {
        switch((int)$msgId)
        {
            case	-1: $out = 'کلید نامعتبر است'; break;
            case	0: $out = 'تراکنش با موفقیت انجام شد.'; break;
            case	1: $out = 'صادرکننده ی کارت از انجام تراکنش صرف نظر کرد.'; break;
            case	2: $out = 'عملیات تاییدیه این تراکنش قبلا با موفقیت صورت پذیرفته است.'; break;
            case	3: $out = 'پذیرنده ی فروشگاهی نامعتبر است.'; break;
            case	5: $out = 'از انجام تراکنش صرف نظر شد.'; break;
            case	6: $out = 'بروز خطا'; break;
            case	7: $out = 'به دلیل شرایط خاص کارت توسط دستگاه ضبط شود.'; break;
            case	8: $out = 'باتشخیص هویت دارنده ی کارت، تراکنش موفق می باشد.'; break;
            case	9: $out = 'در حال حاضر امکان پاسخ دهی وجود ندارد'; break;
            case	12: $out = 'تراکنش نامعتبر است.'; break;
            case	13: $out = 'مبلغ تراکنش اصلاحیه نادرست است.'; break;
            case	14: $out = 'شماره کارت ارسالی نامعتبر است. (وجود ندارد)'; break;
            case	15: $out = 'صادرکننده ی کارت نامعتبراست.(وجود ندارد)'; break;
            case	16: $out = 'تراکنش مورد تایید است و اطلاعات شیار سوم کارت به روز رسانی شود.'; break;
            case	19: $out = 'تراکنش مجدداً ارسال شود.'; break;
            case	20: $out = 'خطای ناشناخته از سامانه مقصد'; break;
            case	23: $out = 'کارمزد ارسالی پذیرنده غیر قابل قبول است.'; break;
            case	25: $out = 'شماره شناسایی صادرکننده غیر معتبر'; break;
            case	30: $out = 'قالب پیام دارای اشکال است.'; break;
            case	31: $out = 'پذیرنده توسط سوئیچ پشتیبانی نمی شود.'; break;
            case	33: $out = 'تاریخ انقضای کارت سپری شده است'; break;
            case	34: $out = 'دارنده کارت مظنون به تقلب است.'; break;
            case	36: $out = 'کارت محدود شده است.کارت توسط دستگاه ضبط شود.'; break;
            case	38: $out = 'تعداد دفعات ورود رمز غلط بیش از حدمجاز است.'; break;
            case	39: $out = 'کارت حساب اعتباری ندارد.'; break;
            case	40: $out = 'عملیات درخواستی پشتیبانی نمی گردد.'; break;
            case	41: $out = 'کارت مفقودی می باشد.'; break;
            case	42: $out = 'کارت حساب عمومی ندارد.'; break;
            case	43: $out = 'کارت مسروقه می باشد.'; break;
            case	44: $out = 'کارت حساب سرمایه گذاری ندارد.'; break;
            case	48: $out = 'تراکنش پرداخت قبض قبلا انجام پذیرفته'; break;
            case	51: $out = 'موجودی کافی نیست.'; break;
            case	52: $out = 'کارت حساب جاری ندارد.'; break;
            case	53: $out = 'کارت حساب قرض الحسنه ندارد.'; break;
            case	54: $out = 'تاریخ انقضای کارت سپری شده است.'; break;
            case	55: $out = 'Pin-Error'; break;
            case	56: $out = 'کارت نا معتبر است.'; break;
            case	57: $out = 'انجام تراکنش مربوطه توسط دارنده ی کارت مجاز نمی باشد.'; break;
            case	58: $out = 'انجام تراکنش مربوطه توسط پایانه ی انجام دهنده مجاز نمی باشد.'; break;
            case	59: $out = 'کارت مظنون به تقلب است.'; break;
            case	61: $out = 'مبلغ تراکنش بیش از حد مجاز است.'; break;
            case	62: $out = 'کارت محدود شده است.'; break;
            case	63: $out = 'تمهیدات امنیتی نقض گردیده است.'; break;
            case	64: $out = 'مبلغ تراکنش اصلی نامعتبر است.(تراکنش مالی اصلی با این مبلغ نمی باشد)'; break;
            case	65: $out = 'تعداد درخواست تراکنش بیش از حد مجاز است.'; break;
            case	67: $out = 'کارت توسط دستگاه ضبط شود.'; break;
            case	75: $out = 'تعداد دفعات ورود رمزغلط بیش از حد مجاز است.'; break;
            case	77: $out = 'روز مالی تراکنش نا معتبر است.'; break;
            case	78: $out = 'کارت فعال نیست.'; break;
            case	79: $out = 'حساب متصل به کارت نامعتبر است یا دارای اشکال است.'; break;
            case	80: $out = 'خطای داخلی سوییچ رخ داده است'; break;
            case	81: $out = 'خطای پردازش سوییچ'; break;
            case	83: $out = 'ارائه دهنده خدمات پرداخت یا سامانه شاپرک اعلام Sign Off نموده است.'; break;
            case	84: $out = 'Host-Down'; break;
            case	86: $out = 'موسسه ارسال کننده، شاپرک یا مقصد تراکنش در حالت Sign off است.'; break;
            case	90: $out = 'سامانه مقصد تراکنش درحال انجام عملیات پایان روز می باشد.'; break;
            case	91: $out = 'پاسخی از سامانه مقصد دریافت نشد'; break;
            case	92: $out = 'مسیری برای ارسال تراکنش به مقصد یافت نشد. (موسسه های اعلامی معتبر نیستند)'; break;
            case	93: $out = 'پیام دوباره ارسال گردد. (درپیام های تاییدیه)'; break;
            case	94: $out = 'پیام تکراری است'; break;
            case	96: $out = 'بروز خطای سیستمی در انجام تراکنش'; break;
            case	97: $out = 'مبلغ تراکنش غیر معتبر است'; break;
            case	98: $out = 'شارژ وجود ندارد.'; break;
            case	99: $out = 'تراکنش غیر معتبر است یا کلید ها هماهنگ نیستند'; break;
            case	100: $out = 'خطای نامشخص'; break;
            case	500: $out = 'کدپذیرندگی معتبر نمی باشد'; break;
            case	501: $out = 'مبلغ بیشتر از حد مجاز است'; break;
            case	502: $out = 'نام کاربری و یا رمز ورود اشتباه است'; break;
            case	503: $out = 'آی پی دامنه کار بر نا معتبر است'; break;
            case	504: $out = 'آدرس صفحه برگشت نا معتبر است'; break;
            case	505: $out = 'ناشناخته'; break;
            case	506: $out = 'شماره سفارش تکراری است -  و یا مشکلی دیگر در درج اطلاعات'; break;
            case	507: $out = 'خطای اعتبارسنجی مقادیر'; break;
            case	508: $out = 'فرمت درخواست ارسالی نا معتبر است'; break;
            case	509: $out = 'قطع سرویس های شاپرک'; break;
            case	510: $out = 'لغو درخواست توسط خود کاربر'; break;
            case	511: $out = 'طولانی شدن زمان تراکنش و عدم انجام در زمان مقرر توسط کاربر'; break;
            case	512: $out = 'خطا اطلاعات Cvv2 کارت'; break;
            case	513: $out = 'خطای اطلاعات تاریخ انقضاء کارت'; break;
            case	514: $out = 'خطا در رایانامه درج شده'; break;
            case	515: $out = 'خطا در کاراکترهای کپچا'; break;
            case	516: $out = 'اطلاعات درخواست نامعتبر میباشد'; break;
            case	517: $out = 'خطا در شماره کارت'; break;
            case	518: $out = 'تراکنش مورد نظر وجود ندارد.'; break;
            case	519: $out = 'مشتری از پرداخت منصرف شده است'; break;
            case	520: $out = 'مشتری در زمان مقرر پرداخت را انجام نداده است'; break;
            case	521: $out = 'قبلا درخواست تائید با موفقیت ثبت شده است'; break;
            case	522: $out = 'قبلا درخواست اصلاح تراکنش با موفقیت ثبت شده است'; break;
            case	600: $out = 'لغو تراکنش'; break;
            case    403:$out = 'سفارش پیدا نشد'; break;
            default: $out ='خطا غیر منتظره رخ داده است';break;
        }

        return $out;
    }

    protected function updateStatus ($status,$notified,$comments='',$id) {
        $modelOrder = VmModel::getModel ('orders');
        $order['order_status'] = $status;
        $order['customer_notified'] = $notified;
        $order['comments'] = $comments;
        $modelOrder->updateStatusForOneOrder ($id, $order, TRUE);
    }

}

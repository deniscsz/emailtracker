<?php
class Xpdev_Emailtracker_Model_Cron{
    
    protected function buildTracker($code) {
        
        $todayDate = Mage::app()->getLocale()->date()->toString('YYYY-MM-dd');
        
        $url = 'http://websro.correios.com.br/sro_bin/txect01$.QueryList?P_LINGUA=001&P_TIPO=001&P_COD_UNI='. $code;
        
        try {
            $client = new Zend_Http_Client();
            $client->setUri($url);
            $content = $client->request();
            $body = $content->getBody();
            //$body = file_get_contents($url);
        } catch (Exception $e) {
            Mage::log('Can\'t connect to correios\'s URL');
            return false;
        }
        
        if (!preg_match('#<table ([^>]+)>(.*?)</table>#is', $body, $matches)) {
            Mage::log('Can\'t format body to correios\'s table');
            return false;
        }
        $table = $matches[2];

        if (!preg_match_all('/<tr>(.*)<\/tr>/i', $table, $columns, PREG_SET_ORDER)) {
            Mage::log('Can\'t format columns to correios\'s table');
            return false;
        }
        
        $progress = array();
        for ($i = 0; $i < count($columns); $i++) {
            $column = $columns[$i][1];
            
            $description = '';
            $found = false;
            if (preg_match('/<td rowspan="?2"?/i', $column) && preg_match('/<td rowspan="?2"?>(.*)<\/td><td>(.*)<\/td><td><font color="[A-Z0-9]{6}">(.*)<\/font><\/td>/i', $column, $matches)) {
                if (preg_match('/<td colspan="?2"?>(.*)<\/td>/i', $columns[$i+1][1], $matchesDescription)) {
                    $description = str_replace('  ', '', $matchesDescription[1]);
                }

                $found = true;
            } elseif (preg_match('/<td rowspan="?1"?>(.*)<\/td><td>(.*)<\/td><td><font color="[A-Z0-9]{6}">(.*)<\/font><\/td>/i', $column, $matches)) {
                $found = true;
            }

            if ($found) {
                $datetime = explode(' ', $matches[1]);
                $locale = new Zend_Locale('pt_BR');
                $date='';
                $date = new Zend_Date($datetime[0], 'dd/MM/YYYY', $locale);

                $track = array(
                            'deliverydate' => $date->toString('YYYY-MM-dd'),
                            'deliverytime' => $datetime[1] . ':00',
                            'deliverylocation' => htmlentities($matches[2]),
                            'status' => htmlentities($matches[3]),
                            'activity' => htmlentities($matches[3])
                            );

                if ($description !== '') {
                    $track['activity'] = $matches[3] . ' - ' . htmlentities($description);
                }
                
                if( $this->diffDate($todayDate,$track['deliverydate'],'D') < 2 && ($i == count($columns) - 1) && Mage::getStoreConfig('emailtracker/options/stop') == '1') {
                    Mage::log('Já recebeu ou tem tempo demais!');
                    return false;
                }
                
                /*if(Mage::getStoreConfig('emailtracker/options/stop') == '1' && ) {
                    if($track['status'] == "Entrega Efetuada") {
                        return false;
                    }
                }*/

                $progress[] = $track;
            }            
        }

        if (!empty($progress)) {
            $track = $progress[0];
            $track['progressdetail'] = $progress;

            return $track;
        } else {
            Mage::log('Progress NULL');
            return false;
        }
    }
    
    protected function diffDate($d1, $d2, $type='', $sep='-') {
        $d1 = explode($sep, $d1);
        $d2 = explode($sep, $d2);
        switch ($type) {
                case 'A':
                    $X = 31536000;
                    break;
                case 'M':
                    $X = 2592000;
                    break;
                case 'D':
                    $X = 86400;
                    break;
                case 'H':
                    $X = 3600;
                    break;
                case 'MI':
                    $X = 60;
                    break;
                default:
                    $X = 1;
        }
        return floor( ( mktime(0, 0, 0, $d2[1], $d2[2], $d2[0]) - mktime(0, 0, 0, $d1[1], $d1[2], $d1[0]) ) / $X );
    }
    
    public function sendMessage($bodyMessage,$cliente_email,$cliente_name,$idOrder,$order) {
        $fromEmail = Mage::getStoreConfig('emailtracker/options/sender');  
        $fromName = Mage::getStoreConfig('emailtracker/options/name'); 
        
        $toEmail = 'deniscsz@gmail.com';//$cliente_email;
        $toName = $cliente_name;
        //Mage::log($bodyMessage);
        $templateConfigPath = Mage::getStoreConfig('emailtracker/options/template');
        Mage::log('Config Template '.$templateConfigPath);
        
        if($templateConfigPath != '') {
            Mage::log('Trans');
            $mailTemplate = Mage::getModel('core/email_template');
            
            try {
                $mailTemplate->setDesignConfig(array('area'=>'frontend', 'store'=>Mage::app()->getStore()->getId()))
                ->sendTransactional(
                        $templateConfigPath,
                        Mage::getStoreConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_IDENTITY,Mage::app()->getStore()->getId()),
                        $toEmail,
                        $toName,
                        array(
                            'order'  => $order,
                            'tableentrega' => $bodyMessage
                        )
                );
            }
            catch(Exception $ex) {
                Mage::log('Email Failed');
                Mage::log($ex->getMessage());
            }
        }
        else {
            Mage::log('No Trans');
            $subject = Mage::getStoreConfig('emailtracker/options/subject') .' '. Mage::getStoreConfig('emailtracker/options/separador') . $idOrder."";
            
            $mail = new Zend_Mail();
            
            $mail->setBodyHtml($bodyMessage);
            
            $mail->setFrom($fromEmail, $fromName);
            
            $mail->addTo($toEmail, $toName);
            
            $mail->setSubject($subject);
            
            //$mail->setHeader();
            
            try {
                $mail->send();
            }
            catch(Exception $ex) {
                Mage::log('Email Failed');
            }
        }
    }
    
    public function VerificaEmailTracker() {
 	  
        $todayDate  = Mage::app()->getLocale()->date()->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);
        $data_quebrada = explode(' ', $todayDate);
                
        $dateTimestamp = (int)Mage::getModel('core/date')->timestamp(strtotime($data_quebrada[0])) - (int)Mage::getStoreConfig('emailtracker/options/intervaldays');
        $dataForFilter = date('d.m.Y', $dateTimestamp);
        
        $orders = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('status', 'complete')
            ->addAttributeToFilter('created_at', array('gteq' => $dataForFilter));
        
        foreach($orders as $order) {
            $cliente_name = $order->getCustomerName();
            $cliente_email = $order->getCustomerEmail();
            $_totalData = $order->getData();
            $price = number_format($_totalData['grand_total'],2);
            
            $trackings = Mage::getResourceModel('sales/order_shipment_track_collection')
            	->setOrderFilter($order)
            	->getData();
            
            for($i=0;$i<count($trackings);$i++) {
                $trackingNumber = $trackings[$i]['track_number'];
                //Mage::log($trackingNumber);
                
                $orderId = $order->getId();
                $bodyMessage = $this->buildTracker($trackingNumber);
                if($bodyMessage != false) {
                    //Mage::log($bodyMessage);
                    if(Mage::getStoreConfig('emailtracker/options/template') != '') {
                        $bodyMessage = $this->buildTableToEmail($bodyMessage,$trackingNumber,$order->getIncrementId(),$cliente_name,$price);
                    }
                    else {
                        $bodyMessage = $this->buildBodyToEmail($bodyMessage,$trackingNumber,$orderId,$cliente_name,$price);
                    }
                    
                    $this->sendMessage($bodyMessage,$cliente_email,$cliente_name,$orderId,$order);
                }
            }
        }
	}
    
    protected function impar($num) {
        if($num % 2) {
            return true;
        } else {
            echo false;
        }
    }
    
    protected function buildBodyToEmail($body,$code,$orderId,$cliente,$preco) {
        $url = 'http://websro.correios.com.br/sro_bin/txect01$.QueryList';
        $url .= '?P_LINGUA=001&P_TIPO=001&P_COD_UNI=' . $code;
        
        $linha1 = "".Mage::getStoreConfig('emailtracker/options/html')."";
        if(strlen($linha1) < 3) {
            $linha1 = "<p>O seu c&oacute;digo de rastreamento do seu pedido (n&uacute;mero $orderId) &eacute;: <a href=\"$url\"><b>$code</b></a></p>";
        }
        $linha1 .= "<br />";
        
        if( strrpos($linha1,"%%")!= false ) {
            $texto = explode("%%", $linha1);
            
            for($i=0;$i<count($texto);$i++) {
                if($this->impar($i)) {
                    switch ($texto[$i]) {
                        case "pedido":
                            $texto[$i] = "$orderId";
                            break;
                        case "preco":
                            $texto[$i] = "R$ ".$preco;
                            break;
                        case "cliente":
                            $texto[$i] = "$cliente";
                            break;
                        case "status":
                            $texto[$i] = $body['progressdetail'][0]['status'];
                            break;
                    }
                }
            }
            $linha1 = "";
            for($i=0;$i<count($texto);$i++) {
                $linha1 .= $texto[$i];
            }
        }
        
        if(sizeof($body['progressdetail']) > 0) {
            $linha2 = "<br />
<table class=\"data-table\" id=\"track-history-table-$code\"  rules=\"all\" style=\"border-color: #".Mage::getStoreConfig('emailtracker/visual/bodercolor').";\" cellpadding=\"5\">
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam1')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam2')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam3')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam4')."\"/>
    <thead>
        <tr style=\"background: #".Mage::getStoreConfig('emailtracker/visual/trcolor').";\">
            <th><strong>Localiza&ccedil;&atilde;o</strong></th>
            <th><strong>Data</strong></th>
            <th><strong>Hora local</strong></th>
            <th><strong>Descri&ccedil;&atilde;o</strong></th>
        </tr>
    </thead>
    <tbody>";

            for($i=0;$i<count($body['progressdetail']);$i++) {
                $linha2 .= "<tr><td style=\"background: #".Mage::getStoreConfig('emailtracker/visual/tdcolor').";\">". $body['progressdetail'][$i]['deliverylocation'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['deliverydate'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['deliverytime'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['activity'] ."</td></tr>";
            }
            
            $linha2 .= "</tbody></table>";
        }
        
        return $linha1.$linha2;
    }
    
    
    protected function buildTableToEmail($body,$code,$orderId,$cliente,$preco) {
        
        if(sizeof($body['progressdetail']) > 0) {
            $url = 'http://websro.correios.com.br/sro_bin/txect01$.QueryList';
            $url .= '?P_LINGUA=001&P_TIPO=001&P_COD_UNI=' . $code;
            
            $linha2 = "<p>O seu c&oacute;digo de rastreamento do seu pedido (n&uacute;mero $orderId) &eacute;: <a href=\"$url\"><b>$code</b></a></p><br/>";
            $linha2 .= "
            <table class=\"data-table\" id=\"track-history-table-$code\"  rules=\"all\" style=\"border-color: #".Mage::getStoreConfig('emailtracker/visual/bodercolor').";\" cellpadding=\"5\">
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam1')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam2')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam3')."\"/>
    <col width=\"".Mage::getStoreConfig('emailtracker/visual/tam4')."\"/>
    <thead>
        <tr style=\"background: #".Mage::getStoreConfig('emailtracker/visual/trcolor').";\">
            <th><strong>Localiza&ccedil;&atilde;o</strong></th>
            <th><strong>Data</strong></th>
            <th><strong>Hora local</strong></th>
            <th><strong>Descri&ccedil;&atilde;o</strong></th>
        </tr>
    </thead>
    <tbody>";
    
            for($i=0;$i<count($body['progressdetail']);$i++) {
                $linha2 .= "<tr><td style=\"background: #".Mage::getStoreConfig('emailtracker/visual/tdcolor').";\">". $body['progressdetail'][$i]['deliverylocation'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['deliverydate'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['deliverytime'] ."</td><td style=\"background: #FFFFFF;\">". $body['progressdetail'][$i]['activity'] ."</td></tr>";
            }
    
            $linha2 .= "</tbody></table>";
        }
    
        return $linha2;
    }
}
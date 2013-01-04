<?php
class Xpdev_Emailtracker_Model_Cron{
    
    protected function buildTracker($code) {
        
        $todayDate = Mage::app()->getLocale()->date()->toString('YYYY-MM-dd');
        
        $url = 'http://websro.correios.com.br/sro_bin/txect01$.QueryList';
        $url .= '?P_LINGUA=001&P_TIPO=001&P_COD_UNI=' . $code;
        try {
            $client = new Zend_Http_Client();
            $client->setUri($url);
            $content = $client->request();
            $body = $content->getBody();
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
                    return false;
                }
                
                //if(Mage::getStoreConfig('emailtracker/options/stop') == '1') {
//                    if($track['status'] == "Entrega Efetuada") {
//                        return false;
//                    }
//                }

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
    
    public function sendMessage($bodyMessage,$cliente_email,$cliente_name,$idOrder) {
        $fromEmail = Mage::getStoreConfig('emailtracker/options/sender');  
        $fromName = Mage::getStoreConfig('emailtracker/options/name'); 
        
        $toEmail = $cliente_email;
        $toName = $cliente_name;
        
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
    
    public function VerificaEmailTracker() {
 	  
        $todayDate  = Mage::app()->getLocale()->date()->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);
        $data_quebrada = explode(' ', $todayDate);
        $data = explode('-', $data_quebrada[0]);
        
        if($data[1] == '2') {
            $data[0] = (string)((int)$data[0] - 1);
            $data[1] == '12';
        }
        else {
            if($data[1] == '1') {
                $data[0] = (string)((int)$data[0] - 1);
                $data[1] == '11';
            }
            else {
                $data[1] = (string)((int)$data[1] - 2);
                if(strlen($data[1]) == 1) {
                    $data[1] = '0'.$data[1];
                }
            }
        }
        $dataForFilter = $data[0].'-'.$data[1].'-'.$data[2];
        
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
                Mage::log($trackingNumber);
                
                $orderId = $order->getId();
                $bodyMessage = $this->buildTracker($trackingNumber);
                if($bodyMessage != false) {
                    Mage::log($bodyMessage);
                    $bodyMessage = $this->buildBodyToEmail($bodyMessage,$trackingNumber,$orderId,$cliente_name,$price);
                    $this->sendMessage($bodyMessage,$cliente_email,$cliente_name,$orderId);
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
            $linha1 = "<p>O seu código de rastreamento do seu pedido (n&uacute;mero $orderId) é: <a href=\"$url\"><b>$code</b></a></p>";
        }
        //$linha1 .= "<br />";
        
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
}
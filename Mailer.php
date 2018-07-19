<?php

require_once 'lib/swift_required.php';

class Mailer
{
   private $mgl = null;
   private $mailjet = null;
   private $mailjet_v3 = null;
   private $mandrill = null;
   private $smpro = null;
   private $prev_client_id;
   private $server;
   private $vmta;
   private $tb_name;
   private $client_email;
   private $apikey;
   private $secret;
   private $mj_apikey_3;
   private $mj_secret_3;

   public function __construct() { }

   public static function newInstance()
   {
       return new self();
   }

   public function send($message, $client_id, $campaign_data)
   {
      // Get client smtp details
      if ($client_id != $this->prev_client_id)
      {
            $query = "SELECT cs.smtp, cs.vmta, c.username, c.email, m1.apikey as apikey_1, m1.secret as secret_1, m3.apikey as apikey_3, m3.secret as secret_3
            FROM clients_smtp cs
            JOIN clients c ON c.id = cs.client_id
            LEFT JOIN mailjet_credentials m1 ON cs.client_id = m1.client_id AND m1.version = 1
            LEFT JOIN mailjet_credentials m3 ON cs.client_id = m3.client_id AND m3.version = 3
            WHERE cs.client_id = '$client_id';";

         $sqlConn = new MySQLConnection();
         $result = $sqlConn->execute($query);
         $this->server = 'mgl';
         $this->client_email = false;

         if (mysql_num_rows($result))
         {
            $this->server = mysql_result($result, 0, "smtp");
            $this->vmta = mysql_result($result, 0, "vmta");
            $this->tb_name = mysql_result($result, 0, "username");
            $this->apikey = mysql_result($result, 0, "apikey_1");
            $this->secret = mysql_result($result, 0, "secret_1");
            $this->mj_apikey_3 = mysql_result($result, 0, "apikey_3");
            $this->mj_secret_3 = mysql_result($result, 0, "secret_3");
            $this->client_email = mysql_result($result, 0, "email");

            // If no api credentials exist for Mailjet, create them
            if ($this->server == 'mailjet')
            {
               if (empty($this->apikey))
               {
                  require_once '/var/www/html/mgl/lib/mailjet/MGLMailjet.class.php';

                  $mj = new MGLMailjet();
                  $api_details = $mj->create_apikey($this->tb_name, $client_id);

                  $this->apikey = $api_details->apikey;
                  $this->secret = $api_details->secretkey;
               }

               $this->_null_smtp($this->server);
            } 
            else if ($this->server == 'mailjet_v3')
            {
               if (empty($this->mj_apikey_3))
               {
                     require_once '/var/www/html/mgl/lib/mailjet/MGLMailjet.v3.class.php';
   
                     $mj = new MGLMailjetV3();
                     $api_details = $mj->create_apikey($this->tb_name, $client_id);
   
                     $this->mj_apikey_3 = $api_details->apikey;
                     $this->mj_secret_3 = $api_details->secretkey;
               }
   
               $this->_null_smtp($this->server);
               }
         }
      }

      $this->prev_client_id = $client_id;

      // Set the appropriate headers for the smtp being used
      switch ($this->server)
      {
         case 'mailjet' :
         case 'mailjet_v3':
            if ($this->tb_name != 'impos' && substr_count($campaign_data['from_email'], '@clients.myguestlist.com.au') == 0)
            {
               $femail = $this->tb_name . '@clients.myguestlist.com.au';
               $message->setFrom(array($femail => $campaign_data['from_name']));
            }

            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Mailjet-Campaign', $this->tb_name . '_' . $campaign_data['message_id']);
            $headers->addTextHeader('X-Mailjet-DeduplicateCampaign', 'y');

            break;
         case 'mandrill' :
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-MC-Tags', 'campaign'); // Temp way of distinguishing between campaign and transactions email
            $headers->addTextHeader('X-MC-Metadata', json_encode(array('mid' => $campaign_data['message_id'], 'pid' => $campaign_data['patron_id'])));

            break;
         case 'smpro' :
            if (substr_count($campaign_data['from_email'], '@clients.myguestlist.com.au') > 0 && !empty($this->client_email))
            {
               $femail = $this->client_email;
               $message->setFrom(array($femail => $campaign_data['from_name']));
            }
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Listid', 'MGL');
            $headers->addTextHeader('X-MGLMsgID', $campaign_data['message_id']);
            $headers->addTextHeader('X-CampaignID', $campaign_data['message_id']);
            $headers->addTextHeader('X-MGLPatronID', $campaign_data['patron_id']);
            $headers->addTextHeader('X-PatronID', $campaign_data['patron_id']);
            $headers->addTextHeader('X-MsgID', $campaign_data['message_id'] . "." . $campaign_data['patron_id']);

            // Remove text part as SMPRO mail relay doesn't support multi-part
            $parts = $message->getChildren();

            foreach ($parts as $part)
            {
               if ($part->getContentType() == 'text/plain')
                  $message->detach($part);
            }

            break;
         case 'mgl' :
         default :
            $headers = $message->getHeaders();
            $headers->addTextHeader('x-job', $campaign_data['message_id']);
            $headers->addTextHeader('X-Mailer', 'MGLMail v1.0');
            $headers->addTextHeader('X-Report-Abuse-To', "fbl@mailer.myguestlist.com.au");

            if (empty($this->vmta))
               $headers->addTextHeader('X-virtual-MTA', 'all'); // Temporary. Will use automated reputation calc to determine shared vmta group.
            else
               $headers->addTextHeader('X-virtual-MTA', $this->vmta); // Used for dedicated IP assignment

            break;
      }

      // Get the smtp and send the message
      $retry = 0;
      $result = false;
      $exception = '';

      while ($retry < 3)
      {
         try
         {
            $smtp = &$this->_get_smtp();
            $result = $smtp->send($message, $failures);
            $retry = 3;
         }
         catch (Exception $e)
         {
            $retry++;
            $result = false;

            // Retry any failed attempts before returning a fail
            if ($retry < 3)
               $this->_null_smtp();
            else
               $exception = $e->getMessage();
         }
      }

      return array(
         'result' => $result,
         'email' => ($result == false ? $failures[0] : ''),
         'exception' => $exception
      );
   }

   private function &_get_smtp()
   {
      switch ($this->server)
      {
         case 'mailjet' :
            if ($this->mailjet == null)
            {
               global $MAILJET_HOST;

               $transport = Swift_SmtpTransport::newInstance($MAILJET_HOST, 25)
                  ->setUsername($this->apikey)
                  ->setPassword($this->secret);
               $this->mailjet = Swift_Mailer::newInstance($transport);
               $this->mailjet->registerPlugin(new Swift_Plugins_AntiFloodPlugin(200, 2));
            }

            return $this->mailjet;

            break;
         case 'mailjet_v3':
            if ($this->mailjet_v3 == null)
            {
               global $MAILJET_HOST;

               $transport = Swift_SmtpTransport::newInstance($MAILJET_HOST, 25)
                  ->setUsername($this->mj_apikey_3)
                  ->setPassword($this->mj_secret_3);
               $this->mailjet_v3 = Swift_Mailer::newInstance($transport);
               $this->mailjet_v3->registerPlugin(new Swift_Plugins_AntiFloodPlugin(200, 2));
            }

            return $this->mailjet_v3;

            break;
         case 'mandrill' :
            if ($this->mandrill == null)
            {
               global $MANDRILL_HOST, $MANDRILL_USER, $MANDRILL_PWORD;

               $transport = Swift_SmtpTransport::newInstance($MANDRILL_HOST, 25)
                  ->setUsername($MANDRILL_USER)
                  ->setPassword($MANDRILL_PWORD);
               $this->mandrill = Swift_Mailer::newInstance($transport);
               $this->mandrill->registerPlugin(new Swift_Plugins_AntiFloodPlugin(200, 2));
            }

            return $this->mandrill;

            break;
         case 'smpro' :
            if ($this->smpro == null)
            {
               global $SMPRO_HOST, $SMPRO_USER, $SMPRO_PWORD;

               $transport = Swift_SmtpTransport::newInstance($SMPRO_HOST, 25)
                  ->setUsername($SMPRO_USER)
                  ->setPassword($SMPRO_PWORD);
               $this->smpro = Swift_Mailer::newInstance($transport);
               $this->smpro->registerPlugin(new Swift_Plugins_AntiFloodPlugin(200, 2));
            }

            return $this->smpro;

            break;
         case 'mgl' :
         default :
            if ($this->mgl == null)
            {
               $transport = Swift_SmtpTransport::newInstance('in.myguestlist.com.au', 25);
               $this->mgl = Swift_Mailer::newInstance($transport);
               $this->mgl->registerPlugin(new Swift_Plugins_AntiFloodPlugin(200, 2));
            }

            return $this->mgl;

            break;
      }
   }

   private function _null_smtp()
   {
      switch ($this->server)
      {
         case 'mailjet' :
            $this->mailjet = null;

            break;
         case 'mailjet_v3' :
            $this->mailjet_v3 = null;

            break;
         case 'mandrill' :
            $this->mandrill = null;

            break;
         case 'smpro' :
            $this->smpro = null;

            break;
         case 'mgl' :
         default:
            $this->mgl = null;

            break;
      }
   }
}

?>

<?php

require_once 'lib/swift_required.php';

class Mailer
{
   private $mgl = null;
   private $mailjet = null;
   private $mandrill = null;
   private $prev_client_id;
   private $server;
   private $vmta;
   private $tb_name;
   private $apikey;
   private $secret;

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
         $query = "SELECT cs.smtp, cs.vmta, c.username, m.apikey, m.secret
               FROM clients_smtp cs
               JOIN clients c ON c.id = cs.client_id
               LEFT JOIN mailjet_credentials m ON cs.client_id = m.client_id
               WHERE cs.client_id = '$client_id';";

         $sqlConn = new MySQLConnection();
         $result = $sqlConn->execute($query);
         $this->server = 'mgl';

         if (mysql_num_rows($result))
         {
            $this->server = mysql_result($result, 0, "smtp");
            $this->vmta = mysql_result($result, 0, "vmta");
            $this->tb_name = mysql_result($result, 0, "username");
            $this->apikey = mysql_result($result, 0, "apikey");
            $this->secret = mysql_result($result, 0, "secret");
//$this->server = 'mandrill';
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
         }
      }

      $this->prev_client_id = $client_id;

      // Set the appropriate headers for the smtp being used
      switch ($this->server)
      {
         case 'mailjet' :
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
            //$femail = $this->tb_name . '@clients.myguestlist.com.au';
            //$message->setFrom(array($femail => $campaign_data['from_name']));

            $headers = $message->getHeaders();
            $headers->addTextHeader('X-MC-Tags', 'campaign'); // Temp way of distinguishing between campaign and transactions email
            $headers->addTextHeader('X-MC-Metadata', json_encode(array('mid' => $campaign_data['message_id'], 'pid' => $campaign_data['patron_id'])));

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
         case 'mandrill' :
            $this->mandrill = null;

            break;
         case 'mgl' :
         default:
            $this->mgl = null;

            break;
      }
   }
}

?>

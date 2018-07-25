<?php
require_once 'lib/swift_required.php';

class MGLMailer
{
   private $lhost = null;
   private $mglold = null;
   private $insermo = null;
   private $smpro = null;
   private $mailjet = null;
   private $mailjet_v3 = null;
   private $logger = null;
   private $prev_client_id;
   private $server;
   private $server_revert;
   private $tb_name;
   private $apikey;
   private $secret;
   private $mj_apikey_3;
   private $mj_secret_3;

   public function __construct()
   {
      global $INSERMO_HOST;
      global $INSERMO_USERNAME;
      global $INSERMO_PASSWORD;

      global $SMPRO_HOST;
      global $SMPRO_USER;
      global $SMPRO_PWORD;

      $transport = Swift_SmtpTransport::newInstance('localhost', 25);
      $this->lhost = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance('cl-t081-210cl.myguestlist.com.au', 25);
      $this->mglold = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance($INSERMO_HOST, 25)
         ->setUsername($INSERMO_USERNAME)
         ->setPassword($INSERMO_PASSWORD);
      $this->insermo = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance($SMPRO_HOST, 25)
         ->setUsername($SMPRO_USER)
         ->setPassword($SMPRO_PWORD);
      $this->smpro = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
         ->setUsername("e3be61b033af97b743e0a782f36d86a5")
         ->setPassword("23bac2ee5cd558623bb08c25fd8fa1ca");
      $this->mailjet = Swift_Mailer::newInstance($transport);

      $this->logger = new Swift_Plugins_Loggers_ArrayLogger(15000);
      $this->lhost->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->mglold->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->insermo->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->smpro->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->mailjet->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
   }

   public static function newInstance()
   {
       return new self();
   }

   public function send($message, $client_id)
   {
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
         $this->server = 'insermo';
         $this->server_revert = '';

         if (mysql_num_rows($result))
         {
            $this->server = mysql_result($result, 0, "smtp");
            $this->tb_name = mysql_result($result, 0, "username");
            $this->apikey = mysql_result($result, 0, "apikey_1");
            $this->secret = mysql_result($result, 0, "secret_1");
            $this->mj_apikey_3 = mysql_result($result, 0, "apikey_3");
            $this->mj_secret_3 = mysql_result($result, 0, "secret_3");

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

               $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
                  ->setUsername($this->apikey)
                  ->setPassword($this->secret);
               $this->mailjet = Swift_Mailer::newInstance($transport);
               $this->mailjet->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
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

               $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
                  ->setUsername($this->mj_apikey_3)
                  ->setPassword($this->mj_secret_3);
               $this->mailjet = Swift_Mailer::newInstance($transport);
               $this->mailjet->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
            }
         }
      }

      $to = $message->getTo();
      list($to_name, $to_email) = array(end($to), key($to)); //mail('alex@myguestlist.com.au', 'MGLMailer', $to_email);

      if (substr_count($to_email, '@bigpond') > 0)
      {
         $this->server_revert = $this->server;
         $this->server = 'mailjet';
      }

      $this->prev_client_id = $client_id;
      $result = false;

      switch ($this->server)
      {
         case 'smpro' :
            try
            {
               $headers = $message->getHeaders();
               $list_id = $headers->get('X-Listid');
               $list_id->setValue('MGL');
               $result = $this->smpro->send($message, $failures); global $mail; $mail->notice($this->logger->dump());
            }
            catch (Exception $e)
            {
               //if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               //{
                  global $SMPRO_HOST;
                  global $SMPRO_USER;
                  global $SMPRO_PWORD;

                  $transport = Swift_SmtpTransport::newInstance($SMPRO_HOST, 25)
                     ->setUsername($SMPRO_USER)
                     ->setPassword($SMPRO_PWORD);
                  $this->smpro = Swift_Mailer::newInstance($transport);
                  $this->smpro->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
               //}
               //else
               //{
                  return array(
                     'result' => false,
                     'email' => '',
                     'exception' => $e->getMessage()
                  );
               //}
            }

            break;

         case 'mailjet' :
         case 'mailjet_v3':
            try
            {
               $from = $message->getFrom();
               //$name = array_pop($from);
               list($name, $email) = array(end($from), key($from));

               if ($this->tb_name != 'impos' && substr_count($email, '@clients.myguestlist.com.au') == 0)
               {
                  $femail = $this->tb_name . '@clients.myguestlist.com.au';
                  $message->setFrom(array($femail => $name));
               }

               $headers = $message->getHeaders();
               $campaign_id = $headers->get('X-CampaignID');
               $headers->addTextHeader('X-Mailjet-Campaign', $this->tb_name . '_' . $campaign_id->getValue());
               $headers->addTextHeader('X-Mailjet-DeduplicateCampaign', 'y');

               $result = $this->mailjet->send($message, $failures);
            }
            catch (Exception $e)
            {
               //if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               //if (stristr($e->getMessage(), 'Expected response code'))
               //{
                  if ($this->server == "mailjet")
                        $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
                        ->setUsername($this->apikey)
                        ->setPassword($this->secret);
                  else {
                        $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
                        ->setUsername($this->mj_apikey_3)
                        ->setPassword($this->mj_apikey_3);
                  }
                  $this->mailjet = Swift_Mailer::newInstance($transport);
                  $this->mailjet->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
               //}
               //else
               //{
                  return array(
                     'result' => false,
                     'email' => '',
                     'exception' => $e->getMessage()
                  );
               //}
            }
            
            if (!empty($this->server_revert))
            {
               $this->server = $this->server_revert;
               $this->server_revert = '';
            }

            break;

         case 'localhost' :
            try
            {
               $result = $this->lhost->send($message, $failures);
            }
            catch (Exception $e)
            {
               if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               {
                  $transport = Swift_SmtpTransport::newInstance('localhost', 25);
                  $this->lhost = Swift_Mailer::newInstance($transport);
                  $this->lhost->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
               }
               else
               {
                  return array(
                     'result' => false,
                     'email' => '',
                     'exception' => $e->getMessage()
                  );
               }
            }

            break;

         case 'mglold' :
            try
            {
               $result = $this->mglold->send($message, $failures);
            }
            catch (Exception $e)
            {
               if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               {
                  $transport = Swift_SmtpTransport::newInstance('cl-t081-210cl.myguestlist.com.au', 25);
                  $this->mglold = Swift_Mailer::newInstance($transport);
                  $this->mglold->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
               }
               else
               {
                  return array(
                     'result' => false,
                     'email' => '',
                     'exception' => $e->getMessage()
                  );
               }
            }

            break;

         case 'insermo' :
         default :
            try
            {
               $result = $this->insermo->send($message, $failures);
            }
            catch (Exception $e)
            {
               //if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               //if (stristr($e->getMessage(), 'Expected response code'))
               //{
                  global $INSERMO_HOST;
                  global $INSERMO_USERNAME;
                  global $INSERMO_PASSWORD;

                  $transport = Swift_SmtpTransport::newInstance($INSERMO_HOST, 25)
                     ->setUsername($INSERMO_USERNAME)
                     ->setPassword($INSERMO_PASSWORD);
                  $this->insermo = Swift_Mailer::newInstance($transport);
                  $this->insermo->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
               //}
               //else
               //{
                  return array(
                     'result' => false,
                     'email' => '',
                     'exception' => $e->getMessage()
                  );
               //}
            }

            break;
      }

      if (!$result)
      {
         return array(
            'result' => false,
            'email' => $failures[0],
            'exception' => ''
         );
      }

      return array(
         'result' => true,
         'email' => '',
         'exception' => ''
      );
   }

   public function log_dump()
   {
      return $this->logger->dump();
   }
}

?>

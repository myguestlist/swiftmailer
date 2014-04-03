<?php
require_once 'lib/swift_required.php';

class MGLMailer
{
   private $lhost = null;
   private $mglold = null;
   private $insermo = null;
   private $smtpcom = null;
   private $mailjet = null;
   private $logger = null;
   private $prev_client_id;
   private $server;
   private $tb_name;
   private $apikey;
   private $secret;

   public function __construct()
   {
      global $INSERMO_HOST;
      global $INSERMO_USERNAME;
      global $INSERMO_PASSWORD;

      global $SMTPCOM_HOST;
      global $SMTPCOM_USER;
      global $SMTPCOM_PWORD;

      $transport = Swift_SmtpTransport::newInstance('localhost', 25);
      $this->lhost = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance('cl-t081-210cl.myguestlist.com.au', 25);
      $this->mglold = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance($INSERMO_HOST, 25)
         ->setUsername($INSERMO_USERNAME)
         ->setPassword($INSERMO_PASSWORD);
      $this->insermo = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance($SMTPCOM_HOST, 25)
         ->setUsername($SMTPCOM_USER)
         ->setPassword($SMTPCOM_PWORD);
      $this->smtpcom = Swift_Mailer::newInstance($transport);

      $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
         ->setUsername("e3be61b033af97b743e0a782f36d86a5")
         ->setPassword("23bac2ee5cd558623bb08c25fd8fa1ca");
      $this->mailjet = Swift_Mailer::newInstance($transport);

      $this->logger = new Swift_Plugins_Loggers_ArrayLogger(15000);
      $this->lhost->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->mglold->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->insermo->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
      $this->smtpcom->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
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
         $query = "SELECT cs.smtp, c.username, m.apikey, m.secret
               FROM clients_smtp cs
               JOIN clients c ON c.id = cs.client_id
               LEFT JOIN mailjet_credentials m ON cs.client_id = m.client_id
               WHERE cs.client_id = '$client_id';";

         $sqlConn = new MySQLConnection();
         $result = $sqlConn->execute($query);
         $this->server = 'insermo';

         if (mysql_num_rows($result))
         {
            $this->server = mysql_result($result, 0, "smtp");
            $this->tb_name = mysql_result($result, 0, "username");
            $this->apikey = mysql_result($result, 0, "apikey");
            $this->secret = mysql_result($result, 0, "secret");

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
         }
      }

      $this->prev_client_id = $client_id;
      $result = false;

      switch ($this->server)
      {
         case 'smtpcom' :
            try
            {
               $result = $this->smtpcom->send($message, $failures);
            }
            catch (Exception $e)
            {
               if (stristr($e->getMessage(), 'Expected response code 250 but got code "", with message ""'))
               {
                  global $SMTPCOM_HOST;
                  global $SMTPCOM_USERNAME;
                  global $SMTPCOM_PASSWORD;

                  $transport = Swift_SmtpTransport::newInstance($SMTPCOM_HOST, 25)
                     ->setUsername($SMTPCOM_USERNAME)
                     ->setPassword($SMTPCOM_PASSWORD);
                  $this->smtpcom = Swift_Mailer::newInstance($transport);
                  $this->smtpcom->registerPlugin(new Swift_Plugins_LoggerPlugin($this->logger));
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

         case 'mailjet' :
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
                  $transport = Swift_SmtpTransport::newInstance("in.mailjet.com", 25)
                     ->setUsername($this->apikey)
                     ->setPassword($this->secret);
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

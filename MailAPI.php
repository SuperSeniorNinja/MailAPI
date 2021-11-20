<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Mail {

  protected $CI;

  public function __construct() {
    $this->CI =& get_instance();
  }

  // $msg should contain the following keys:
  // from, to, subject, text, html
  private function send_message(array $msg, $attachments = null, $inline = null) {

    // Determine the domain to use
    $domain = $this->CI->config->item('mailgun_api_domain');
    $api_url = $this->CI->config->item('mailgun_api_uri') . $domain . '/messages';
    $api_key = $this->CI->config->item('mailgun_api_key');

    // Set up default sender
    if ( !isset($msg['from']) ) {
      $msg['from'] = $this->CI->config->item('mailgun_default_sender');
    }

    // Set up default reply-to
    if ( !isset($msg['h:Reply-To']) ) {
      $msg['h:Reply-To'] = $this->CI->config->item('mailgun_default_reply_to');
    }

    if ( is_array($msg['to']) ) {
      $msg['to'] = implode(',', $msg['to']);
    }

    $attachments = (array) $attachments;
    if ($attachments) {
      $i = 0;
      foreach($attachments as $file) {
        if(substr($file, 0, 1) == '@') {
          $msg['attachment[' . $i++ . ']'] = curl_file_create($file);
        }
        else {
          $msg['attachment[' . $i++ . ']'] = curl_file_create($file);
        }
      }
    }

    $inline = (array) $inline;
    if ($inline) {
      $i = 0;
      foreach($inline as $file) {
        if(substr($file, 0, 1) == '@') {
          $msg['inline[' . $i++ . ']'] = $file;
        }
        else {
          $msg['inline[' . $i++ . ']'] = '@' . $file;
        }
      }
    }

    // POST to Mailgun
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $api_url,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $msg,
      CURLOPT_USERPWD => 'api:' . $api_key,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  } // end send_message()

  private function load_template($file, array $replacements) {
    return str_replace(array_keys($replacements), $replacements, file_get_contents($file));
  }

  public function send_template($template, array $cfg, array $replacements) {
    $generic_replacements = [
      '%%current_year%%' => date('Y'),
      '%%title%%' => isset($cfg['title']) ? $cfg['title'] : $cfg['subject'],
      '%%message%%' => '',
      '%%base_url%%' => base_url(),
    ];

    if (isset( $cfg['bypass_generic_template'] ) ) {
        $html = self::load_template('application/views/emails/html/' . $template . '.html', $replacements);
        // $text = self::load_template('application/views/emails/text/' . $template . '.txt', $replacements);
    }
    else {
      $generic_replacements['%%message%%'] = self::load_template('application/views/emails/html/' . $template . '.html', $replacements);
      $html = self::load_template('application/views/emails/html/generic_template_inline.html', $generic_replacements);

      // $generic_replacements['%%message%%'] = self::load_template('application/views/emails/text/' . $template . '.txt', $replacements);
      // $text = self::load_template('application/views/emails/text/generic_template.txt', $generic_replacements);
    }

    $text = '';

    $fields = [
      'to' => $cfg['to'],
      'subject' => $cfg['subject'],
      'text' => $text,
      'html' => $html,
    ];

    if ( isset($cfg['from']) ) {
      $fields['from'] = $cfg['from'];
    }

    if ( isset($cfg['cc']) ) {
      $fields['cc'] = $cfg['cc'];
    }

    if ( isset($cfg['bcc']) ) {
      $fields['bcc'] = $cfg['bcc'];
    }

    if ( isset($cfg['reply-to']) ) {
      $fields['h:Reply-To'] = $cfg['reply-to'];
    }

    if ( isset($cfg['attachments']) ) {
      $attachments = $cfg['attachments'];
    }else{
      $attachments = null;
    }

    // error_log('Mailgun: template ' . $template . ' sent.');

    $response = self::send_message($fields, $attachments);
    $response = json_decode($response);

    // Add log entry to email_log for the primary recipient
    $emailData = [
        'subject' => $template,
        'recipient_email' => $cfg['to'],
        'sent_via' => 'mailgun',
        'response_id' => isset($response->id) ? $response->id: 0,
        'response_message' => isset($response->message) ? $response->message: "",
        'created_at' => date("Y-m-d H:i:s")
    ];
    $this->CI->db->insert('email_log', $emailData);

    // Add log entry to email_log for any ccs
    if ( isset($cfg['cc']) ) {
      $emailDataCC = [
          'subject' => $template,
          'recipient_email' => $cfg['cc'],
          'sent_via' => 'mailgun',
          'response_id' => isset($response->id) ? $response->id: 0,
          'response_message' => isset($response->message) ? $response->message: "",
          'created_at' => date("Y-m-d H:i:s")
      ];
      $this->CI->db->insert('email_log', $emailDataCC);
    }

    // Add log entry to email_log for any bccs
    if ( isset($cfg['bcc']) ) {
      $emailDataBCC = [
          'subject' => $template,
          'recipient_email' => $cfg['bcc'],
          'sent_via' => 'mailgun',
          'response_id' => isset($response->id) ? $response->id: 0,
          'response_message' => isset($response->message) ? $response->message: "",
          'created_at' => date("Y-m-d H:i:s")
      ];
      $this->CI->db->insert('email_log', $emailDataBCC);
    }

    return $response;
  } // end send_template()

  public function send_generic_email($recipient, $subject, $body, $from = null, $attachments = null, $inline = null, $cc = null, $plaintext = false, $replyTo = null, $bcc = null) {

    $generic_replacements = [
      '%%current_year%%' => date('Y'),
      '%%title%%' => '',
      '%%message%%' => '',
      '%%base_url%%' => base_url(),
    ];

    $msg = [
      'to' => $recipient,
      'subject' => $subject,
    ];

    if ($plaintext) {
      $text = self::load_template('application/views/emails/text/plaintext_template.txt', $generic_replacements);
      $msg['text'] = $text;
    }
    else {
      $html = self::load_template('application/views/emails/html/generic_template.html', $generic_replacements);
      $text = self::load_template('application/views/emails/text/generic_template.txt', $generic_replacements);
      $msg['html'] = $html;
      $msg['text'] = $text;
    }

    if ($from !== null) {
      $msg['from'] = $from;
    }

    if ($cc !== null) {
      $msg['cc'] = $cc;
    }

    if ($bcc !== null) {
      $msg['bcc'] = $bcc;
    }

    if ($replyTo !== null) {
      $msg['h:Reply-To'] = $replyTo;
    }

    $response = self::send_message($msg, $attachments, $inline);
    $response = json_decode($response);

    // Add log entry to email_log for the primary recipient
    $emailData = [
        'subject' => $subject,
        'recipient_email' => $recipient,
        'sent_via' => 'mailgun',
        'response_id' => isset($response->id) ? $response->id: 0,
        'response_message' => isset($response->message) ? $response->message: "",
        'created_at' => date("Y-m-d H:i:s")
    ];
    $this->CI->db->insert('email_log', $emailData);

    // Add log entry to email_log for any ccs
    if ($cc !== null) {
      $emailDataCC = [
          'subject' => $subject,
          'recipient_email' => $cc,
          'sent_via' => 'mailgun',
          'response_id' => isset($response->id) ? $response->id: 0,
          'response_message' => isset($response->message) ? $response->message: "",
          'created_at' => date("Y-m-d H:i:s")
      ];
      $this->CI->db->insert('email_log', $emailDataCC);
    }

    // Add log entry to email_log for any bccs
    if ($bcc !== null) {
      $emailDataBCC = [
          'subject' => $subject,
          'recipient_email' => $bcc,
          'sent_via' => 'mailgun',
          'response_id' => isset($response->id) ? $response->id: 0,
          'response_message' => isset($response->message) ? $response->message: "",
          'created_at' => date("Y-m-d H:i:s")
      ];
      $this->CI->db->insert('email_log', $emailDataBCC);
    }

    return $response;
  } // end send_generic_email()

}

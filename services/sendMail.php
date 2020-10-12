<?php
//------------------------------------------------------------------------------
// POST service to send mail with the specified 'from', 'fromName', 'to', 'subject'
// and 'body' parameters.
//------------------------------------------------------------------------------

   $from = @$_POST['from'];
   $fromName = isset($_POST['fromName']) ? $_POST['fromName'] : 'Anonymous';
   $to = @$_POST['to'];
   $subject = @$_POST['subject'];
   $body = @$_POST['body'];

   $response = array();

   if ($from && $to && $subject && $body) {
      $headers[] = "MIME-Version: 1.0";
      $headers[] = "Content-type: text/plain; charset=utf-8";
      $headers[] = "From: \"$fromName\" <$from>";
      $headers[] = "X-Mailer: PHP/" . phpversion();

      $success = @mail($to, $subject, $body, implode("\r\n", $headers));
      $response['sent'] = $success;
      if (!$success) {
         $response['error'] = 'Mail error';
      }
   } else {
      $response['sent'] = FALSE;
      $response['error'] = 'Missing parameters';
   }

   return $response;
?>

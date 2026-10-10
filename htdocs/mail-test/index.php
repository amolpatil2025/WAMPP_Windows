<?php
declare(strict_types=1);
/**
 * Quick mail test — sends via PHP mail() to local Mailpit (SMTP 127.0.0.1:1025).
 * Open inbox: http://127.0.0.1:8025/
 */
$sent = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($_POST['to'] ?? 'you@example.test'));
    $subject = trim((string)($_POST['subject'] ?? 'Hello from Web Stack'));
    $body = (string)($_POST['body'] ?? "This is a test message from C:\\web.\n");
    $headers = "From: local@web.test\r\n" .
        "Reply-To: local@web.test\r\n" .
        "X-Mailer: WebStack-MailTest\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($to, $subject, $body, $headers);
    if (!$sent) {
        $error = 'mail() returned false — is Mailpit running on :1025? Check php.ini SMTP settings.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mail test</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 520px; margin: 40px auto; padding: 0 16px; }
    label { display: block; margin: 12px 0 4px; font-size: 0.9rem; }
    input, textarea { width: 100%; padding: 8px; box-sizing: border-box; }
    button { margin-top: 14px; padding: 10px 16px; cursor: pointer; }
    .ok { color: #0a7; } .err { color: #c33; }
    a { color: #06c; }
  </style>
</head>
<body>
  <h1>Mail test</h1>
  <p>Sends through PHP <code>mail()</code> → Mailpit SMTP <code>127.0.0.1:1025</code>.</p>
  <p><a href="http://127.0.0.1:8025/" target="_blank" rel="noopener">Open Mailpit inbox</a>
     · <a href="/panel/">Stack panel</a></p>
  <?php if ($sent === true): ?>
    <p class="ok">Sent. Check the <a href="http://127.0.0.1:8025/" target="_blank" rel="noopener">inbox</a>.</p>
  <?php elseif ($sent === false): ?>
    <p class="err"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
  <form method="post">
    <label>To <input name="to" value="you@example.test" required></label>
    <label>Subject <input name="subject" value="Hello from Web Stack" required></label>
    <label>Body <textarea name="body" rows="5">This is a test message from the local web stack.</textarea></label>
    <button type="submit">Send test mail</button>
  </form>
</body>
</html>

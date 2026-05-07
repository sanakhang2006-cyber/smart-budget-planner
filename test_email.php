<?php
require_once 'includes/config.php';

echo dirname(__DIR__ . '/includes/') . '/phpmailer/src/PHPMailer.php';
echo "<br>";

$result = sendEmail('sanakhang2006@gmail.com', 'Budget Alert Test', 'Test!');
echo $result ? "✅ Gayi!" : "❌ Nahi gayi!";
?>
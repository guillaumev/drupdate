<?php

if (!empty($_GET) && $_GET['owner'] && $_GET['repository'] && $_GET['branch']) {
  $output = [];
  // Build command
  $command = 'php /var/www/drupdate/lib/drupdate.php -o '.escapeshellarg($_GET['owner']).' -r '.escapeshellarg($_GET['repository']).' -b '.escapeshellarg($_GET['branch']);
  exec($command, $output);
  echo join("\n", $output);
}

<?php

if (!empty($_GET) && $_GET['owner'] && $_GET['repository'] && $_GET['branch']) {
  $output = [];
  // Build command
  $command = 'php /var/www/drupdate/lib/drupdate.php -o '.escapeshellarg($_GET['owner']).' -r '.escapeshellarg($_GET['repository']).' -b '.escapeshellarg($_GET['branch']);
  if ($_GET['security']) {
    $command .= ' -s';
  }
  if ($_GET['directory']) {
    $command .= ' -d ' . escapeshellarg($_GET['directory']);
  }
  exec($command, $output);
  echo join("\n", $output);
}

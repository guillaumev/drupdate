<?php

/**
 * @file hook.php
 * Responds to a merge on a specific branch by:
 *  - pulling the branch
 *  - backing up each site's database
 *  - running drush updb on each drupal site
 */

$branch = 'master';
$platform_path = '/var/aegir/platforms/gvj-7.x-1.x-prod';
$address = 'guillaume@viguierjust.com';
$subject = "GVJ Platform update";

$full_output = '';
$headers = getallheaders();
$event = $headers['X-GitHub-Event'];
if ($event == 'push') {
  $request_body = file_get_contents('php://input');
  $decoded = json_decode($request_body);
  if ($decoded->ref == 'refs/heads/'.$branch) {
    // Step 1: pull master branch
    $cmd = "sudo -u aegir -H /usr/bin/pullhere $platform_path";
    $full_output .= "PULLING LATEST CODE...\n";
    exec($cmd, $output, $return);
    $full_output .= implode("\n", $output);
    unset($output);
    if ($return == 0) {
      // Step 2: backup each site's database
      $sites = scandir($platform_path.'/sites');
      foreach ($sites as $i => $site) {
        if (!is_dir($platform_path.'/sites/'.$site) || in_array($site, array('.', '..', 'all', 'default'))) {
          unset($sites[$i]);
        }
      }
      foreach ($sites as $site) {
        $cmd = 'cd '.$platform_path.'/sites/'.$site.'; sudo -u aegir -H drush -y sql-dump --result-file=../../backups/'.$site.'.sql';
        $full_output .= "BACKING UP $site\n";
        exec($cmd, $output, $return);
        $full_output .= implode("\n", $output);
        unset($output);
        if ($return == 0) {
          // Step 3: run drush updb on each drupal site
          $cmd = 'cd '.$platform_path.'/sites/'.$site.'; sudo -u aegir -H drush -y updb';
          $full_output .= "UPDATING DB FOR $site\n";
          exec($cmd, $output, $return);
          $full_output .= implode("\n", $output);
          unset($output);
        }
      }
    }
  }
  if (!empty($full_output)) {
    mail($address, $subject, $full_output);
  }
}


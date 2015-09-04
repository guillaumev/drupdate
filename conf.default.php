<?php

/**
 * Controls the configuration of drupdate
 * Copy this file to conf.php and enter your configuration details
 */

$conf['username'] = 'github-username'; // Your github username
$conf['password'] = 'github-password'; // Your github password
$conf['owner'] = 'owner'; // The github owner of the repository where your Drupal code is located (the repository URL on github is https://github.com/owner/repo)
$conf['repo'] = 'repo'; // The name of the repository where your Drupal code is located (the repository URL on github is https://github.com/owner/repo)
$conf['branch'] = 'master'; // The branch that needs to be cloned
$conf['options'] = array(
  'ignore' => array('bootstrap'), // List of modules that should be ignored in the update
  'merge' => false, // If this is set to true, Drupdate will create an "update-current_date" branch on your repository with the module updates and will attempt to merge it back into the main branch. If this is set to false, the branch will not be merged automatically, and you will need to merge it manually (but it will allow you to check that the updates are correct before merging back into your main branch)
);


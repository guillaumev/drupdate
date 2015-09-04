<?php

require_once('./conf.php');
require_once('./lib.php');
require_once(__DIR__ . '/github-php-client/client/GitHubClient.php');

$client = new GitHubClient();
$client->setCredentials($conf['username'], $conf['password']);

$url = 'git@github.com:guillaumev/gvj.git';
$branch = 'master';

/**
 * Forks a repository
 *
 * @param $owner
 *   The owner of the repository to be forked
 * @param $repo
 *   The repository to be forked
 */
function drupdate_fork($owner, $repo) {
  global $client;
  return $client->request("/repos/$owner/$repo/forks", 'POST', $data, 202, 'GitHubRepo');
}

/**
 * Clone a repository locally and sync it if it's a fork
 *
 * @param $owner
 *  The owner of the github repository
 * @param $repo
 *  The repository to be cloned
 * @param $branch
 *  The branch to be cloned
 */
function _drupdate_clone($owner, $repo, $branch) {
  global $client;
  $cmd = 'git clone git@github.com:'.$owner.'/'.$repo.' repository';
  exec($cmd, $output, $return);
  if ($return == 0) {
    // Find out if it's a fork
    $repo_object = $client->repos->get($owner, $repo);
    if ($repo_object->getFork()) {
      // This is a fork, it needs to be synchronized
      // Get parent repository clone url
      $parent = $repo_object->getParent();
      $cmd = 'cd repository;git remote add upstream '.$parent->getCloneUrl().';git fetch upstream; git checkout '.$branch.';git merge upstream/'.$branch;
      exec($cmd, $output, $return);
    }
  }
  return $return;
}


/**
 * Updates the Drupal modules of a repository
 *
 * @param $owner
 *   The owner of the github repository
 * @param $repo
 *   The repository to work on
 * @param $options
 *   Extra options:
 *    - merge: whether to merge back in the main branch after pushing
 *    - ignore: array of modules or themes that should be ignored in the update
 */
function drupdate($owner, $repo, $branch, $options = array()) {
  $to_update = array();
  // Step 1: clone repository
  $return = _drupdate_clone($owner, $repo, $branch);
  if ($return == 0) {
    // Step 2: get list of modules with their version
    $dir_iterator = new RecursiveDirectoryIterator("./repository");
    $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
      if ($file->getExtension() == 'info') {
        $data = file_get_contents($file->getRealPath());
        $info = drupal_parse_info_format($data);
        if (isset($info['project']) && ($info['project'] == $file->getBasename('.info') || $info['project'] == 'drupal') && isset($info['version']) && strpos($info['version'], 'dev') === FALSE) {
          $projects[$info['project']]['info'] = $info;
        }
      }
    }

    // Step 3: get list of modules that need to be updated
    update_process_project_info($projects);

    foreach ($projects as $name => $project) {
      // See if there is an update available
      $update_fetch_url = isset($project['info']['project status url']) ? $project['info']['project status url'] : UPDATE_DEFAULT_URL;
      $update_fetch_url .= '/'.$name.'/7.x';
      $xml = file_get_contents($update_fetch_url);
      if ($xml) {
        $available = update_parse_xml($xml);
        update_calculate_project_update_status(NULL, $project, $available);
        if (isset($project['existing_version']) && isset($project['recommended']) && $project['existing_version'] != $project['recommended'] && (!isset($options['ignore']) || (isset($options['ignore']) && !in_array($name, $options['ignore'])))) {
          $to_update[] = $name;
        }
      }
    }

    // Step 4: download updated modules with drush
    if (!empty($to_update)) {
      // TODO: handle patches
      // Handle drupal core specifically
      if (in_array('drupal', $to_update)) {
        $dkey = array_search('drupal', $to_update);
        if ($dkey !== FALSE) {
          unset($to_update[$dkey]);
          // Update drupal core
          // Download in another folder
          $cmd = "drush dl drupal -y --destination=core --drupal-project-rename";
          exec($cmd, $output, $return);
          if ($return == 0) {
            $cmd = "cp -R core/drupal/* repository";
            exec($cmd, $output, $return);
          }
        }
      }
      if (!empty($to_update)) {
        $modules = implode(' ', $to_update);
        $cmd = "cd repository; drush -y dl $modules";
        exec($cmd, $output, $return);
        if ($return == 0) {
          _drupdate_commit($owner, $repo, $branch, $modules, $options);
        }
      }
      else {
        // We are just update drupal core
        $modules = 'drupal';
        _drupdate_commit($owner, $repo, $branch, $modules, $options);
      }
    }
        
    
  }
}

function _drupdate_commit($owner, $repo, $branch, $modules, $options) {
  global $client;
  // Step 5: commit the changes in an update branch
  $date = date('Y-m-d');
  $cmd = 'cd repository; git checkout -b update-' . $date . '; git add --all .; git commit -am "Updated ' . $modules.'"';
  exec($cmd, $output, $return);
  if ($return == 0) {
    // push the updated modules to the branch
    $cmd = 'cd repository; git push origin update-' . $date;
    exec($cmd, $output, $return);
    // create the pull request
    $pr_data = array();
    $pr_data['owner'] = $owner;
    $pr_data['repo'] = $repo;
    $pr_data['title'] = 'Drupal module updates '.$date;
    $pr_data['head'] = 'update-'.$date;
    // Find out if it's a fork
    $repo_object = $client->repos->get($owner, $repo);
    if ($repo_object->getFork()) {
      $parent = $repo_object->getParent();
      $pr_data['owner'] = $parent->getOwner()->getLogin();
      $pr_data['head'] = $owner.':update-'.$date;
    }
    $pr_data['base'] = $branch;
    $pr_data['body'] = 'Updated '.$modules;
    $client->pulls->createPullRequest($pr_data['owner'], $pr_data['repo'], $pr_data['title'], $pr_data['head'], $pr_data['base'], $pr_data['body']);
    if ($return == 0 && isset($options['merge']) && $options['merge'] == true) {
      // Merge the update branch back into the main one
      $cmd = 'git checkout '.$branch.'; git merge update-' . $date.'; git push origin '.$branch;
      exec($cmd, $output, $return);
    }
  }
}

$options = array('ignore' => array('bootstrap'), 'merge' => false);

drupdate('guillaumev', 'gvj', 'master', $options);

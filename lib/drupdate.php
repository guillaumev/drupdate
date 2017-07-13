<?php

require_once(__DIR__ . '/conf.php');
require_once(__DIR__ . '/vendor/autoload.php');

// Make sure we are not running within a drupal environment
if (!defined('DRUPAL_ROOT')) {
  require_once(__DIR__ . '/lib.php');
}

define('REPOSITORY_DIR', 'repository');
define('CORE_DIR', 'core');

/**
 * Recursively deletes a directory
 * Taken from http://php.net/manual/en/function.rmdir.php
 */
function rrmdir($dir) {
  if (is_dir($dir)) {
    $objects = scandir($dir);
    foreach ($objects as $object) {
      if ($object != "." && $object != "..") {
        if (filetype($dir."/".$object) == "dir") rrmdir($dir."/".$object); else unlink($dir."/".$object);
      }
    }
    reset($objects);
    rmdir($dir);
  }
}


function _drupdate_github() {
  static $client;
  if (!isset($client)) {
    $client = new GitHubClient();
    $client->setCredentials(DRUPDATE_GITHUB_USER, DRUPDATE_GITHUB_PASSWORD);
  }
  return $client;
}

/**
 * Forks a repository
 *
 * @param $owner
 *   The owner of the repository to be forked
 * @param $repo
 *   The repository to be forked
 */
function drupdate_fork($owner, $repo) {
  $client = _drupdate_github();
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
  $client = _drupdate_github();
  $cmd = 'git clone https://'.DRUPDATE_GITHUB_USER.':'.DRUPDATE_GITHUB_PASSWORD.'@github.com/'.$owner.'/'.$repo.' '.REPOSITORY_DIR;
  exec($cmd, $output, $return);
  if ($return == 0) {
    // Find out if it's a fork
    $repo_object = $client->repos->get($owner, $repo);
    if ($repo_object->getFork()) {
      // This is a fork, it needs to be synchronized
      // Get parent repository clone url
      $parent = $repo_object->getParent();
      $cmd = 'cd repository;git remote add upstream '.$parent->getCloneUrl().';git fetch upstream; git checkout '.$branch.';git merge upstream/'.$branch;
      echo $cmd;
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
  $recommended_versions = array();
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
        $recommended = $project['recommended'];
        if ($options['security']) {
          if (count($project['security updates'])) {
            $shifted = array_shift($project['security updates']);
            $recommended = $shifted['version'];
          }
        }
        if (isset($project['existing_version']) &&
          !empty($recommended) &&
          $project['existing_version'] != $recommended &&
          (!isset($options['ignore']) || (isset($options['ignore']) && !in_array($name, $options['ignore'])))) {
            $to_update[] = $name;
            $recommended_versions[$name] = $recommended;
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
          $cmd = 'drush dl drupal-' . $recommended_versions['drupal'] . ' -y --destination='.CORE_DIR.' --drupal-project-rename';
          exec($cmd, $output, $return);
          if ($return == 0) {
            $cmd = 'cp -R '.CORE_DIR.'/drupal/* '.REPOSITORY_DIR;
            exec($cmd, $output, $return);
          }
        }
      }
      if (!empty($to_update)) {
        foreach ($to_update as &$name) {
          $name = $name . '-' . $recommended_versions[$name];
        }
        $modules = implode(' ', $to_update);
        $cmd = 'cd '.REPOSITORY_DIR.'; drush -y dl '.$modules;
        exec($cmd, $output, $return);
        if ($return == 0) {
          _drupdate_commit($owner, $repo, $branch, $modules, $options);
        }
      }
      else {
        // We are just updating drupal core
        $modules = 'drupal-' . $recommended_versions['drupal'];
        _drupdate_commit($owner, $repo, $branch, $modules, $options);
      }
    }
  }

  // Step 5: clean up
  if (is_dir(REPOSITORY_DIR)) {
    rrmdir(REPOSITORY_DIR);
  }
  if (is_dir(CORE_DIR)) {
    rrmdir(CORE_DIR);
  }
}

function _drupdate_commit($owner, $repo, $branch, $modules, $options) {
  $client = _drupdate_github();
  // Step 5: commit the changes in an update branch
  $date = date('Y-m-d');
  $cmd = 'cd '.REPOSITORY_DIR.'; git checkout -b update-' . $date . '; git add --all .; git commit -am "Updated ' . $modules.'"';
  exec($cmd, $output, $return);
  if ($return == 0) {
    // push the updated modules to the branch
    $cmd = 'cd '.REPOSITORY_DIR.'; git push origin update-' . $date;
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
    $pr_data['body'] = "Updated the following: \n\n";
    $arr_modules = explode(' ', $modules);
    foreach ($arr_modules as $module) {
      $pos = strpos($module, '-');
      $module_name = substr($module, 0, $pos);
      $module_version = substr($module, $pos);
      $pr_data['body'] .= " * [" . $arr_module[0] . "](https://www.drupal.org/project/" . $module_name . "/releases/" . $module_version . ")\n";
    }
    $client->pulls->createPullRequest($pr_data['owner'], $pr_data['repo'], $pr_data['title'], $pr_data['head'], $pr_data['base'], $pr_data['body']);
    if ($return == 0 && isset($options['merge']) && $options['merge'] == true) {
      // Merge the update branch back into the main one
      $cmd = 'git checkout '.$branch.'; git merge update-' . $date.'; git push origin '.$branch;
      exec($cmd, $output, $return);
    }
  }
}

function drupdate_usage() {
  echo "Usage: drupdate -o owner -r repository -b branch -i modules_to_ignore -m -s\n";
  echo "-o: github owner\n";
  echo "-r: github repository name\n";
  echo "-b: repository branch to use\n";
  echo "-i: list of modules to ignore\n";
  echo "-m: try to merge pull request automatically\n";
  echo "-s: security updates only\n";
}

if (!defined('DRUPAL_ROOT')) {
  // Build conf
  $short_opts = 'o:r:b:i:m:s';
  $long_opts = array(
    'owner:',
    'repository:',
    'branch:',
    'ignore:',
    'merge',
    'security'
  );
  $options = getopt($short_opts, $long_opts);

  if ((!isset($options['o']) && !isset($options['owner'])) ||
    (!isset($options['r']) && !isset($options['repository'])) ||
    (!isset($options['b']) && !isset($options['branch']) )) {
    drupdate_usage();
  }
  else {
    $owner = $options['o'] ? $options['o'] : $options['owner'];
    $repository = $options['r'] ? $options['r'] : $options['repository'];
    $branch = $options['b'] ? $options['b'] : $options['branch'];
    $doptions = array();
    if (isset($options['i'])) {
      $doptions['ignore'] = explode(',', $options['i']);
    }
    if (isset($options['ignore'])) {
      $doptions['ignore'] = explode(',', $options['ignore']);
    }
    $doptions['merge'] = false;
    if (isset($options['m']) || isset($options['merge'])) {
      $doptions['merge'] = true;
    }
    $doptions['security'] = false;
    if (isset($options['s']) || isset($options['security'])) {
      $doptions['security'] = true;
    }
    drupdate($owner, $repository, $branch, $doptions);
  }
}

<?php

require_once('./lib.php');

$url = 'https://github.com/humanitarianresponse/site';
$branch = 'dev';

/**
 * Updates the Drupal modules of a repository
 *
 * @param $url
 *   The URL of the git repository
 * @param $branch
 *   The branch to work on
 * @param $options
 *   Extra options:
 *    - merge: whether to merge back in the main branch after pushing
 *    - ignore: array of modules or themes that should be ignored in the update
 */
function drupdate($url, $branch, $options = array()) {
  $to_update = array();
  // Step 1: clone repository
  $cmd = "git clone --branch=$branch $url repository";
  exec($cmd, $output, $return);
  if ($return == 0) {
    // Step 2: get list of modules with their version
    $dir_iterator = new RecursiveDirectoryIterator("./repository");
    $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
      if ($file->getExtension() == 'info') {
        $data = file_get_contents($file->getRealPath());
        $info = drupal_parse_info_format($data);
        if (isset($info['project']) && $info['project'] == $file->getBasename('.info') && isset($info['version']) && strpos($info['version'], 'dev') === FALSE) {
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
        if (isset($project['existing_version']) && isset($project['recommended']) && $project['existing_version'] != $project['recommended']) {
          $to_update[] = $name;
        }
      }
    }

    // Step 4: download updated modules with drush
    if (!empty($to_update)) {
      $modules = implode(' ', $to_update);
      $cmd = "cd repository; drush -y dl $modules";
      exec($cmd, $output, $return);
      if ($return == 0) {
        // Step 5: commit the changes in an update branch
        $date = date('Y-m-d');
        $cmd = 'cd repository; git checkout -b update-' . $date . '; git add --all .; git commit -am "Updated ' . $modules.'"';
        exec($cmd, $output, $return);
        // TODO: push the updated modules to the branch
        // TODO: merge the update branch back into the main one
      }
    }
        
    
  }
}

drupdate($url, $branch);

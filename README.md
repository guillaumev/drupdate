# Drupdate

Drupdate will allow you to automatically perform module and core updates of your Drupal codebase. If you set it to run periodically, it will automatically create branches in your repository for module updates, ready for review, automating the maintenance tasks.

## Installing

1. Copy conf.default.php in conf.php
2. Edit conf.php and set your configuration
3. Install composer and run `php composer.phar install`

## Running

You can now run drupdate from the command line, using `php drupdate.php`. When you run it, it will clone the repository into a "repository" directory in the current directory, perform the module updates, and send the updates (if there are some) to a branch in your repository called "update-[current_date]". The updates should then be ready to be merged into your main branch.

Optionally, you can set drupdate to run periodically, by editing your crontab, so that you don't miss updates anymore.

<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Executes "svn commit" once a revision has been "Accepted".
 *
 * @group workflow
 */
class ArcanistCommitWorkflow extends ArcanistBaseWorkflow {

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
      **commit** [--revision __revision_id__] [--show]
          Supports: svn
          Commit a revision which has been accepted by a reviewer.
EOTEXT
      );
  }

  public function requiresWorkingCopy() {
    return true;
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function getArguments() {
    return array(
      'show' => array(
        'help' =>
          "Show the command which would be issued, but do not actually ".
          "commit anything."
      ),
      'revision' => array(
        'param' => 'revision_id',
        'help' =>
          "Commit a specific revision. If you do not specify a revision, ".
          "arc will look for committable revisions.",
      )
    );
  }

  public function run() {
    $repository_api = $this->getRepositoryAPI();
    $conduit = $this->getConduit();

    $revision_data = $conduit->callMethodSynchronous(
      'differential.find',
      array(
        'query' => 'committable',
        'guids' => array(
          $this->getUserGUID(),
        ),
      ));

    try {
      $revision_id = $this->getArgument('revision');
      $revision = $this->chooseRevision(
        $revision_data,
        $revision_id,
        'Which revision do you want to commit?');
    } catch (ArcanistChooseInvalidRevisionException $ex) {
      throw new ArcanistUsageException(
        "Revision D{$revision_id} is not committable. You can only commit ".
        "revisions you own which have been 'accepted'.");
    } catch (ArcanistChooseNoRevisionsException $ex) {
      throw new ArcanistUsageException(
        "You have no committable Differential revisions. You can only commit ".
        "revisions you own which have been 'accepted'.");
    }

    $revision_id    = $revision->getID();
    $revision_name  = $revision->getName();

    $message = $conduit->callMethodSynchronous(
      'differential.getcommitmessage',
      array(
        'revision_id' => $revision_id,
      ));

    if ($this->getArgument('show')) {
      echo $message;
      return 0;
    }

    echo "Committing D{$revision_id} '{$revision_name}'...\n";

    $files = $this->getCommitFileList($revision);

    $files = implode(' ', array_map('escapeshellarg', $files));
    $message = escapeshellarg($message);
    $root = escapeshellarg($repository_api->getPath());

    // Specify LANG explicitly so that UTF-8 commit messages don't break
    // subversion.
    $command =
      "(cd {$root} && LANG=en_US.utf8 svn commit {$files} -m {$message})";

    $err = null;
    passthru($command, $err);

    if ($err) {
      throw new Exception("Executing 'svn commit' failed!");
    }

    $working_copy = $this->getWorkingCopy();
    $remote_hooks = $working_copy->getConfig('remote_hooks_installed', false);
    if (!$remote_hooks) {
      echo "According to .arcconfig, remote commit hooks are not installed ".
           "for this project, so the revision will be marked committed now. ".
           "Consult the documentation for instructions on installing hooks.".
           "\n\n";
      $mark_workflow = $this->buildChildWorkflow(
        'mark-committed',
        array($revision_id));
      $mark_workflow->run();
    }

    return $err;
  }

  protected function getCommitFileList(
    ArcanistDifferentialRevisionRef $revision) {
    $repository_api = $this->getRepositoryAPI();

    if (!($repository_api instanceof ArcanistSubversionAPI)) {
      throw new ArcanistUsageException(
        "arc commit is only supported under SVN. Use arc amend under git.");
    }

    $conduit = $this->getConduit();

    $revision_id = $revision->getID();

    $revision_source = $revision->getSourcePath();
    $working_copy = $repository_api->getPath();
    if ($revision_source != $working_copy) {
      $prompt =
        "Revision was generated from '{$revision_source}', but the current ".
        "working copy root is '{$working_copy}'. Commit anyway?";
      if (!phutil_console_confirm($prompt)) {
        throw new ArcanistUserAbortException();
      }
    }

    $commit_paths = $conduit->callMethodSynchronous(
      'differential.getcommitpaths',
      array(
        'revision_id' => $revision_id,
      ));
    $commit_paths = array_fill_keys($commit_paths, true);

    $status = $repository_api->getSVNStatus();

    $modified_but_not_included = array();
    foreach ($status as $path => $mask) {
      if (!empty($commit_paths[$path])) {
        continue;
      }
      foreach ($commit_paths as $will_commit => $ignored) {
        if (Filesystem::isDescendant($path, $will_commit)) {
          throw new ArcanistUsageException(
            "This commit includes the directory '{$will_commit}', but ".
            "it contains a modified path ('{$path}') which is NOT included ".
            "in the commit. Subversion can not handle this operation and ".
            "will commit the path anyway. You need to sort out the working ".
            "copy changes to '{$path}' before you may proceed with the ".
            "commit.");
        }
      }
      $modified_but_not_included[] = $path;
    }

    if ($modified_but_not_included) {
      if (count($modified_but_not_included) == 1) {
        $prefix = "A locally modified path is not included in this revision:";
        $prompt = "It will NOT be committed. Commit this revision anyway?";
      } else {
        $prefix = "Locally modified paths are not included in this revision:";
        $prompt = "They will NOT be committed. Commit this revision anyway?";
      }
      $this->promptFileWarning($prefix, $prompt, $modified_but_not_included);
    }

    $do_not_exist = array();
    foreach ($commit_paths as $path => $ignored) {
      $disk_path = $repository_api->getPath($path);
      if (file_exists($disk_path)) {
        continue;
      }
      if (is_link($disk_path)) {
        continue;
      }
      if (idx($status, $path) & ArcanistRepositoryAPI::FLAG_DELETED) {
        continue;
      }
      $do_not_exist[] = $path;
      unset($commit_paths[$path]);
    }

    if ($do_not_exist) {
      if (count($do_not_exist) == 1) {
        $prefix = "Revision includes changes to a path that does not exist:";
        $prompt = "Commit this revision anyway?";
      } else {
        $prefix = "Revision includes changes to paths that do not exist:";
        $prompt = "Commit this revision anyway?";
      }
      $this->promptFileWarning($prefix, $prompt, $do_not_exist);
    }

    $files = array_keys($commit_paths);

    if (empty($files)) {
      throw new ArcanistUsageException(
        "There is nothing left to commit. None of the modified paths exist.");
    }

    return $files;
  }

  protected function promptFileWarning($prefix, $prompt, array $paths) {
    echo $prefix."\n\n";
    foreach ($paths as $path) {
      echo "    ".$path."\n";
    }
    if (!phutil_console_confirm($prompt)) {
      throw new ArcanistUserAbortException();
    }
  }

  protected function getSupportedRevisionControlSystems() {
    return array('svn');
  }

}

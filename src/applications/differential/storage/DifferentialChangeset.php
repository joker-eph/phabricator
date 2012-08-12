<?php

/*
 * Copyright 2012 Facebook, Inc.
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

final class DifferentialChangeset extends DifferentialDAO {

  protected $diffID;
  protected $oldFile;
  protected $filename;
  protected $awayPaths;
  protected $changeType;
  protected $fileType;
  protected $metadata;
  protected $oldProperties;
  protected $newProperties;
  protected $addLines;
  protected $delLines;

  private $unsavedHunks = array();
  private $hunks;

  const TABLE_CACHE = 'differential_changeset_parse_cache';

  protected function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'metadata'      => self::SERIALIZATION_JSON,
        'oldProperties' => self::SERIALIZATION_JSON,
        'newProperties' => self::SERIALIZATION_JSON,
        'awayPaths'     => self::SERIALIZATION_JSON,
      )) + parent::getConfiguration();
  }

  public function getAffectedLineCount() {
    return $this->getAddLines() + $this->getDelLines();
  }

  public function attachHunks(array $hunks) {
    assert_instances_of($hunks, 'DifferentialHunk');
    $this->hunks = $hunks;
    return $this;
  }

  public function getHunks() {
    if ($this->hunks === null) {
      throw new Exception("Must load and attach hunks first!");
    }
    return $this->hunks;
  }

  public function getDisplayFilename() {
    $name = $this->getFilename();
    if ($this->getFileType() == DifferentialChangeType::FILE_DIRECTORY) {
      $name .= '/';
    }
    return $name;
  }

  public function addUnsavedHunk(DifferentialHunk $hunk) {
    if ($this->hunks === null) {
      $this->hunks = array();
    }
    $this->hunks[] = $hunk;
    $this->unsavedHunks[] = $hunk;
    return $this;
  }

  public function loadHunks() {
    if (!$this->getID()) {
      return array();
    }
    return id(new DifferentialHunk())->loadAllWhere(
      'changesetID = %d',
      $this->getID());
  }

  public function save() {
    $this->openTransaction();
      $ret = parent::save();
      foreach ($this->unsavedHunks as $hunk) {
        $hunk->setChangesetID($this->getID());
        $hunk->save();
      }
    $this->saveTransaction();
    return $ret;
  }

  public function delete() {
    $this->openTransaction();
      foreach ($this->loadHunks() as $hunk) {
        $hunk->delete();
      }
      $this->_hunks = array();

      queryfx(
        $this->establishConnection('w'),
        'DELETE FROM %T WHERE id = %d',
        self::TABLE_CACHE,
        $this->getID());

      $ret = parent::delete();
    $this->saveTransaction();
    return $ret;
  }

  public function getSortKey() {
    $sort_key = $this->getFilename();
    // Sort files with ".h" in them first, so headers (.h, .hpp) come before
    // implementations (.c, .cpp, .cs).
    $sort_key = str_replace('.h', '.!h', $sort_key);
    return $sort_key;
  }

  public function makeNewFile() {
    $file = mpull($this->getHunks(), 'makeNewFile');
    return implode('', $file);
  }

  public function makeOldFile() {
    $file = mpull($this->getHunks(), 'makeOldFile');
    return implode('', $file);
  }

  public function computeOffsets() {
    $offsets = array();
    $n = 1;
    foreach ($this->getHunks() as $hunk) {
      for ($i = 0; $i < $hunk->getNewLen(); $i++) {
        $offsets[$n] = $hunk->getNewOffset() + $i;
        $n++;
      }
    }
    return $offsets;
  }

  public function makeChangesWithContext($num_lines = 3) {
    $with_context = array();
    foreach ($this->getHunks() as $hunk) {
      $context = array();
      $changes = explode("\n", $hunk->getChanges());
      foreach ($changes as $l => $line) {
        if ($line[0] == '+' || $line[0] == '-') {
          $context += array_fill($l - $num_lines, 2 * $num_lines + 1, true);
        }
      }
      $with_context[] = array_intersect_key($changes, $context);
    }
    return array_mergev($with_context);
  }

  public function getAnchorName() {
    return substr(md5($this->getFilename()), 0, 8);
  }

  public function getAbsoluteRepositoryPath(
    PhabricatorRepository $repository = null,
    DifferentialDiff $diff = null) {

    $base = '/';
    if ($diff && $diff->getSourceControlPath()) {
      $base = id(new PhutilURI($diff->getSourceControlPath()))->getPath();
    }

    $path = $this->getFilename();
    $path = rtrim($base, '/').'/'.ltrim($path, '/');

    $svn = PhabricatorRepositoryType::REPOSITORY_TYPE_SVN;
    if ($repository && $repository->getVersionControlSystem() == $svn) {
      $prefix = $repository->getDetail('remote-uri');
      $prefix = id(new PhutilURI($prefix))->getPath();
      if (!strncmp($path, $prefix, strlen($prefix))) {
        $path = substr($path, strlen($prefix));
      }
      $path = '/'.ltrim($path, '/');
    }

    return $path;
  }

  /**
   * Retreive the configured wordwrap width for this changeset.
   */
  public function getWordWrapWidth() {
    $config = PhabricatorEnv::getEnvConfig('differential.wordwrap');
    foreach ($config as $regexp => $width) {
      if (preg_match($regexp, $this->getFilename())) {
        return $width;
      }
    }
    return 80;
  }

  public function getWhitespaceMatters() {
    $config = PhabricatorEnv::getEnvConfig('differential.whitespace-matters');
    foreach ($config as $regexp) {
      if (preg_match($regexp, $this->getFilename())) {
        return true;
      }
    }

    return false;
  }

  // TODO: parse unified diffs and pull out the context logic.
  public function makeUnifiedDiff($inline) {
    $diff = new DifferentialDiff();

    $changes = [ 0 => $this ];
    $diff->attachChangesets($changes);
    // TODO: We could batch this to improve performance.
    //foreach ($diff->getChangesets() as $changeset) {
    //  $changeset->attachHunks($changeset->loadHunks());
    //}
    $diff_dict = $diff->getDiffDict();

    $changes = array();
    foreach ($diff_dict['changes'] as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }
    $bundle = ArcanistBundle::newFromChanges($changes);

    $bundle->setLoadFileDataCallback(array($this, 'loadFileByPHID'));

    return $bundle->toUnifiedDiff();
  }

  public function makeContextDiff($inline, $add_context) {
    $context = array();
    $debug = False;
    if ($debug) {
      $context[] = 'Inline: '.$inline->getIsNewFile().' '.
        $inline->getLineNumber().' '.$inline->getLineLength();
      foreach ($this->getHunks() as $hunk) {
        $context[] = 'hunk: '.$hunk->getOldOffset().'-'.
          $hunk->getOldLen().'; '.$hunk->getNewOffset().'-'.$hunk->getNewLen();
        $context[] = $hunk->getChanges();
      }
    }

    if ($inline->getIsNewFile()) {
      $prefix = '+';
    } else {
      $prefix = '-';
    }
    foreach ($this->getHunks() as $hunk) {
      if ($inline->getIsNewFile()) {
        $offset = $hunk->getNewOffset();
        $length = $hunk->getNewLen();
      } else {
        $offset = $hunk->getOldOffset();
        $length = $hunk->getOldLen();
      }
      $start = $inline->getLineNumber() - $offset;
      $end = $start + $inline->getLineLength();
      if ($start < $length && $end >= 0) {
        //$start = /*max(0,*/ $start-$add_context/*)*/;
        //$end = /*min($length-1,*/ $end+$add_context/*)*/;
        $hunk_content = array();
        $hunk_pos = array( "-" => 0, "+" => 0 );
        $hunk_offset = array( "-" => NULL, "+" => NULL );
        foreach (explode("\n", $hunk->getChanges()) as $line) {
          /*$skip = (strncmp($line, $prefix, 1) != 0 &&
                   strncmp($line, " ", 1) != 0);*/
          if ($hunk_pos[$prefix] <= $end) {
            if ($start <= $hunk_pos[$prefix]) {
            //if (!$skip || ($hunk_pos[$prefix] != $start && $hunk_pos[$prefix] != $end)) {
              if ($hunk_offset["-"] === NULL && (strncmp($line, "-", 1) === 0 || strncmp($line, " ", 1) === 0)) {
                $hunk_offset["-"] = $hunk_pos["-"];
              }
              if ($hunk_offset["+"] === NULL && (strncmp($line, "+", 1) === 0 || strncmp($line, " ", 1) === 0)) {
                $hunk_offset["+"] = $hunk_pos["+"];
              }

              $hunk_content[] = $line;
            //}
            }
            if (strncmp($line, "-", 1) === 0 || strncmp($line, " ", 1) === 0) {
              ++$hunk_pos["-"];
            }
            if (strncmp($line, "+", 1) === 0 || strncmp($line, " ", 1) === 0) {
              ++$hunk_pos["+"];
            }
          }
        }
        $header = "@@";
        if ($hunk_offset["-"] !== NULL) {
          $header .= " -" . ($hunk->getOldOffset() + $hunk_offset["-"]) . "," . ($hunk_pos["-"]-$hunk_offset["-"]);
        }
        if ($hunk_offset["+"] !== NULL) {
          $header .= " +" . ($hunk->getNewOffset() + $hunk_offset["+"]) . "," . ($hunk_pos["+"]-$hunk_offset["+"]);
        }
        $header .= " @@";
        $context[] = $header;
        $context[] = implode("\n", $hunk_content);
      }
    }
    return implode("\n", $context);
  }

}

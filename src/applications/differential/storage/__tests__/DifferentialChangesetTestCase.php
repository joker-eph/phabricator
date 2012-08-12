<?php

final class DifferentialChangeSetTestCase extends PhabricatorTestCase {
  private function createComment() {
    $comment = new DifferentialInlineComment();
    return $comment;
  }
  // $line: 1 based
  // $length: 0 based (0 meaning 1 line)
  private function createNewComment($line, $length) {
    $comment = $this->createComment();
    $comment->setIsNewFile(True);
    $comment->setLineNumber($line);
    $comment->setLineLength($length);
    return $comment;
  }
  // $line: 1 based
  // $length: 0 based (0 meaning 1 line)
  private function createOldComment($line, $length) {
    $comment = $this->createComment();
    $comment->setIsNewFile(False);
    $comment->setLineNumber($line);
    $comment->setLineLength($length);
    return $comment;
  }
  private function createHunk($oldOffset, $oldLen, $newOffset, $newLen, $changes) {
    $hunk = new DifferentialHunk();
    $hunk->setOldOffset($oldOffset);
    $hunk->setOldLen($oldLen);
    $hunk->setNewOffset($newOffset);
    $hunk->setNewLen($newLen);
    $hunk->setChanges($changes);
    return $hunk;
  }
  private function createChange($hunks) {
    $change = new DifferentialChangeset();
    $change->attachHunks($hunks);
    return $change;
  }
  // Returns a change that consists of a single hunk, starting at line 1.
  private function createSingleChange($old_lines, $new_lines, $changes) {
    return $this->createChange(array(
      0 => $this->createHunk(1, $old_lines, 1, $new_lines, $changes),
    ));
  }

  public function testOneLineOldComment() {
    $change = $this->createSingleChange(1, 0, "-a");
    $context = $change->makeContextDiff($this->createOldComment(1, 0), 0); 
    $this->assertEqual("@@ -1,1 @@\n-a", $context);
  }

  public function testOneLineNewComment() {
    $change = $this->createSingleChange(0, 1, "+a");
    $context = $change->makeContextDiff($this->createNewComment(1, 0), 0); 
    $this->assertEqual("@@ +1,1 @@\n+a", $context);
  }

  public function testCannotFindContext() {
    $change = $this->createSingleChange(0, 1, "+a");
    $context = $change->makeContextDiff($this->createNewComment(2, 0), 0); 
    $this->assertEqual("", $context);
  }
  
  public function testOverlapFromStartOfHunk() {
    $change = $this->createChange(array(
      0 => $this->createHunk(23, 2, 42, 2, " 1\n 2"),
    ));
    $context = $change->makeContextDiff($this->createNewComment(41, 1), 0); 
    $this->assertEqual("@@ -23,1 +42,1 @@\n 1", $context);
  }

  public function testMultiLineOldComment() {
    $change = $this->createSingleChange(7, 7,
        " e1\n".
        " e2\n".
        "-o3\n".
        "-o4\n".
        "+n3\n".
        " e5/4\n".
        " e6/5\n".
        "+n6\n".
        " e7\n");
    $context = $change->makeContextDiff($this->createNewComment(2, 4), 0);
    $this->assertEqual(
        "@@ -2,6 +2,5 @@\n".
        " e2\n".
        "-o3\n".
        "-o4\n".
        "+n3\n".
        " e5/4\n".
        " e6/5\n".
        "+n6", $context);
  }
}


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

  public function testOneLineOldComment() {
    $change = $this->createChange(array(
      0 => $this->createHunk(1, 1, 0, 0, "-a"),
    ));
    $comment = $this->createOldComment(1, 0); 

    $this->assertEqual("@@ -1,1 @@\n-a", $change->makeContextDiff($comment));
  }

  public function testOneLineNewComment() {
    $change = $this->createChange(array(
      0 => $this->createHunk(0, 0, 1, 1, "+a"),
    ));
    $comment = $this->createNewComment(1, 0); 

    $this->assertEqual("@@ +1,1 @@\n+a", $change->makeContextDiff($comment));
  }
}


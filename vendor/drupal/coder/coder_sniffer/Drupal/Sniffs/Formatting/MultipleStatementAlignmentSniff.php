<?php
/**
 * Drupal_Sniffs_Formatting_MultipleStatementAlignmentSniff.
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @link     http://pear.php.net/package/PHP_CodeSniffer
 */

/**
 * Checks alignment of multiple assignments.Largely copied from
 * Generic_Sniffs_Formatting_MultipleStatementAlignmentSniff but also allows multiple
 * single space assignments.
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @link     http://pear.php.net/package/PHP_CodeSniffer
 */
class Drupal_Sniffs_Formatting_MultipleStatementAlignmentSniff extends Generic_Sniffs_Formatting_MultipleStatementAlignmentSniff
{


    /**
     * If true, an error will be thrown; otherwise a warning.
     *
     * @var bool
     */
    public $error = true;


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
     * @param int                  $stackPtr  The position of the current token
     *                                        in the stack passed in $tokens.
     *
     * @return int
     */
    public function checkAlignment(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        $assignments = array();
        $prevAssign  = null;
        $lastLine    = $tokens[$stackPtr]['line'];
        $maxPadding  = null;
        $stopped     = null;
        $lastCode    = $stackPtr;
        $lastSemi    = null;

        $find = PHP_CodeSniffer_Tokens::$assignmentTokens;
        unset($find[T_DOUBLE_ARROW]);

        for ($assign = $stackPtr; $assign < $phpcsFile->numTokens; $assign++) {
            if (isset($find[$tokens[$assign]['code']]) === false) {
                // A blank line indicates that the assignment block has ended.
                if (isset(PHP_CodeSniffer_tokens::$emptyTokens[$tokens[$assign]['code']]) === false) {
                    if (($tokens[$assign]['line'] - $tokens[$lastCode]['line']) > 1) {
                        break;
                    }

                    $lastCode = $assign;

                    if ($tokens[$assign]['code'] === T_SEMICOLON) {
                        if ($tokens[$assign]['conditions'] === $tokens[$stackPtr]['conditions']) {
                            if ($lastSemi !== null && $prevAssign !== null && $lastSemi > $prevAssign) {
                                // This statement did not have an assignment operator in it.
                                break;
                            } else {
                                $lastSemi = $assign;
                            }
                        } else {
                            // Statement is in a different context, so the block is over.
                            break;
                        }
                    }
                }//end if

                continue;
            } else if ($assign !== $stackPtr && $tokens[$assign]['line'] === $lastLine) {
                // Skip multiple assignments on the same line. We only need to
                // try and align the first assignment.
                continue;
            }//end if

            if ($assign !== $stackPtr) {
                // Has to be nested inside the same conditions as the first assignment.
                if ($tokens[$assign]['conditions'] !== $tokens[$stackPtr]['conditions']) {
                    break;
                }

                // Make sure it is not assigned inside a condition (eg. IF, FOR).
                if (isset($tokens[$assign]['nested_parenthesis']) === true) {
                    foreach ($tokens[$assign]['nested_parenthesis'] as $start => $end) {
                        if (isset($tokens[$start]['parenthesis_owner']) === true) {
                            break(2);
                        }
                    }
                }
            }//end if

            $var = $phpcsFile->findPrevious(
                PHP_CodeSniffer_Tokens::$emptyTokens,
                ($assign - 1),
                null,
                true
            );

            // Make sure we wouldn't break our max padding length if we
            // aligned with this statement, or they wouldn't break the max
            // padding length if they aligned with us.
            $varEnd    = $tokens[($var + 1)]['column'];
            $assignLen = $tokens[$assign]['length'];
            if ($assign !== $stackPtr) {
                if (($varEnd + 1) > $assignments[$prevAssign]['assign_col']) {
                    $padding      = 1;
                    $assignColumn = ($varEnd + 1);
                } else {
                    $padding = ($assignments[$prevAssign]['assign_col'] - $varEnd + $assignments[$prevAssign]['assign_len'] - $assignLen);
                    if ($padding === 0) {
                        $padding = 1;
                    }

                    if ($padding > $this->maxPadding) {
                        $stopped = $assign;
                        break;
                    }

                    $assignColumn = ($varEnd + $padding);
                }//end if

                if (($assignColumn + $assignLen) > ($assignments[$maxPadding]['assign_col'] + $assignments[$maxPadding]['assign_len'])) {
                    $newPadding = ($varEnd - $assignments[$maxPadding]['var_end'] + $assignLen - $assignments[$maxPadding]['assign_len'] + 1);
                    if ($newPadding > $this->maxPadding) {
                        $stopped = $assign;
                        break;
                    } else {
                        // New alignment settings for previous assignments.
                        foreach ($assignments as $i => $data) {
                            if ($i === $assign) {
                                break;
                            }

                            $newPadding = ($varEnd - $data['var_end'] + $assignLen - $data['assign_len'] + 1);
                            $assignments[$i]['expected']   = $newPadding;
                            $assignments[$i]['assign_col'] = ($data['var_end'] + $newPadding);
                        }

                        $padding      = 1;
                        $assignColumn = ($varEnd + 1);
                    }
                } else if ($padding > $assignments[$maxPadding]['expected']) {
                    $maxPadding = $assign;
                }//end if
            } else {
                $padding      = 1;
                $assignColumn = ($varEnd + 1);
                $maxPadding   = $assign;
            }//end if

            $found = 0;
            if ($tokens[($var + 1)]['code'] === T_WHITESPACE) {
                $found = $tokens[($var + 1)]['length'];
                if ($found === 0) {
                    // This means a newline was found.
                    $found = 1;
                }
            }

            $assignments[$assign] = array(
                                     'var_end'    => $varEnd,
                                     'assign_len' => $assignLen,
                                     'assign_col' => $assignColumn,
                                     'expected'   => $padding,
                                     'found'      => $found,
                                    );

            $lastLine   = $tokens[$assign]['line'];
            $prevAssign = $assign;
        }//end for

        if (empty($assignments) === true) {
            return $stackPtr;
        }

        // If there is at least one assignment that uses more than two spaces then it
        // appears that the assignments should all be aligned right.
        $alignRight = false;
        foreach ($assignments as $assignment => $data) {
            if ($data['found'] > 2) {
                $alignRight = true;
                break;
            }
        }

        $numAssignments = count($assignments);

        $errorGenerated = false;
        foreach ($assignments as $assignment => $data) {
            // Missing space is already covered by
            // Drupal.WhiteSpace.OperatorSpacing.NoSpaceBefore.
            if ($data['found'] === 0) {
                continue;
            }

            if ($alignRight === false) {
                $data['expected'] = 1;
            }

            if ($data['found'] === $data['expected']) {
                continue;
            }

            $expectedText = $data['expected'].' space';
            if ($data['expected'] !== 1) {
                $expectedText .= 's';
            }

            if ($data['found'] === null) {
                $foundText = 'a new line';
            } else {
                $foundText = $data['found'].' space';
                if ($data['found'] !== 1) {
                    $foundText .= 's';
                }
            }

            if ($numAssignments === 1) {
                $type  = 'Incorrect';
                $error = 'Equals sign not aligned correctly; expected %s but found %s';
            } else {
                $type  = 'NotSame';
                $error = 'Equals sign not aligned with surrounding assignments; expected %s but found %s';
            }

            $errorData = array(
                          $expectedText,
                          $foundText,
                         );

            if ($this->error === true) {
                $fix = $phpcsFile->addFixableError($error, $assignment, $type, $errorData);
            } else {
                $fix = $phpcsFile->addFixableWarning($error, $assignment, $type.'Warning', $errorData);
            }

            $errorGenerated = true;

            if ($fix === true && $data['found'] !== null) {
                $newContent = str_repeat(' ', $data['expected']);
                if ($data['found'] === 0) {
                    $phpcsFile->fixer->addContentBefore($assignment, $newContent);
                } else {
                    $phpcsFile->fixer->replaceToken(($assignment - 1), $newContent);
                }
            }
        }//end foreach

        if ($numAssignments > 1) {
            if ($errorGenerated === true) {
                $phpcsFile->recordMetric($stackPtr, 'Adjacent assignments aligned', 'no');
            } else {
                $phpcsFile->recordMetric($stackPtr, 'Adjacent assignments aligned', 'yes');
            }
        }

        if ($stopped !== null) {
            return $this->checkAlignment($phpcsFile, $stopped);
        } else {
            return $assignment;
        }

    }//end checkAlignment()


}//end class

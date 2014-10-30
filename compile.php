<?php
$SHOWERRORS = TRUE;
error_reporting($SHOWERRORS ? E_ALL : 0); ini_set('display_errors', $SHOWERRORS); ini_set('display_startup_errors', $SHOWERRORS);

// Gherkin compiler
//
// Create a skeleton test for each feature in a module

include __DIR__ . '/test-defs.php';

$path = '../' . $_GET['module']; // relative path from compiler to module directory
$GHERKIN_PATH = str_repeat('../', substr_count($path, '/') + 1) . basename(dirname($_SERVER['PHP_SELF']));
$gEOL = '\\\\'; // end of line marker
$argPatterns = '"(.*?)"|([\-\+]?[0-9]+(?:[\.\,\-][0-9]+)*)|(%[a-z][A-Za-z0-9]+)'; // what forms the step arguments can take
$lead = '  '; // line leader (indentation for everything in class definition

$flnms = glob("$path/features");
if (empty($flnms)) error('No features found. The gherkin directory should have the same parent as the module directory.');
if (count($flnms) > 1) error('More than one .module file found in that folder.');

//$MODULE = str_replace('.module', '', basename($flnms[0]));
$MODULE = basename(dirname($flnms[0]));
$testDir = "$path/tests";
if (!file_exists($testDir)) mkdir($testDir);

$infoFilename = "$path/$MODULE.info";
$info = @file_get_contents($infoFilename);

$stepsHeaderFilename = './steps-header.php';
$stepsHeader = file_get_contents($stepsHeaderFilename);

$stepsFilename = "$path/$MODULE.steps";
$stepsText = file_exists($stepsFilename) ? file_get_contents($stepsFilename) : $stepsHeader;

/** $steps array
 *
 * Associative array of step function information, indexed by step function name
 * Each step is in turn an associative array:
 *   'original' is "new", "changed", or the original function header from the steps file
 *   'english' is the english language description of the step
 *   'toReplace' is the text to replace in the steps file when the callers change: from the first caller through the function name
 *   'callers' array is the list of tests that use this specific step function
 *   'arg_count' is the number of arguments (for new steps only)
 */
$steps = getSteps($stepsText); // global

$features = findFiles("$path/features", '/\.feature$/', FALSE);

foreach ($features as $featureFilename) {
  $testFilename = str_replace('features/', 'tests/', str_replace('.feature', '.test', $featureFilename));
  $file_line = str_replace("$path/", '', "files[] = $testFilename\n");
  if ($info) if (strpos($info, $file_line) === FALSE) $info .= $file_line;
  $testData = do_feature($featureFilename, $steps);
  $testData['MODULE'] = $MODULE;
  $test = file_get_contents('test-template.php');
  $test = strtr2($test, $testData);
  file_put_contents($testFilename, $test);
  echo "Created: $testFilename<br>";
}

//print_r($steps); die();
foreach ($steps as $functionName => $step) {
  extract($step); // original, english, toReplace, callers, TMB, functionName
  $newCallers = replacement($callers, $TMB, $functionName); // (replacement includes function's opening parenthesis)
  if ($original == 'new') {
    for ($argList = '', $i = 0; $i < $arg_count; $i++) {
      $argList .= ($argList ? ', ' : '') . '$arg' . ($i + 1);
    }
    $stepsText .= "\n" // do not use <<<EOF here, because it results in extraneous EOLs
    . "/**\n"
    . " * $english\n"
    . " *\n"
    . " * in: {$newCallers}$argList) {\n"
    . "  global \$testOnly;\n"
    . "  todo;\n"
    . "}\n";
  } else $stepsText = str_replace($toReplace, $newCallers, $stepsText);
}

file_put_contents($stepsFilename, $stepsText);
if ($info) file_put_contents($infoFilename, $info);

echo "<br>Updated $stepsFilename<br>Done. " . date('g:ia');
$caller = @$_SERVER['HTTP_REFERER'];
if (@$_GET['return'] and $caller) echo <<<EOF
  <script>
    alert('Press Enter to return to program');
    document.location.href='$caller';
  </script>
EOF;

// END of program

/**
 * Do Feature
 *
 * Get the specific parameters for the feature's tests
 *
 * @param string $featureFilename
 *   feature path and filename relative to module
 *
 * @param array $steps (by reference)
 *   an associative array of empty step function objects, keyed by function name
 *   returned: the original array, with unique new steps added (old steps are not duplicated)
 *
 * @return associative array:
 *   GROUP: sub-project name (currently unused in template)
 *   FEATURE_NAME: titlecase feature name, with no spaces
 *   FEATURE_LONGNAME: feature name in normal english
 *   FEATURE_HEADER: standard Gherkin feature header, formatted as a comment
 *   TESTS: all the tests and steps
 */
function do_feature($featureFilename) {
  global $firstScenarioOnly, $argPatterns, $FEATURE_NAME, $FEATURE_LONGNAME, $GHERKIN_PATH;
  global $steps, $lead, $skipping;     
  $GROUP = basename(dirname(dirname($featureFilename)));
  $FEATURE_NAME = str_replace('.feature', '', basename($featureFilename));
  $FEATURE_LONGNAME = $FEATURE_NAME; // default English description of feature, in case it's missing from feature file
  $FEATURE_HEADER = '';
  $TESTS = '';
  $SETUP_LINES = '';
  $skipping = FALSE;
  
  $lines = explode("\n", file_get_contents($featureFilename));

  // Parse into sections and scenarios
  $section_headers = explode(' ', 'Feature Variants Setup Scenario');
  $sections = $scenarios = array();
  $variantGroups = array();

  while (!is_null($line = array_shift($lines))) {
    if (!($line = trim($line))) continue; // ignore blank lines
    if (substr($line, 0, 1) == '#') continue; // ignore comment lines
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $word1_original = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));

    if ($word1 == 'Skip' or $word1 == 'Resume') {
      $skipping = ($word1 == 'Skip');
    } elseif (@$skipping) {
      continue;
    } elseif (in_array($word1, $section_headers)) {
      $state = $word1;
      switch ($word1) {
        case 'Feature':
          $FEATURE_HEADER .= "//\n// $line\n";
          $FEATURE_LONGNAME = $tail;
          break;
        case 'Scenario': 
          $testFunction = 'test' . (preg_replace("/[^A-Z]/i", '', ucwords($tail))); 
          $scenarios[$testFunction] = array($line);
          break;
        case 'Variants':
          $variantGroups[] = $variant_count = count(@$sections['Variants']);
          if (@$sections['Setup'] and !isset($firstVariantAfterSetup)) $firstVariantAfterSetup = $variant_count;
      }
    } elseif ($state == 'Scenario') {
      $scenarios[$testFunction][] = $line;
    } else $sections[$state][] = $line;
  }
  foreach ($sections['Feature'] as $line) $FEATURE_HEADER .= "//   $line\n"; // parse features

  $variants = parseVariants(@$sections['Variants']); // if empty, return a single line that will get replaced with itself
  if (!isset($firstVariantAfterSetup)) $firstVariantAfterSetup = count($variants); // in case all variants are pre-setup
  if (!@$variantGroups) $variantGroups = array(0);
  $g9 = count($variantGroups);
  $variantGroups[] = count($variants); // point past the end of the last group (for convenience)
//print_r(compact('variants','variantGroups'));
  for ($g = 0; $g < $g9; $g++) { // for each variant group, parse setups and scenarios with all their variants
    $start = $variantGroups[$g]; // pointer to first line of variant group
    $next = $variantGroups[$g + 1]; // pointer past last line of variant group
    $preSetup = ($start < $firstVariantAfterSetup); // whether to make changes to setup steps as well as scenarios
    for ($i = $start + ($start > 0 ? 1 : 0); $i < $next; $i++) { // for each variant in group (do unaltered scenario only once)
      if ($i == 0 or $preSetup) if (@$sections['Setup']) $SETUP_LINES .= doSetups($sections['Setup'], $variants, $start, $i);
      foreach ($scenarios as $testFunction => $lines) $TESTS .= doScenario($testFunction, $lines, $variants, $start, $i);
//    print_r(compact('start','next','preSetup','g','g9','i', 'TESTS'));
    }
  }

  return compact(ray('GHERKIN_PATH,GROUP,FEATURE_NAME,FEATURE_LONGNAME,FEATURE_HEADER,SETUP_LINES,TESTS'));
}

function doSetups($lines, $variants, $start, $i) {
  global $lead;
  adjustLines($lines, $variants, $start, $i); // adjust for current variant
  return ''
    . "{$lead}  case($i):\n"
    . parseScenario('featureSetup', $lines)
    . "{$lead}  break;\n\n";
}

function doScenario($testFunction, $lines, $variants, $start, $i) {
  global $lead;
  $testFunctioni = $testFunction . "_$i";
  adjustLines($lines, $variants, $start, $i); // adjust for current variant
  $line = array_shift($lines); // get the original Scenario line back
  return "\n"
    . "$lead// $line\n"
    . "{$lead}public function $testFunctioni() {\n"
    . "$lead  \$this->setUp2(__FUNCTION__, $i);\n"
    . parseScenario($testFunction, $lines)
    . "$lead}\n"; // close the test function definition
}

function adjustLines(&$lines, $variants, $start, $i) {
  if ($i > $start) foreach ($lines as $key => $line) $lines[$key] = strtr($line, array_combine($variants[$start], $variants[$i]));
}

function parseVariants($lines) {
  if (!$lines) return array(array(1));
  $result = array();
  while (substr(trim(@$lines[0]), 0, 1) == '|') {
    $line = squeeze(preg_replace('/ *\| */', '|', trim(array_shift($lines))), '|');
    $result[] = explode('|', $line);
  }
  return $result;
}

/**
 * Find Files
 *
 * Return an array of files matching the given pattern.
 *
 * @param string $path (optional, defaults to current directory)
 *   the directory to search
 *
 * @param string $pattern (optional, defaults to all files)
 *   return filenames matching this pattern
 *
 * @param array $result (optional)
 *   partial results. if this array is supplied, then recurse subdirectories
 *
 * @return
 *   an array of filenames, qualified by path (including the initial directory $path)
 */
function findFiles($path = '.', $pattern = '/./', $result = '') {
  if (!($recurse = is_array($result))) $result = array();
  if (!is_dir($path)) error('No features folder found for that module.');
  $dir = dir($path);
  
  while ($filename = $dir->read()) {
    if ($filename == '.' or $filename == '..') continue;
    $filename = "$path/$filename";
    if (is_dir($filename) and $recurse) $result = findFiles($filename, $pattern, $result);
    if (preg_match($pattern, $filename)){
      $result[] = $filename;
    }
  }
  return $result;
}

/**
 * Get steps
 *
 * Given the text of the steps file, return an array of steps (see $steps)
 *
 */
function getSteps($stepsText) {
  $stepKeys = ray('original,english,toReplace,callers,functionName');
  $pattern = ''
  . '^/\*\*$\s'
  . '^ \* ([^\*]*?)$\s'
  . '^ \*$\s'
  . '^ \* in: ((.*?)$\s'
  . '^ \*/$\s'
  . '^function (.*?)\()';
  preg_match_all("~$pattern~ms", $stepsText, $matches, PREG_SET_ORDER);
  $steps = array();
  foreach ($matches as $step) {
    $step = array_combine($stepKeys, $step);
//    $step['callers'] = explode("\n *     ", $step['callers']); // add to the list, but don't delete
    $step['callers'] = array(); // rebuild this list every time
    $steps[$step['functionName']] = $step; // use the function name as the index for the step
  }
  return $steps;
}

/**
 * Replacement text
 *
 * When updating an existing step function, replace the header with this.
 * (guaranteed to be unique for each step)
 */
function replacement($callers, $TMB, $functionName) {
  foreach ($callers as $key => $func) $callers[$key] = $TMB[$func] . ' ' . $callers[$key];
  $callers = join("\n *     ", $callers);
  return "$callers\n */\nfunction $functionName(";
}

/**
 * See if the next few lines represent a matrix argument using the following syntax, and handle it:
 *   | a1     | b1     | c1     |
 *   | a2     | b2     | c2     |
 *   | a3     | b3     | c3     |
 * Any one or more spaces next to vertical bars are ignored.
 * If the first line has a star immediately after the final bar, the matrix is treated as an associative array.
 * 
 * @param array $lines: the remaining lines of the feature file
 *                      (RETURNED IMPLICIT) the remaining lines of the feature file, after handling the arg
 * @return the matrix lines (empty if this is not a matrix argument)
 */
function matrixArg(&$lines) {
  global $gEOL;
  
  $type = 'ARRAY';
  $result = '';
  while (substr(trim(@$lines[0]), 0, 1) == '|') {
    $line = trim(array_shift($lines));
    if (!$result and substr($line, -1, 1) == '*') {
      $line = trim(substr($line, 0, strlen($line) - 1));
      $type = 'ASSOC';
    }
    //if (strpos($line, '"') !== FALSE) error('Use two single quotes in a matrix argument, to simulate a double quote.');
    $line = str_replace('"', "''", $line); // this gets changed back in otherFixes() in test-defs.php
    $line = str_replace("'", "\\'", $line);
    $result .= "'\n    . '$gEOL$line";
  }
  return $result ? " \"$type$result\"" : '';
}

function newStepFunction($original, $testFunctionQualified, $english, $isThen, $tail) {
  global $argPatterns;
  $callers = array($testFunctionQualified);
  $TMB = array($testFunctionQualified => ($isThen ? 'TEST' : 'MAKE'));
  preg_match_all("/$argPatterns/ms", $tail, $matches);
//  print_r(compact('argPatterns', 'tail', 'matches')); die();
//  expect(@$matches[1], "Test function \"$testFunctionQualified\" has no args.");
  $arg_count = @$matches[1] ? count($matches[1]) : 0;
  return compact(ray('original,english,callers,TMB,arg_count'));
}

function fixStepFunction($funcArray, $testFunction, $testFunctionQualified, $english, $isThen, $tail, $errArgs) {
  if (!$funcArray) return newStepFunction('new', $testFunctionQualified, $english, $isThen, $tail);
  
  if(($old_english = @$funcArray['english']) != $english) error(
    "<br>WARNING: You tried to redefine step function \"!stepFunction\". "
    . "Delete the old one first.<br>\n"
    . "Old: $old_english<br>\n"
    . "New: $english<br>\n"
    . "  in Feature: !FEATURE_LONGNAME<br>\n"
    . "  in Scenario: $testFunction<br>\n"
    . "  in Step: !line<br>\n", 
    $errArgs
  );
  
  if ($funcArray['original'] != 'new') $funcArray['original'] = 'changed';
  if (!in_array($testFunctionQualified, $funcArray['callers'])) {
    $funcArray['callers'][] = $testFunctionQualified;
    $funcArray['TMB'][$testFunctionQualified] = ($isThen ? 'TEST' : 'MAKE');
  } else {
    $TMB_changes = $isThen ? array('MAKE' => 'BOTH') : array('TEST' => 'BOTH');
    //print_r(compact('TMB_changes','isThen','testFunctionQualified') + array('zot'=>$funcArray['TMB'][$testFunctionQualified]));
    $funcArray['TMB'][$testFunctionQualified] = strtr($funcArray['TMB'][$testFunctionQualified], $TMB_changes);
    //print_r(compact('TMB_changes','isThen','testFunctionQualified') + array('zot'=>$funcArray['TMB'][$testFunctionQualified])); die();
  }
  return $funcArray;
}

function ray($s) {return explode(',', $s);}

function strtr2($string, $subs, $prefix = '%') {
  foreach($subs as $from => $to) $string = str_replace("$prefix$from", $to, $string);
  return $string;
}

function error($message, $subs = array()) {die(strtr2("\n\nERROR: $message.", $subs, '!') . ' See the file "howto.txt".');}

function expect($bool, $message) {
  global $FEATURE_NAME;
  if(!$bool) error(@$FEATURE_NAME . ": $message");
}

function parseScenario($testFunction, $lines) { 
  global $argPatterns, $FEATURE_NAME, $FEATURE_LONGNAME, $steps, $lead, $skipping;
  $result = $state = '';

  while (!is_null($line = array_shift($lines))) {
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));
    if (in_array($word1, array('Given', 'When', 'Then', 'And'))) {
      $isThen = ($word1 == 'Then' or ($word1 == 'And' and $state == 'Then'));
      if ($word1 == 'And') $word1 = 'And__'; else $state = $word1;
      if ($word1 == 'When' or $word1 == 'Then') $word1 .= '_';
      $matrixArg = matrixArg($lines);
      $tailEscaped = str_replace("'", "\\'", $tail) . $matrixArg;
      $tail .= str_replace("\\'", "'", $matrixArg);
//      print_r(compact('matrixArg','tailEscaped','tail'));
      $result .= "$lead  $word1('$tailEscaped');\n";
      $english = preg_replace("/$argPatterns/ms", '(ARG)', $tail);
      $stepFunction = lcfirst(preg_replace("/$argPatterns|[^A-Z]/msi", '', ucwords($tail)));

      $testFunctionQualified = str_replace('- test', '', str_replace('- feature', '', "$FEATURE_NAME - $testFunction"));
      $errArgs = compact(ray('stepFunction,FEATURE_LONGNAME,line')); // for error reporting, just in case 
      $steps[$stepFunction] = fixStepFunction(@$steps[$stepFunction], $testFunction, $testFunctionQualified, $english, $isThen, $tail, $errArgs);
    } elseif ($word1 == 'Skip' or $word1 == 'Resume') {
      $skipping = ($word1 == 'Skip'); // might call And skip 
    }
  }
 
  return $result;
}
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
$arg_patterns = '"(.*?)"|([\-\+]?[0-9]+(?:[\.\,\-][0-9]+)*)|(%[a-z][A-Za-z0-9]+)'; // what forms the step arguments can take
$lead = '  '; // line leader (indentation for everything in class definition

$flnms = glob("$path/features");
if (empty($flnms)) error('No features found. The gherkin directory should have the same parent as the module directory.');
if (count($flnms) > 1) error('More than one .module file found in that folder.');

//$MODULE = str_replace('.module', '', basename($flnms[0]));
$MODULE = basename(dirname($flnms[0]));
$test_dir = "$path/tests";
if (!file_exists($test_dir)) mkdir($test_dir);

$info_filename = "$path/$MODULE.info";
$info = @file_get_contents($info_filename);

$steps_header_filename = './steps-header.php';
$steps_header = file_get_contents($steps_header_filename);

$steps_filename = "$path/$MODULE.steps";
$steps_text = file_exists($steps_filename) ? file_get_contents($steps_filename) : $steps_header;

/** $steps array
 *
 * Associative array of step function information, indexed by step function name
 * Each step is in turn an associative array:
 *   'original' is "new", "changed", or the original function header from the steps file
 *   'english' is the english language description of the step
 *   'to_replace' is the text to replace in the steps file when the callers change: from the first caller through the function name
 *   'callers' array is the list of tests that use this specific step function
 *   'arg_count' is the number of arguments (for new steps only)
 */
$steps = get_steps($steps_text); // global

$features = findFiles("$path/features", '/\.feature$/', FALSE);

foreach ($features as $feature_filename) {
  $test_filename = str_replace('features/', 'tests/', str_replace('.feature', '.test', $feature_filename));
  $file_line = str_replace("$path/", '', "files[] = $test_filename\n");
  if ($info) if (strpos($info, $file_line) === FALSE) $info .= $file_line;
  $test_data = do_feature($feature_filename, $steps);
  $test_data['MODULE'] = $MODULE;
  $test = file_get_contents('test-template.php');
  $test = strtr2($test, $test_data);
  file_put_contents($test_filename, $test);
  echo "Created: $test_filename<br>";
}

foreach ($steps as $function_name => $step) {
  extract($step); // original, english, to_replace, callers, TMB, function_name
  $new_callers = replacement($callers, $TMB, $function_name); // (replacement includes function's opening parenthesis)
  if ($original == 'new') {
    for ($arg_list = '', $i = 0; $i < $arg_count; $i++) {
      $arg_list .= ($arg_list ? ', ' : '') . '$arg' . ($i + 1);
    }
    $steps_text .= "\n" // do not use <<<EOF here, because it results in extraneous EOLs
    . "/**\n"
    . " * $english\n"
    . " *\n"
    . " * in: {$new_callers}$arg_list) {\n"
    . "  global \$testOnly;\n"
    . "  todo;\n"
    . "}\n";
  } else $steps_text = str_replace($to_replace, $new_callers, $steps_text);
}

file_put_contents($steps_filename, $steps_text);
if ($info) file_put_contents($info_filename, $info);

echo "<br>Updated $steps_filename<br>Done. " . date('g:ia');
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
 * @param string $feature_filename
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
function do_feature($feature_filename) {
  global $first_scenario_only, $arg_patterns, $FEATURE_NAME, $FEATURE_LONGNAME, $GHERKIN_PATH;
  global $steps, $lead, $TESTS, $SETUP_LINES;     
  $GROUP = basename(dirname(dirname($feature_filename)));
  $FEATURE_NAME = str_replace('.feature', '', basename($feature_filename));
  $FEATURE_LONGNAME = $FEATURE_NAME; // default English description of feature, in case it's missing from feature file
  $FEATURE_HEADER = '';
  $TESTS = '';
  $SETUP_LINES = '';
 
  $lines = explode("\n", file_get_contents($feature_filename));

  // Parse into sections and scenarios
  $section_headers = explode(' ', 'Feature Variants Setup Scenario');
  $sections = $scenarios = array();

  while (!is_null($line = array_shift($lines))) {
    $line = trim($line);
    if (substr($line, 0, 1) == '#') continue; // ignore comment lines
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $word1_original = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));

    if(in_array($word1, $section_headers)) {
      $state = $word1;
      switch ($word1) {
        case 'Feature':
          $FEATURE_HEADER .= "//\n// $line\n";
          $FEATURE_LONGNAME = $tail;
          break;
        case 'Scenario': 
          $test_function = 'test' . (preg_replace("/[^A-Z]/i", '', ucwords($tail))); 
          $scenarios[$test_function] = array($line);
          break;
        case 'Variants':
          if (!@$sections['Setup']) $state = 'preVariants';
      }
    } elseif ($state == 'Scenario') {
      $scenarios[$test_function][] = $line;
    } else $sections[$state][] = $line;
  }

  // Parse each section
  foreach ($sections['Feature'] as $line) $FEATURE_HEADER .= "//   $line\n";

  $prevariants = parseVariants(@$sections['preVariants']);
  $prevariant_count = count($prevariants);
  for ($i = 0; $i < $prevariant_count; $i++) {
    $SETUP_LINES .= doSetups($sections['Setup'], $prevariants, $i);
    foreach ($scenarios as $test_function => $lines) $TESTS .= doScenario($test_function, $lines, $prevariants, $i);
  }

  $variants = parseVariants(@$sections['Variants']); // if empty, return a single line that will get replaced with itself
  if ($prevariant_count > 1) {
    $filler = array_fill(1, $prevariant_count - 1, '');
    $variants = array_splice($variants, 1, 0, $filler); // bump the relevant variation numbers so as not to repeat
  }
  for ($i = ($prevariant_count ?: 0); $i < count($variants); $i++) { // don't redo variant 0, skip the filler
    foreach ($scenarios as $test_function => $lines) $TESTS .= doScenario($test_function, $lines, $variants, $i);
  }

  return compact(ray('GHERKIN_PATH,GROUP,FEATURE_NAME,FEATURE_LONGNAME,FEATURE_HEADER,SETUP_LINES,TESTS'));
}

function doSetups($lines, $prevariants, $i) {
  global $lead;
  fixLines($lines, $prevariants, $i); // adjust for current variant
  return ''
    . "{$lead}  case($i):\n"
    . parseScenario('featureSetup', $lines)
    . "{$lead}  break;\n\n";
}

function doScenario($test_function, $lines, $variants, $i) {
  global $lead;
  $test_functioni = $test_function . $i;
  fixLines($lines, $variants, $i); // adjust for current variant
  $line = array_shift($lines); // get the original Scenario line back
  return "\n"
    . "$lead// $line\n"
    . "{$lead}public function $test_functioni() {\n"
    . "$lead  sceneSetup(\$this, __FUNCTION__, $i);\n"
    . parseScenario($test_function, $lines)
    . "$lead}\n"; // close the test function definition
}

function fixLines(&$lines, $variants, $i) {
  if ($i) foreach ($lines as $key => $line) $lines[$key] = strtr($line, array_combine($variants[0], $variants[$i]));
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
function get_steps($steps_text) {
  $step_keys = ray('original,english,to_replace,callers,function_name');
  $pattern = ''
  . '^/\*\*$\s'
  . '^ \* ([^\*]*?)$\s'
  . '^ \*$\s'
  . '^ \* in: ((.*?)$\s'
  . '^ \*/$\s'
  . '^function (.*?)\()';
  preg_match_all("~$pattern~ms", $steps_text, $matches, PREG_SET_ORDER);
  $steps = array();
  foreach ($matches as $step) {
    $step = array_combine($step_keys, $step);
//    $step['callers'] = explode("\n *     ", $step['callers']); // add to the list, but don't delete
    $step['callers'] = array(); // rebuild this list every time
    $steps[$step['function_name']] = $step; // use the function name as the index for the step
  }
  return $steps;
}

/**
 * Replacement text
 *
 * When updating an existing step function, replace the header with this.
 * (guaranteed to be unique for each step)
 */
function replacement($callers, $TMB, $function_name) {
  foreach ($callers as $key => $func) $callers[$key] .= ' ' . $TMB[$func];
  $callers = join("\n *     ", $callers);
  return "$callers\n */\nfunction $function_name(";
}

/**
 * Multiline Argument
 *
 * See if the next few lines represent data records using the syntax:
 *   | field1 | field2 | field3 |
 *   | a1     | b1     | c1     |
 *   | a2     | b2     | c2     |
 * 
 * @param array $lines: the remaining lines of the feature file
 *
 * @return
 *   the additional lines, if any, to add to the first
 *   $lines (implicit) the remaing lines of the feature file
 */
function multiline_arg(&$lines) {
  global $gEOL;
  $result = '';
  while (substr(trim(@$lines[0]), 0, 1) == '|') {
    $line = str_replace("'", "\\'", trim(array_shift($lines)));
    $result .= "'\n    . '$gEOL$line";
  }
  return $result ? " \"DATA$result\"" : '';
}

function new_step_function($original, $test_function_qualified, $english, $is_then, $tail) {
  global $arg_patterns;
  $callers = array($test_function_qualified);
  $TMB = array($test_function_qualified => ($is_then ? 'TEST' : 'MAKE'));
  preg_match_all("/$arg_patterns/ms", $tail, $matches);
//  print_r(compact('arg_patterns', 'tail', 'matches')); die();
//  expect(@$matches[1], "Test function \"$test_function_qualified\" has no args.");
  $arg_count = @$matches[1] ? count($matches[1]) : 0;
  return compact(ray('original,english,callers,TMB,arg_count'));
}

function fix_step_function($func_array, $test_function, $test_function_qualified, $english, $is_then, $tail, $err_args) {
  if (!$func_array) return new_step_function('new', $test_function_qualified, $english, $is_then, $tail);
  
  if(($old_english = @$func_array['english']) != $english) error(
    "<br>WARNING: You tried to redefine step function \"!step_function\". "
    . "Delete the old one first.<br>\n"
    . "Old: $old_english<br>\n"
    . "New: $english<br>\n"
    . "  in Feature: !FEATURE_LONGNAME<br>\n"
    . "  in Scenario: $test_function<br>\n"
    . "  in Step: !line<br>\n", 
    $err_args
  );
  
  if ($func_array['original'] != 'new') $func_array['original'] = 'changed';
  if (!in_array($test_function_qualified, $func_array['callers'])) {
    $func_array['callers'][] = $test_function_qualified;
    $func_array['TMB'][$test_function_qualified] = ($is_then ? 'TEST' : 'MAKE');
  } else {
    $TMB_changes = $is_then ? array('MAKE' => 'BOTH') : array('TEST' => 'BOTH');
    //print_r(compact('TMB_changes','is_then','test_function_qualified') + array('zot'=>$func_array['TMB'][$test_function_qualified]));
    $func_array['TMB'][$test_function_qualified] = strtr($func_array['TMB'][$test_function_qualified], $TMB_changes);
    //print_r(compact('TMB_changes','is_then','test_function_qualified') + array('zot'=>$func_array['TMB'][$test_function_qualified])); die();
  }
  return $func_array;
}

function ray($s) {return explode(',', $s);}

function strtr2($string, $subs, $prefix = '%') {
  foreach($subs as $from => $to) $string = str_replace("$prefix$from", $to, $string);
  return $string;
}

function error($message, $subs = array()) {die(strtr2("\n\n$message.", $subs, '!') . ' See the file "howto.txt".');}

function expect($bool, $message) {
  global $FEATURE_NAME;
  if(!$bool) error(@$FEATURE_NAME . ": $message");
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

function parseScenario($test_function, $lines) { 
  global $arg_patterns, $FEATURE_NAME, $FEATURE_LONGNAME, $steps, $lead;     

  $result = $state = '';

  while (!is_null($line = array_shift($lines))) {
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));

    if (in_array($word1, array('Given', 'When', 'Then', 'And'))) {
      $is_then = ($word1 == 'Then' or ($word1 == 'And' and $state == 'Then'));
      if ($word1 == 'When' or $word1 == 'Then') $word1 .= '_';
      if ($word1 == 'And') $word1 = 'And__'; else $state = $word1;
      $multiline_arg = multiline_arg($lines);
      $tail_escaped = str_replace("'", "\\'", $tail) . $multiline_arg;
      $tail .= str_replace("\\'", "'", $multiline_arg);
//      print_r(compact('multiline_arg','tail_escaped','tail'));
      $result .= "$lead  $word1('$tail_escaped');\n";
      $english = preg_replace("/$arg_patterns/ms", '(ARG)', $tail);
      $step_function = lcfirst(preg_replace("/$arg_patterns|[^A-Z]/msi", '', ucwords($tail)));

      $test_function_qualified = "$FEATURE_NAME - $test_function";
      $err_args = compact(ray('step_function,FEATURE_LONGNAME,line')); // for error reporting, just in case 
      $steps[$step_function] = fix_step_function(@$steps[$step_function], $test_function, $test_function_qualified, $english, $is_then, $tail, $err_args);
    }
  }
 
  return $result;
}
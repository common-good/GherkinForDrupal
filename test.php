<?php
//http://localhost/devcore/rcredits/util/test?module=rcredits/rsms&menu=1
use rCredits\Util as u;
global $okALL, $noALL; $okALL = $noALL = 0; // overall results counters
global $the_menu, $the_feature, $the_scene, $the_variant; // these allow for arbitrarily selective testing
global $programPath; $programPath = $_SERVER['REDIRECT_URL'];
define('TESTING', TRUE); // use this to activate extra debugging statements (if (defined('TESTING')))

$args = array();
foreach (explode('&', $_SERVER['QUERY_STRING']) as $one) { // gotta do it the long way, because Drupal suppresses $_POST
  list ($key, $value) = explode('=', $one);
  $args[$key] = $value;
}
extract(u\just('menu module feature scene variant', $args), EXTR_PREFIX_ALL, 'the');
if (@$the_scene) $the_variant = 0;
$modules = @$the_module ? array($the_module) : array('rcredits/rsms', 'rcredits/rsmart', 'rcredits/rweb'); // and admin
foreach($modules as $module) doModule($module);
if (!@$the_menu) if (count($modules) > 1) report('OVERALL', $okALL, $noALL);
// END OF PROGRAM

// SMS: OpenAnAccountForTheCaller AbbreviationsWork ExchangeForCash GetHelp GetInformation Transact Undo OfferToExchangeUSDollarsForRCredits
// Smart: Startup IdentifyQR Transact UndoCompleted UndoPending UndoAttack Insufficient Change
// Web: Signup

//  $features = array('UndoPending'); // uncomment to run just one feature (test set)
//  $the_scene = 'testTheCallerAsksToPayAMemberId'; // uncomment to run just one test scenario
//  $the_variant = 0; // uncomment to focus on a single variant (usually 0)

/**
 * Run tests for one module
 */
function doModule($module) {
  global $ok, $no, $fails, $okALL, $noALL, $the_feature, $the_menu, $programPath;
  $fails = $ok = $no = 0; // results counters

  $moduleName = strtoupper(basename($module));
  $path = __DIR__ . "/../$module"; // relative path from test program to module directory
  $features = str_replace("$path/features/", '', str_replace('.feature', '', findFiles("$path/features", '/.*\.feature/')));
  if (@$the_feature) $features = array($the_feature);
  $link = testLink('ALL', $module);
  
  if (@$the_menu) { // just show the choices
    $menu = array("<h1>$moduleName: $link</h1>");
    foreach ($features as $feature) $menu[] = testLink($feature, $module, $feature);
    insertMessage(join('<br>', $menu) . '<br>&nbsp;');
  } else {
    foreach ($features as $feature) dotest($module, $feature);
    report($moduleName, $ok, $no, $module);
  }
}  

function dotest($module, $feature) {
  global $results, $summary, $user, $the_scene, $the_variant;
  include ($feature_filename = __DIR__ . "/../$module/tests/$feature.test");

  $featureLink = testLink($feature, $module, $feature);
  $temp_user = $user; $user = array();
  $classname = basename($module . $feature);
  $t = new $classname();
  $s = file_get_contents($feature_filename);
  preg_match_all('/function (test.*?)\(/sm', $s, $matches);

  foreach ($matches[1] as $one) {
    list ($scene, $variant) = explode('_', $one);
    if (@$the_scene) if ($scene != $the_scene) continue;
    if (isset($the_variant)) if ($variant != $the_variant) continue;

    $results = array('PASS!');
    $t->setUp();
    $t->$one(); // run one test
    
    // Display results intermixed with debugging output, if any (so don't collect results before displaying)
    $one = testLink($one, $module, $feature, $scene);
    $results[0] .= " [$featureLink] $one";
    $results[0] = color($results[0], 'darkkhaki');
    \drupal_set_message(join(PHP_EOL, $results));
  }
  $user = $temp_user;
}

/**
 * Find Files (copied from gherkin)
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
  if (!is_dir($path)) die("No features folder found for that module (path $path).");
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

class DrupalWebTestCase {
  function setUp() {}
  function assertTrue($bool) {
    global $results, $summary;
    global $ok, $no, $fails, $okALL, $noALL;
    $trace = debug_backtrace();
    list ($zot, $step, $feature) = $trace[0]['args'];
    $step = str_replace('\\', "\n     ", $step);
    $step = str_replace("''", '"', $step);
    $where = $feature == 'Setup' ? "[$feature] " : '';
    list ($result, $color) = $bool ? array('OK', 'lightgreen') : array('NO', 'yellow');
    $results[] = $result = color("$result: $where$step", $color);
    if ($bool) {
      $ok++; $okALL++;
    } else {
      $no++; $noALL++;
      if (!strpos($results[0], 'FAIL')) {
        $fails++;
        $results[0] = gotoError('FAIL', $fails);
      }
    }
  }
}

function color($msg, $color) {
  return "<pre style='background-color:$color;'>$msg</pre>";
}

function insertMessage($s, $type = 'status') {
  if (!@$_SESSION['messages'][$type]) $_SESSION['messages'][$type] = array();
  array_unshift($_SESSION['messages'][$type], $s);
}

function report($moduleName, $ok, $no, $module = '') {
  $moduleName = testLink($moduleName, $module);
  if (!$no) $no = '_'; else $no = gotoError($no);
  $msg = <<<EOF
  <h1>
  $moduleName - 
  ok: <span style='color:lightgreen; font-size:300%;'>$ok</span> 
  no: <span style='color:red; font-size:300%;'>$no</span>
  </h1>
EOF;
  insertMessage($msg);
}

function testLink($description, $module, $feature = '', $scene = '') {
  global $programPath;
  return "<a href='$programPath?module=$module&feature=$feature&scene=$scene'>$description</a>";
}

function gotoError($title, $errorNum = 0) {
  $next = $errorNum + 1;
  $link = "javascript:document.getElementById('testError$next').scrollIntoView(true); window.scrollBy(0, -50);";
  return "<a id='testError$errorNum' href=\"$link\">$title</a>";
}
